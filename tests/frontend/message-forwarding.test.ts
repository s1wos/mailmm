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
})

test('the archive form describes the stored copy and accepts the chosen recipient', async t => {
    const card = await mount({ ...page, availability: { available: true, reason: null, content: 'archive' } })
    t.after(card.unmount)
    await card.state.open()
    const html = await card.html()
    assert.match(html, /«От кого» получатель увидит адрес исходного отправителя/)
    assert.match(html, /Если его адрес не сохранился, отправителем будет указан MailMM/)
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

test('polling a saved pending operation identifies the viewer and stops after acceptance', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] })
    const requests: string[] = []
    t.mock.method(globalThis, 'fetch', async (path: string, options: RequestInit) => {
        requests.push(path)
        assert.equal(new Headers(options.headers).get('X-Panel-User'), '17')
        return Response.json({ forward: { ...operation, status: 'accepted' } })
    })
    const card = await mount({ ...page, initialForwards: [operation] })
    t.after(card.unmount)
    t.mock.timers.tick(10_000)
    await flush()
    assert.deepEqual(requests, ['/admin/messages/42/forwards/f1'])
    assert.match(await card.html(), /Передано почтовому серверу/)
    t.mock.timers.tick(10_000)
    await flush()
    assert.equal(requests.length, 1)
})
