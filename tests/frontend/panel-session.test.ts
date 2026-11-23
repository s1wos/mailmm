import assert from 'node:assert/strict'
import { test, type TestContext } from 'node:test'
import { setImmediate as flush } from 'node:timers/promises'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { PanelUser } from '../../resources/js/panel/types.ts'
import { loadComponent, mountSsrComponent } from './vueFixture.ts'

const admin = { id: 1, email: 'admin@example.test', is_admin: true }
const owner = { id: 2, email: 'owner@example.test', is_admin: false }
const emptyList = { data: [], current_page: 1, last_page: 1, total: 0, prev_page_url: null, next_page_url: null }
const ticket = '/admin/mailboxes?issued=12&ticket=one-use'
const credentials = { email: 'new@example.test', password: 'one-use-test-password' }
const issued = { screen: 'mailboxes', user: admin, domains: [], mailboxes: emptyList, credentials }

function archive(user: PanelUser) {
    return { screen: 'archive', user, recipient: '', listQuery: {}, messages: emptyList }
}

const beforeExpiry = '2026-11-01T10:00:00Z'
const expiry = '2026-11-01T10:01:00Z'
const later = '2026-11-01T10:05:00Z'
const archivePath = '/admin/archive'

function pageOfLetters(count: number, expiresAt: string) {
    return Array.from({ length: count }, (_, i) => ({
        id: 26 - i, subject: 'Letter ' + (26 - i), from: 'sender@example.test', recipient: owner.email,
        received_at: expiresAt.replace('2026', '2025'), expires_at: expiresAt,
    }))
}

function firstOfTwoPages(rowExpiry: string) {
    return {
        ...archive(admin), serverTime: beforeExpiry, nextExpiryAt: expiry,
        messages: { ...emptyList, data: pageOfLetters(25, rowExpiry), total: 26, last_page: 2, next_page_url: archivePath + '?page=2' },
    }
}

async function openPanel(t: TestContext, path: string) {
    const PanelPage = await loadComponent('/resources/js/panel/PanelPage.vue')
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/admin/:path(.*)*', component: PanelPage, meta: { panel: true } },
            { path: '/:path(.*)*', component: {} },
        ],
    })
    await router.push(path)
    const events = new EventTarget()
    Object.assign(globalThis, {
        window: events,
        document: Object.assign(new EventTarget(), { hidden: false, title: '' }),
    })
    const requests: { path: string, respond: (response: Response) => void }[] = []
    t.mock.method(globalThis, 'fetch', (path: string, options: RequestInit) => {
        assert.equal(options.method ?? 'GET', 'GET')
        const response = Promise.withResolvers<Response>()
        requests.push({ path, respond: response.resolve })
        return response.promise
    })

    const { html, unmount } = mountSsrComponent(PanelPage, {}, createPinia(), router)
    t.after(() => {
        unmount()
        Reflect.deleteProperty(globalThis, 'window')
        Reflect.deleteProperty(globalThis, 'document')
    })

    async function respond(path: string, page: object, status = 200) {
        const index = requests.findIndex(request => request.path === path)
        assert.notEqual(index, -1, 'Expected a pending GET ' + path)
        requests.splice(index, 1)[0].respond(Response.json(page, { status }))
        await flush()
    }
    return { router, html, respond, requests, dispatch: (event: Event) => events.dispatchEvent(event) }
}

test('paging within a panel list keeps its rows mounted until the new page arrives', async t => {
    const panel = await openPanel(t, archivePath)
    await panel.respond(archivePath, firstOfTwoPages(later))
    const next = archivePath + '?page=2'
    await panel.router.push(next)
    assert.match(await panel.html(), /Letter 26/)
    await panel.respond(next, { ...archive(admin), serverTime: beforeExpiry, nextExpiryAt: later,
        messages: { ...emptyList, current_page: 2, last_page: 2, data: [{ ...pageOfLetters(1, later)[0], subject: 'Last letter' }] } })
    assert.match(await panel.html(), /Last letter/)
    assert.doesNotMatch(await panel.html(), /Letter 26/)
})

test('moving between mailbox receipts keeps the form but immediately clears the previous password', async t => {
    const panel = await openPanel(t, ticket)
    await panel.respond(ticket, issued)
    const next = '/admin/mailboxes?issued=15&ticket=second'
    await panel.router.push(next)
    const pending = await panel.html()
    assert.match(pending, /<h1>Ящики<\/h1>/)
    assert.ok(!pending.includes(credentials.password))
})

