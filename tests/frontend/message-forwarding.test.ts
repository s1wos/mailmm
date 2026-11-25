import assert from 'node:assert/strict'
import { test } from 'node:test'
import { setImmediate as flush } from 'node:timers/promises'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { Ref } from 'vue'
import { loadComponent, mountSsrComponent } from './vueFixture.ts'

const page = { userId: 17, messageId: 42, initialForwards: [], availability: { available: true, reason: null, content: 'original' } }
const operation = { id: 'f1', mode: 'manual', status: 'queued', destination: 'reader@example.test',
    request_id: 'first', error_code: null, created_at: '2026-11-24T12:00:00Z' }

async function mount(pageProps: object) {
    const component = await loadComponent('/resources/js/panel/MessageForwarding.vue')
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/admin/messages/:id', component }] })
    await router.push('/admin/messages/42')
    return { router, ...mountSsrComponent<{ to: Ref<string>, open: () => Promise<void>, send: () => Promise<void> }>(component, { ...pageProps }, router) }
}

test('following the public action on an already open card expands the recipient field at the hash target', async t => {
    const card = await mount(page)
    t.after(card.unmount)
    assert.doesNotMatch(await card.html(), /name="to"/)
    await card.router.push('/admin/messages/42#forward')
    await flush()
    const html = await card.html()
    assert.match(html, /<input\b(?=[^>]*id="forward")(?=[^>]*name="to")(?=[^>]*inputmode="email")[^>]*>/)
    assert.match(html, /«От кого» получатель увидит адрес исходного отправителя/)
    assert.match(html, /Если оригинал недоступен, копия придёт с адреса MailMM/)
})

test('the archive form describes the stored copy and accepts the chosen recipient', async t => {
    const card = await mount({ ...page, availability: { available: true, reason: null, content: 'archive' } })
    t.after(card.unmount)
    await card.state.open()
    const html = await card.html()
    assert.match(html, /с адреса MailMM/)
    assert.match(html, /ответить автору/i)
    assert.match(html, /текст письма и сохранённые вложения/i)
    assert.doesNotMatch(html, /исходным оформлением|встроенными изображениями/)
    t.mock.method(globalThis, 'fetch', async (_path: string, options: RequestInit) => {
        const { to, request_id } = JSON.parse(String(options.body))
        assert.equal(to, operation.destination)
        return Response.json({ forward: { ...operation, request_id, status: 'accepted' } }, { status: 202 })
    })
    card.state.to.value = operation.destination
    await card.state.send()
    assert.match(await card.html(), /Передано почтовому серверу/)
})

test('a lost POST response keeps its UUID across a reload and only the initiating account can resume it', async t => {
    const records = new Map<string, string>()
    const storage = { getItem: (k: string) => records.get(k) ?? null, setItem: (k: string, v: string) => records.set(k, v),
        removeItem: (k: string) => records.delete(k) }
    Object.defineProperty(globalThis, 'window', { value: Object.assign(new EventTarget(), { sessionStorage: storage }), configurable: true })
    t.after(() => { Reflect.deleteProperty(globalThis, 'window') })
    const sent: { to: string, request_id: string }[] = []
    t.mock.method(globalThis, 'fetch', async (path: string, options: RequestInit) => {
        if (path === '/admin/messages/42/forwards?ids%5B%5D=f1') {
            return Response.json({ forwards: [{ ...operation, request_id: sent[0].request_id }] })
        }
        assert.equal(path, '/admin/messages/42/forwards')
        assert.equal(options.method, 'POST')
        const body = JSON.parse(String(options.body))
        sent.push(body)
        if (sent.length === 1) throw new TypeError('connection lost')
        return Response.json({ forward: { ...operation, request_id: body.request_id } }, { status: 202 })
    })
    const first = await mount(page)
    t.after(first.unmount)
    await first.state.open()
    first.state.to.value = operation.destination
    await Promise.all([first.state.send(), first.state.send()])
    assert.equal(sent.length, 1)
    assert.match(sent[0].request_id, /^[a-f0-9-]{36}$/)
    assert.match(await first.html(), /Повторить запрос/)
    first.unmount()

    const other = await mount({ ...page, userId: 18 })
    assert.doesNotMatch(await other.html(), /reader@example\.test|Повторить запрос/)
    other.unmount()
    const recovered = await mount(page)
    t.after(recovered.unmount)
    assert.match(await recovered.html(), /reader@example\.test/)
    await recovered.state.send()
    assert.deepEqual(sent[1], sent[0])
    assert.equal(records.size, 0)
    assert.match(await recovered.html(), /В очереди/)
})

test('a new forward checks status immediately and returns to normal polling after a short window', async t => {
    t.mock.timers.enable({ apis: ['Date', 'setTimeout'] })
    const requests: string[] = []
    t.mock.method(globalThis, 'fetch', async (path: string, options: RequestInit) => {
        if (options.method === 'POST') return Response.json({ forward: operation }, { status: 202 })
        requests.push(path)
        return Response.json({ forwards: [{ ...operation, status: requests.length === 6 ? 'accepted' : 'preparing' }] })
    })
    const card = await mount(page)
    t.after(card.unmount)
    card.state.to.value = operation.destination
    await card.state.send()
    await flush()
    assert.deepEqual(requests, ['/admin/messages/42/forwards?ids%5B%5D=f1'])
    assert.match(await card.html(), /Подготовка письма/)

    for (let i = 0; i < 4; i++) {
        t.mock.timers.tick(500)
        await flush()
    }
    assert.equal(requests.length, 5)

    t.mock.timers.tick(4_999)
    await flush()
    assert.equal(requests.length, 5)
    t.mock.timers.tick(1)
    await flush()
    assert.equal(requests.length, 6)
    assert.match(await card.html(), /Передано почтовому серверу/)
})

test('one poll refreshes several pending operations without retrying a disappeared operation', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] })
    const requests: string[] = []
    const missing = { ...operation, id: 'f2', destination: 'missing@example.test', status: 'preparing' }
    const automatic = { ...operation, id: 'f3', mode: 'auto', status: 'submitting' }
    const terminal = { ...operation, id: 'f4', status: 'failed' }
    t.mock.method(globalThis, 'fetch', async (path: string, options: RequestInit) => {
        requests.push(path)
        assert.equal(new Headers(options.headers).get('X-Panel-User'), '17')
        return Response.json({ forwards: [{ ...operation, status: 'accepted' }, { ...automatic, status: 'cancelled' }] })
    })
    const card = await mount({ ...page, initialForwards: [operation, missing, automatic, terminal] })
    t.after(card.unmount)
    t.mock.timers.tick(10_000)
    await flush()
    assert.deepEqual(requests, ['/admin/messages/42/forwards?ids%5B%5D=f1&ids%5B%5D=f2&ids%5B%5D=f3'])
    const html = await card.html()
    assert.match(html, /Передано почтовому серверу/)
    assert.match(html, /Отменено/)
    assert.match(html, /Ошибка отправки/)
    assert.doesNotMatch(html, /missing@example\.test|Не удалось обновить статус/)
    t.mock.timers.tick(10_000)
    await flush()
    assert.equal(requests.length, 1)
})
