import assert from 'node:assert/strict'
import { test } from 'node:test'
import vue from '@vitejs/plugin-vue'
import { createServer } from 'vite'
import { createSSRApp, effectScope } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createMemoryHistory, createRouter } from 'vue-router'
import { usePanelForm } from '../../resources/js/panel/usePanelForm.ts'
import { panelRequest, setPanelUser } from '../../resources/js/panel/http.ts'

test('a mutation identifies the account that opened its form and checks the session on conflict', async t => {
    const events = new EventTarget()
    Object.defineProperty(globalThis, 'window', { value: events, configurable: true })
    t.after(() => { Reflect.deleteProperty(globalThis, 'window'); setPanelUser(null) })
    let checks = 0
    events.addEventListener('panel-check-session', () => { checks++ })
    t.mock.method(globalThis, 'fetch', (_path: string, options: RequestInit) => {
        assert.equal(new Headers(options.headers).get('X-Panel-User'), '17')
        return Response.json({ code: 'panel_user_changed' }, { status: 409 })
    })
    setPanelUser(17)
    await assert.rejects(() => panelRequest('/admin/logout', { method: 'POST' }), { status: 409 })
    assert.equal(checks, 1)
})

class BrowserFormData extends FormData {
    constructor(form: { entries: string[][] }) {
        super()
        for (const [key, value] of form.entries) this.append(key, value)
    }
}

function submission(path: string, entries: string[][] = []) {
    return { currentTarget: { getAttribute: () => path, entries } } as unknown as SubmitEvent
}

test('login preserves input on 422 and sends one corrected request for the intended page', async t => {
    const scope = effectScope()
    t.after(() => scope.stop())
    const form = scope.run(usePanelForm)!
    t.mock.method(globalThis, 'FormData', BrowserFormData)
    const requests: { path: string, init: RequestInit }[] = []
    const success = Promise.withResolvers<Response>()
    t.mock.method(globalThis, 'fetch', async (path: string, init: RequestInit) => {
        requests.push({ path, init })
        if (requests.length === 1) {
            return Response.json({ errors: { email: ['Проверьте логин и пароль.'] } }, { status: 422 })
        }
        return success.promise
    })
    const fields = [['email', 'owner@mail.test'], ['password', 'old']]
    const event = submission('/admin/login', fields)
    assert.equal(await form.submit(event), undefined)
    assert.deepEqual(form.state.fields, { email: ['Проверьте логин и пароль.'] })
    assert.deepEqual(fields, [['email', 'owner@mail.test'], ['password', 'old']])
    const duplicate = form.submit(event)
    assert.equal(requests.length, 1, 'a second activation with unchanged credentials must not spend another login attempt')
    assert.equal(await duplicate, undefined)

    fields[1][1] = 'corrected'
    form.edited()
    const attempt = form.submit(event)
    assert.equal(form.state.pending, true)
    assert.equal(await form.submit(event), undefined)
    assert.equal(requests.length, 2)
    const { path, init } = requests[1]
    assert.equal(path, '/admin/login')
    assert.equal(init.method, 'POST')
    assert.equal(new Headers(init.headers).get('Content-Type'), 'application/json')
    assert.deepEqual(JSON.parse(String(init.body)), { email: 'owner@mail.test', password: 'corrected' })
    success.resolve(Response.json({ redirect: '/admin/messages/123' }))
    assert.deepEqual(await attempt, { redirect: '/admin/messages/123' })
    assert.equal(form.state.pending, false)
    assert.deepEqual(form.state.fields, {})
})

test('a domain change reports its own error and an unmounted form ignores a late success', async t => {
    const scope = effectScope()
    t.after(() => scope.stop())
    const form = scope.run(usePanelForm)!
    t.mock.method(globalThis, 'FormData', BrowserFormData)
    const late = Promise.withResolvers<Response>()
    let signal: AbortSignal | null | undefined
    const domainPath = '/admin/settings/domains/2'
    t.mock.method(globalThis, 'fetch', async (path: string, init: RequestInit) => {
        if (path === domainPath) {
            assert.equal(init.method, 'PATCH')
            assert.deepEqual(JSON.parse(String(init.body)), { issuance_enabled: '1' })
            return new Response(null, { status: 404 })
        }
        signal = init.signal
        return late.promise
    })
    await form.submit(submission(domainPath, [['issuance_enabled', '1']]), 'PATCH')
    assert.ok(form.messageFor(domainPath))
    assert.equal(form.messageFor('/admin/settings/domains/1'), '')
    const saving = form.submit(submission('/admin/mailboxes'))
    scope.stop()
    assert.equal(signal?.aborted, true)
    late.resolve(Response.json({ redirect: '/admin/mailboxes?issued=3&ticket=one-use' }))
    assert.equal(await saving, undefined)
})

test('the mailbox screen shows issued credentials only in the supplied page and applies the selected domain limits', async () => {
    const vite = await createServer({
        configFile: false,
        plugins: [vue()],
        server: { middlewareMode: true, ws: false, watch: null },
    })
    try {
        const Mailboxes = (await vite.ssrLoadModule('/resources/js/panel/Mailboxes.vue')).default
        const router = createRouter({
            history: createMemoryHistory(),
            routes: [{ path: '/:path(.*)', component: Mailboxes }],
        })
        await router.push('/admin?issued=12&ticket=one-use')
        const credentials = { email: 'owner@example.test', password: 'one-use-fixture' }
        const page = {
            screen: 'mailboxes',
            request_id: '9a42feeb-18c8-4251-afc6-02f411f2970a',
            user: { id: 1, is_admin: true, email: 'admin@example.test' },
            domains: [
                { domain: 'short.test', limit: 4, formats: ['random'] },
                { domain: 'words.test', limit: 64, formats: ['random', 'words'] },
            ],
            mailboxes: { data: [], current_page: 1, last_page: 2, total: 13, prev_page_url: null, next_page_url: '/admin/mailboxes?page=2' },
        }
        const issued = await renderToString(createSSRApp(Mailboxes, { page: { ...page, credentials } }).use(router))
        assert.match(issued, /Пароль показывается один раз/)
        assert.ok(issued.includes(credentials.password))
        assert.ok(issued.includes('name="request_id" value="' + page.request_id + '"'))
        assert.match(issued, /href="\/admin\/mailboxes\?page=2"/)
        assert.match(issued, /name="local_part"[^>]*maxlength="4"/)
        assert.match(issued, /<option value="words" disabled/)
        const revisited = await renderToString(createSSRApp(Mailboxes, {
            page: { ...page, credentials: null, domains: [] },
        }).use(router))
        assert.ok(!revisited.includes(credentials.password))
        assert.match(revisited, /Добавьте почтовый домен/)
        assert.doesNotMatch(revisited, /name="domain"/)
    } finally {
        await vite.close()
    }
})