test('a failed next page leaves the current list visible with a retry', async t => {
    const panel = await openPanel(t, archivePath)
    await panel.respond(archivePath, firstOfTwoPages(later))
    const next = archivePath + '?page=2'
    await panel.router.push(next)
    await panel.respond(next, {}, 503)
    const html = await panel.html()
    assert.ok(html.includes('Letter 26'))
    assert.match(html, /<button\b[^>]*>Повторить<\/button>/)
})

test('losing permission while paging hides the retained list and rechecks the account', async t => {
    const panel = await openPanel(t, archivePath)
    await panel.respond(archivePath, firstOfTwoPages(later))
    const next = archivePath + '?page=2'
    await panel.router.push(next)
    await panel.respond(next, {}, 403)
    assert.ok(!(await panel.html()).includes('Letter 26'))
    await panel.respond('/admin/session', { user: owner })
    assert.equal(panel.router.currentRoute.value.fullPath, '/admin')
})

test('a failed page request still checks the account after the window regained focus', async t => {
    const panel = await openPanel(t, archivePath)
    await panel.respond(archivePath, firstOfTwoPages(later))
    const next = archivePath + '?page=2'
    await panel.router.push(next)
    panel.dispatch(new Event('focus'))
    await panel.respond(next, {}, 503)
    await panel.respond('/admin/session', { user: owner })
    assert.equal(panel.router.currentRoute.value.fullPath, '/admin')
    assert.ok(!(await panel.html()).includes('Letter 26'))
})

test('revisiting recently opened panel tabs shows their data immediately without fetching them again', async t => {
    const panel = await openPanel(t, archivePath)
    await panel.respond(archivePath, firstOfTwoPages(later))
    const mailboxesPath = '/admin/mailboxes'
    await panel.router.push(mailboxesPath)
    await panel.respond(mailboxesPath, { ...issued, credentials: null })

    await panel.router.push(archivePath)
    assert.ok((await panel.html()).includes('Letter 26'), 'The previous archive rows should already be visible')
    await panel.router.push(mailboxesPath)
    assert.ok((await panel.html()).includes('<h1>Ящики</h1>'), 'The mailbox list should already be visible')
    assert.deepEqual(panel.requests.map(request => request.path).filter(path => [archivePath, mailboxesPath].includes(path)), [])
})

test('revisiting a stale archive keeps its rows visible until a single refresh replaces them', async t => {
    const panel = await openPanel(t, archivePath)
    t.mock.timers.enable({ apis: ['Date', 'setTimeout'], now: Date.parse(beforeExpiry) })
    await panel.respond(archivePath, { ...firstOfTwoPages(later), nextExpiryAt: later })
    await panel.router.push('/admin/mailboxes')
    await panel.respond('/admin/mailboxes', { ...issued, credentials: null })
    t.mock.timers.tick(61_000)

    await panel.router.push(archivePath)
    assert.ok((await panel.html()).includes('Letter 26'), 'Existing rows should remain visible during refresh')
    await panel.respond(archivePath, { ...archive(admin), serverTime: expiry, nextExpiryAt: null })
    const html = await panel.html()
    assert.match(html, /Писем пока нет/)
    assert.doesNotMatch(html, /Letter 26/)
    assert.deepEqual(panel.requests.filter(request => request.path === archivePath), [])
})

test('an unavailable background refresh preserves the cached settings and offers a retry', async t => {
    const path = '/admin/settings'
    const panel = await openPanel(t, path)
    t.mock.timers.enable({ apis: ['Date'], now: Date.parse(beforeExpiry) })
    await panel.respond(path, { screen: 'settings', user: admin, domains: [], guestIssuanceEnabled: true, mxHost: 'mx.example.test' })
    await panel.router.push(archivePath)
    await panel.respond(archivePath, archive(admin))
    t.mock.timers.tick(61_000)

    await panel.router.push(path)
    await panel.respond(path, {}, 503)
    const html = await panel.html()
    assert.ok(html.includes('mx.example.test'), 'The cached MX host should stay on screen after a failed refresh')
    assert.match(html, /Не удалось/)
    assert.match(html, /<button\b[^>]*>Повторить<\/button>/)
})

test('a delayed background dashboard response cannot restore a letter that expired during refresh', async t => {
    const panel = await openPanel(t, '/admin')
    t.mock.timers.enable({ apis: ['Date', 'setTimeout'], now: Date.parse(beforeExpiry) })
    const dashboard = {
        screen: 'dashboard', user: admin, serverTime: beforeExpiry, days: [],
        metrics: { received24h: 1, archived24h: 0, guests: 0, permanent: 0 },
        recentMessages: pageOfLetters(1, '2026-11-01T10:02:00Z'),
    }
    await panel.respond('/admin', dashboard)
    await panel.router.push('/admin/settings')
    await panel.respond('/admin/settings', { screen: 'settings', user: admin, domains: [], guestIssuanceEnabled: true, mxHost: 'mx.example.test' })
    t.mock.timers.tick(61_000)
    await panel.router.push('/admin')
    assert.ok((await panel.html()).includes('Letter 26'))
    t.mock.timers.tick(60_000)
    assert.ok(!(await panel.html()).includes('Letter 26'))

    await panel.respond('/admin', { ...dashboard, serverTime: '2026-11-01T10:01:01Z' })
    assert.ok(!(await panel.html()).includes('Letter 26'), 'The delayed response must not bring back the expired letter')
})

test('going back to a redeemed credentials URL never restores the one-use password', async t => {
    const panel = await openPanel(t, ticket)
    await panel.respond(ticket, issued)
    assert.ok((await panel.html()).includes(credentials.password))
    await panel.router.push(archivePath)
    await panel.respond(archivePath, archive(admin))

    panel.router.back()
    await flush()
    assert.equal(panel.router.currentRoute.value.fullPath, ticket)
    assert.ok(!(await panel.html()).includes(credentials.password))
    await panel.respond(ticket, { ...issued, credentials: null })
    const html = await panel.html()
    assert.match(html, /<h1>Ящики<\/h1>/)
    assert.ok(!html.includes(credentials.password))
})

test('switching to a cached tab during a session check honors a revoked administrator role', async t => {
    const panel = await openPanel(t, archivePath)
    await panel.respond(archivePath, firstOfTwoPages(later))
    await panel.router.push('/admin/mailboxes')
    await panel.respond('/admin/mailboxes', { ...issued, credentials: null })
    panel.dispatch(new Event('focus'))
    await panel.router.push(archivePath)

    const demoted = { ...admin, is_admin: false }
    await panel.respond('/admin/session', { user: demoted })
    assert.equal(panel.router.currentRoute.value.fullPath, '/admin')
    assert.doesNotMatch(await panel.html(), /Letter 26/)
    await panel.respond('/admin', archive(demoted))
    assert.match(await panel.html(), /<h1>Моя почта<\/h1>/)
})

test('a late page response cannot replace the archive after navigation', async t => {
    const panel = await openPanel(t, ticket)
    await panel.router.push('/admin/archive')
    await panel.respond('/admin/archive', archive(admin))
    assert.match(await panel.html(), /<h1>Архив писем<\/h1>/u)

    await panel.respond(ticket, issued)
    const html = await panel.html()
    assert.match(html, /<h1>Архив писем<\/h1>/u)
    assert.ok(!html.includes(credentials.password))
})

test('expiry of all 26 letters clears the archive count and navigation', async t => {
    const panel = await openPanel(t, archivePath)
    t.mock.timers.enable({ apis: ['Date', 'setTimeout'], now: Date.parse(beforeExpiry) })
    await panel.respond(archivePath, firstOfTwoPages(expiry))
    assert.match(await panel.html(), /class="count">26</)
    t.mock.timers.tick(60_000)
    const pending = await panel.html()
    assert.doesNotMatch(pending, /Letter 26|Страницы писем|class="count"/)
    await panel.respond(archivePath, { ...archive(admin), serverTime: expiry, nextExpiryAt: null })
    const empty = await panel.html()
    assert.match(empty, /class="count">0</)
    assert.match(empty, /Писем пока нет/)
    assert.doesNotMatch(empty, /Страницы писем/)
    t.mock.timers.tick(300_000)
    assert.deepEqual(panel.requests, [])
})

test('temporary mailboxes show their details and drop expired rows while refreshing the list', async t => {
    const path = '/admin/temporary-mailboxes'
    const panel = await openPanel(t, path)
    t.mock.timers.enable({ apis: ['Date', 'setTimeout'], now: Date.parse(beforeExpiry) })
    const page = { screen: 'temporary-mailboxes', user: admin, serverTime: beforeExpiry, nextExpiryAt: expiry }
    await panel.respond(path, { ...page, mailboxes: {
        ...emptyList, total: 13, last_page: 2, next_page_url: path + '?page=2',
        data: [{ id: 9, email: 'guest@example.test', created_at: beforeExpiry, expires_at: expiry, messages_count: 7 }],
    } })
    const html = await panel.html()
    assert.match(html, /<h1>Временные ящики<\/h1>/)
    assert.match(html, /href="\/admin\/temporary-mailboxes"/)
    assert.match(html, /href="\/admin\/archive\?recipient=guest@example\.test"/)
    assert.match(html, /datetime="2026-11-01T10:00:00Z"/)
    assert.match(html, /datetime="2026-11-01T10:01:00Z"/)
    assert.match(html, />7</)
    assert.match(html, /href="\/admin\/temporary-mailboxes\?page=2"/)
    assert.match(html, /<button\b[^>]*>Обновить<\/button>/)
    t.mock.timers.tick(60_000)
    assert.doesNotMatch(await panel.html(), /guest@example\.test|class="count"/)
    await panel.respond(path, { ...page, serverTime: expiry, nextExpiryAt: null, mailboxes: emptyList })
    assert.match(await panel.html(), /Активных временных ящиков нет/)
    await panel.router.push('/admin')
    await panel.respond('/admin', archive(owner))
    await panel.respond('/admin', archive(owner))
    assert.doesNotMatch(await panel.html(), /href="\/admin\/temporary-mailboxes"/)
})

test('returning to the archive reconciles off-page expiry and keeps current rows through a failed refresh', async t => {
    const panel = await openPanel(t, archivePath)
    t.mock.timers.enable({ apis: ['Date', 'setTimeout'], now: Date.parse(beforeExpiry) })
    const first = firstOfTwoPages(later)
    await panel.respond(archivePath, first)
    t.mock.timers.setTime(Date.parse(expiry))
    document.dispatchEvent(new Event('visibilitychange'))
    await panel.respond('/admin/session', { user: admin })
    await panel.respond(archivePath, {}, 503)
    const failed = await panel.html()
    assert.match(failed, /Letter 26/)
    assert.match(failed, /Повторить/)
    assert.doesNotMatch(failed, /Страницы писем|class="count"/)
    t.mock.timers.tick(30_000)
    assert.deepEqual(panel.requests, [])

    panel.dispatch(new Event('focus'))
    await panel.respond('/admin/session', { user: admin })
    await panel.respond(archivePath, {
        ...first, serverTime: expiry, nextExpiryAt: later, messages: { ...emptyList, data: first.messages.data, total: 25 },
    })
    const current = await panel.html()
    assert.match(current, /class="count">25</)
    assert.doesNotMatch(current, /Страницы писем|Повторить/)
})

test('when temporary mailboxes close, a vanished page returns to a fresh first page', async t => {
    const path = '/admin/temporary-mailboxes'
    const second = path + '?page=2'
    const panel = await openPanel(t, path)
    const page = { screen: 'temporary-mailboxes', user: admin, serverTime: beforeExpiry, nextExpiryAt: later }
    const box = { id: 5, email: 'closed@example.test', created_at: beforeExpiry, expires_at: later, messages_count: 0 }
    await panel.respond(path, { ...page, mailboxes: {
        ...emptyList, data: [box], total: 13, last_page: 2, next_page_url: second,
    } })
    await panel.router.push(second)
    await panel.respond(second, { ...page, mailboxes: { ...emptyList, total: 1, current_page: 2, prev_page_url: path } })
    assert.equal(panel.router.currentRoute.value.fullPath, path)
    assert.doesNotMatch(await panel.html(), /closed@example\.test/)
    await panel.respond(path, { ...page, mailboxes: { ...emptyList, total: 1, data: [{ ...box, id: 6, email: 'open@example.test' }] } })
    assert.match(await panel.html(), /open@example\.test/)
    assert.doesNotMatch(await panel.html(), /Страницы временных ящиков/)
})

test('when the last filtered archive page expires the panel returns to the remaining page', async t => {
    const filtered = archivePath + '?recipient=owner@example.test'
    const second = filtered + '&page=2'
    const panel = await openPanel(t, second)
    t.mock.timers.enable({ apis: ['Date', 'setTimeout'], now: Date.parse(beforeExpiry) })
    const page = {
        ...archive(admin), recipient: owner.email, listQuery: { recipient: owner.email, page: 2 },
        serverTime: beforeExpiry, nextExpiryAt: expiry, messages: {
            ...emptyList, current_page: 2, last_page: 2, prev_page_url: filtered,
            data: pageOfLetters(1, expiry), total: 26,
        },
    }
    await panel.respond(second, page)
    t.mock.timers.tick(60_000)
    await panel.respond(second, { ...page, serverTime: expiry, nextExpiryAt: later, messages: {
        ...emptyList, current_page: 2, prev_page_url: filtered, total: 25,
    } })
    assert.equal(panel.router.currentRoute.value.fullPath, filtered)
    await panel.respond(filtered, { ...page, serverTime: expiry, nextExpiryAt: later, listQuery: { recipient: owner.email },
        messages: { ...emptyList, total: 25, data: pageOfLetters(25, later) } })
    const html = await panel.html()
    assert.match(html, /class="count">25</)
    assert.doesNotMatch(html, /Страницы писем/)
})

test('a device clock jump does not cause repeated refreshes while the server still retains the archive', async t => {
    const panel = await openPanel(t, archivePath)
    t.mock.timers.enable({ apis: ['Date', 'setTimeout'], now: Date.parse(beforeExpiry) })
    const page = firstOfTwoPages(later)
    await panel.respond(archivePath, page)
    t.mock.timers.setTime(Date.parse(expiry) + 3_600_000)
    panel.dispatch(new Event('focus'))
    await panel.respond('/admin/session', { user: admin })
    await panel.respond(archivePath, { ...page, serverTime: '2026-11-01T10:00:10Z' })
    assert.match(await panel.html(), /class="count">26</)
    assert.deepEqual(panel.requests, [])
})

test('a slow archive refresh cannot reveal rows that expired while its response was in flight', async t => {
    const panel = await openPanel(t, archivePath)
    t.mock.timers.enable({ apis: ['Date', 'setTimeout'], now: Date.parse(beforeExpiry) })
    const page = firstOfTwoPages(later)
    await panel.respond(archivePath, page)
    t.mock.timers.tick(60_000)
    t.mock.timers.tick(240_000)
    assert.doesNotMatch(await panel.html(), /Letter 26/)
    await panel.respond(archivePath, {
        ...page, serverTime: expiry, nextExpiryAt: later, messages: { ...emptyList, data: page.messages.data, total: 25 },
    })
    assert.doesNotMatch(await panel.html(), /Letter 26/)
    await panel.respond(archivePath, { ...archive(admin), serverTime: later, nextExpiryAt: null })
    assert.match(await panel.html(), /class="count">0</)
})

for (const change of ['filter', 'account']) {
    test('a late archive refresh cannot overwrite the new ' + change, async t => {
        const panel = await openPanel(t, archivePath)
        t.mock.timers.enable({ apis: ['Date', 'setTimeout'], now: Date.parse(beforeExpiry) })
        await panel.respond(archivePath, firstOfTwoPages(later))
        t.mock.timers.tick(60_000)
        const next = change === 'filter' ? archivePath + '?recipient=other@example.test' : '/admin'
        if (change === 'filter') await panel.router.push(next)
        else {
            panel.dispatch(new Event('focus'))
            await panel.respond('/admin/session', { user: owner })
        }
        await panel.respond(next, { ...archive(change === 'filter' ? admin : owner),
            recipient: change === 'filter' ? 'other@example.test' : '', serverTime: expiry, nextExpiryAt: null })
        await panel.respond(archivePath, { ...firstOfTwoPages(later), serverTime: expiry, nextExpiryAt: later })
        const html = await panel.html()
        assert.match(html, /class="count">0</)
        assert.doesNotMatch(html, /Letter 26|Страницы писем/)
    })
}

const journalPath = '/admin/errors'
const older = '/admin/errors?cursor=previous-batch'
const newer = '/admin/errors?cursor=newer-batch'
function journal(message: string, previous: string | null = null) {
    return {
        screen: 'errors', user: admin, collection: { online: true, sources: {} }, entries: {
            data: [{ id: 23, occurred_at: '2026-11-21T13:52:01Z', service: 'smtp', level: 'error', message }],
            next_page_url: previous ? null : older, prev_page_url: previous,
        },
    }
}

test('the administrator sees fresh errors in the open journal while an older page stays stable', async t => {
    const panel = await openPanel(t, journalPath)
    t.mock.timers.enable({ apis: ['setInterval'] })
    await panel.respond(journalPath, journal('<invalid recipient>'))
    const first = await panel.html()
    assert.match(first, /<h1>Ошибки<\/h1>/)
    assert.match(first, /href="\/admin\/errors\?cursor=previous-batch"/)
    assert.match(first, /&lt;invalid recipient&gt;/)
    assert.match(first, /smtp/)
    assert.match(first, /datetime="2026-11-21T13:52:01Z"/)

    Object.assign(document, { hidden: true })
    t.mock.timers.tick(10_000)
    assert.deepEqual(panel.requests, [])
    Object.assign(document, { hidden: false })
    t.mock.timers.tick(10_000)
    await panel.respond(journalPath, journal('New delivery error'))
    assert.match(await panel.html(), /New delivery error/)

    t.mock.timers.tick(10_000)
    await panel.router.push(older)
    await panel.respond(older, journal('Older error', newer))
    await panel.respond(journalPath, journal('Late delivery error'))
    t.mock.timers.tick(30_000)
    assert.deepEqual(panel.requests, [])
    assert.match(await panel.html(), /Older error/)
    assert.doesNotMatch(await panel.html(), /Late delivery error/)

    await panel.router.push(newer)
    await panel.respond(newer, journal('Back at the start'))
    assert.equal(panel.router.currentRoute.value.fullPath, journalPath)
    await panel.respond(journalPath, journal('Back at the start'))
    t.mock.timers.tick(10_000)
    await panel.respond(journalPath, journal('Live again'))
    assert.match(await panel.html(), /Live again/)
})

test('a revoked administrator loses the displayed journal when its automatic request is denied', async t => {
    const panel = await openPanel(t, journalPath)
    t.mock.timers.enable({ apis: ['setInterval'] })
    await panel.respond(journalPath, journal('Private diagnostics'))
    t.mock.timers.tick(10_000)
    await panel.respond(journalPath, {}, 403)
    assert.doesNotMatch(await panel.html(), /Private diagnostics/)
    await panel.respond('/admin', archive(owner))
    assert.doesNotMatch(await panel.html(), /href="\/admin\/errors"/)
})

test('an empty or populated journal identifies unavailable sources and keeps the last successful check', async t => {
    const panel = await openPanel(t, journalPath)
    t.mock.timers.enable({ apis: ['setInterval'] })
    const good = { available: true, last_success_at: '2026-11-22T07:00:00Z' }
    const broken = { available: false, last_success_at: '2026-11-22T06:00:00Z' }
    const collection = { online: true, sources: { 'smtp/1': broken, 'worker/1': good } }
    const data = journal('Previous diagnostic')
    await panel.respond(journalPath, { ...data, entries: { ...data.entries, data: [] }, collection })
    const empty = await panel.html()
    assert.doesNotMatch(empty, /Ошибок пока нет/)
    assert.match(empty, /smtp\/1/)
    assert.match(empty, /datetime="2026-11-22T06:00:00Z"/)
    t.mock.timers.tick(10_000)
    await panel.respond(journalPath, { ...data, collection: { ...collection, online: false } })
    const populated = await panel.html()
    assert.match(populated, /Сборщик недоступен/)
    assert.match(populated, /Previous diagnostic/)
})

test('rechecking a historical journal page removes it when the same account has lost its role', async t => {
    const panel = await openPanel(t, older)
    await panel.respond(older, journal('Archived diagnostics', newer))
    panel.dispatch(new Event('focus'))
    await panel.respond('/admin/session', { user: { ...admin, is_admin: false } })
    assert.equal(panel.router.currentRoute.value.fullPath, '/admin')
    assert.doesNotMatch(await panel.html(), /Archived diagnostics/)
})

test('focus during a page load checks the account before showing the pending credentials', async t => {
    const panel = await openPanel(t, '/admin/archive')
    await panel.respond('/admin/archive', archive(admin))
    await panel.router.push(ticket)
    panel.dispatch(new Event('focus'))
    await panel.respond(ticket, issued)
    assert.ok(!(await panel.html()).includes(credentials.password))

    await panel.respond('/admin/session', { user: owner })
    assert.equal(panel.router.currentRoute.value.fullPath, '/admin')
    await panel.respond('/admin', archive(owner))
    const html = await panel.html()
    assert.match(html, /<h1>Моя почта<\/h1>/u)
    assert.ok(html.includes(owner.email))
    assert.ok(!html.includes(admin.email))
    assert.ok(!html.includes(credentials.password))
})

test('focus preserves an issued password when the same account is still signed in', async t => {
    const panel = await openPanel(t, ticket)
    await panel.respond(ticket, issued)
    assert.ok((await panel.html()).includes(credentials.password))

    panel.dispatch(new Event('focus'))
    assert.ok((await panel.html()).includes(credentials.password))
    await panel.respond('/admin/session', { user: { ...admin } })
    assert.ok((await panel.html()).includes(credentials.password))
    assert.deepEqual(panel.requests, [], 'the one-use ticket must not be fetched again')
})

test('restoring the previous document from browser history cannot reveal the issued password again', async t => {
    const panel = await openPanel(t, ticket)
    await panel.respond(ticket, issued)
    assert.ok((await panel.html()).includes(credentials.password))
    panel.dispatch(new Event('pagehide'))
    assert.ok(!(await panel.html()).includes(credentials.password))
    panel.dispatch(Object.assign(new Event('pageshow'), { persisted: true }))
    await panel.respond(ticket, { ...issued, credentials: null })
    assert.match(await panel.html(), /<h1>Ящики<\/h1>/u)
    assert.ok(!(await panel.html()).includes(credentials.password))
})

for (const wake of ['timer', 'visibilitychange']) {
    for (const screen of ['dashboard', 'archive', 'owner']) {
        test(`expired summaries disappear from the open ${screen} on ${wake}`, async t => {
            const path = screen === 'archive' ? '/admin/archive' : '/admin'
            const panel = await openPanel(t, path)
            const deviceTime = Date.parse('2036-11-01T10:00:00Z')
            t.mock.timers.enable({ apis: ['Date', 'setTimeout'], now: deviceTime })
            const rows = ['Expired subject', 'Current subject'].map((subject, i) => ({
                id: 37 + i, subject, from: 'sender' + i + '@example.test', recipient: owner.email,
                received_at: '2025-11-01T10:00:00Z', expires_at: `2026-11-01T10:0${i + 1}:00Z`,
            }))
            const data = screen === 'dashboard' ? {
                screen, user: admin, metrics: { received24h: 0, archived24h: 0, guests: 0, permanent: 0 }, days: [], recentMessages: rows,
            } : { ...archive(screen === 'owner' ? owner : admin), messages: { ...emptyList, data: rows, total: 2 } }
            await panel.respond(path, { ...data, serverTime: '2026-11-01T10:00:00Z' })
            assert.match(await panel.html(), /Expired subject/)
            if (wake === 'timer') t.mock.timers.tick(60_000)
            else {
                t.mock.timers.setTime(deviceTime + 60_000)
                document.dispatchEvent(new Event('visibilitychange'))
            }
            const html = await panel.html()
            assert.doesNotMatch(html, /Expired subject|sender0@example.test|messages\/37/)
            assert.match(html, /Current subject/)
        })
    }
    test(`an open panel message and its download expire on ${wake} without a server response`, async t => {
        const panel = await openPanel(t, '/admin/messages/37')
        const deviceTime = Date.parse('2036-11-01T10:00:00Z')
        t.mock.timers.enable({ apis: ['Date', 'setTimeout'], now: deviceTime })
        await panel.respond('/admin/messages/37', {
            screen: 'message', user: owner, backUrl: '/admin', serverTime: '2026-11-01T10:00:00Z',
            letter: {
                id: 37, subject: 'Last minute', from: 'sender@example.test', recipient: owner.email,
                received_at: '2025-11-01T10:01:00Z', expires_at: '2026-11-01T10:01:00Z', body_text: 'Private letter',
                attachments: [{ filename: 'report.pdf', url: '/admin/messages/37/attachments/0' }],
            },
        })
        const before = await panel.html()
        assert.match(before, /Private letter/)
        assert.match(before, /href="\/admin\/messages\/37\/attachments\/0"/)
        if (wake === 'timer') {
            t.mock.timers.tick(60_000)
        } else {
            t.mock.timers.setTime(deviceTime + 60_000)
            document.dispatchEvent(new Event('visibilitychange'))
        }
        const after = await panel.html()
        assert.doesNotMatch(after, /Private letter|Last minute|report\.pdf|\/attachments\/0/)
        assert.doesNotMatch(document.title, /Last minute/)
        assert.match(after, /Срок хранения письма истёк/)
    })
}
