import assert from 'node:assert/strict'
import { beforeEach, test } from 'node:test'
import { createPinia, setActivePinia } from 'pinia'
import { useEmailStore } from '../../resources/js/stores/emailStore.ts'

const first = { id: 1, email: 'first@mail.test', expires_at: '2099-01-02T00:00:00Z' }
const second = { ...first, id: 2, email: 'second@mail.test' }
const message = { id: 4, from: 'sender@mail.test', subject: 'Hello', received_at: new Date().toISOString(), expires_at: '2099-01-01T00:00:00Z', has_attachments: false }
const detail = (id: number) => Response.json({ data: { ...message, id, body_text: 'Full text', attachments: [] } }, { headers: { 'X-Mailbox-Id': String(first.id) } })
const address = (mailbox: typeof first, snapshot?: string) => Response.json(
    { data: mailbox }, { headers: snapshot ? { 'X-Inbox-Snapshot': snapshot } : {} },
)
const list = (id: number, data = [message], cursor: string | null = null) => Response.json(
    { data, meta: { next_cursor: cursor } }, { headers: { 'X-Mailbox-Id': String(id) } },
)

beforeEach(() => setActivePinia(createPinia()))

test('an account change discards a late mailbox response and clears the panel link', async (t) => {
    const pending = Promise.withResolvers<Response>()
    t.mock.method(globalThis, 'fetch', () => pending.promise)
    const store = useEmailStore()
    store.panelUrl = '/admin'
    store.mailbox = first
    const refresh = store.refresh()
    store.resetSession()
    pending.resolve(address(first))
    await refresh
    assert.equal(store.mailbox, null)
    assert.equal(store.panelUrl, null)
})

test('returning from the panel reads the new mailbox even when the previous visit still has a request in flight', async t => {
    const firstRead = Promise.withResolvers<Response>()
    let reads = 0
    t.mock.method(globalThis, 'fetch', async (path: string) => {
        if (path === '/api/email') return ++reads === 1 ? firstRead.promise : address(second)
        return list(second.id)
    })
    const store = useEmailStore()
    const oldVisit = store.refresh()
    store.resetSession()
    const newVisit = store.refresh()
    firstRead.resolve(address(first))
    await Promise.all([oldVisit, newVisit])
    assert.equal(store.mailbox?.id, second.id)
})

test('a mailbox check updates panel access even while address issuance is paused', async (t) => {
    let signedIn = true
    t.mock.method(globalThis, 'fetch', async () => Response.json({ code: 'guest_issuance_paused' }, {
        status: 503, headers: signedIn ? { 'X-Panel-Url': '/admin' } : {},
    }))
    const store = useEmailStore()
    await store.refresh()
    assert.equal(store.mailbox, null)
    assert.equal(store.panelUrl, '/admin')
    signedIn = false
    await store.refresh()
    assert.equal(store.panelUrl, null)
})

test('a manual check during polling shares the active request and its result', async (t) => {
    const started = Promise.withResolvers<void>()
    const response = Promise.withResolvers<Response>()
    const requests: string[] = []
    t.mock.method(globalThis, 'fetch', async (url: string) => {
        requests.push(url)
        if (url === '/api/email') return address(first)
        started.resolve()
        return response.promise
    })
    const store = useEmailStore()
    const poll = store.refresh()
    await started.promise
    const manual = store.refresh()
    response.resolve(list(first.id))
    await Promise.all([poll, manual])
    assert.deepEqual(requests, ['/api/email', '/api/messages'])
    assert.deepEqual(store.messages, [message])
    assert.equal(store.error, '')
})

test('an arrival during a pending check schedules a fresh read for the new letter', async (t) => {
    const started = Promise.withResolvers<void>()
    const response = Promise.withResolvers<Response>()
    let reads = 0
    t.mock.method(globalThis, 'fetch', async (url: string) => {
        if (url === '/api/email') return address(first)
        if (++reads > 1) return list(first.id)
        started.resolve()
        return response.promise
    })
    const store = useEmailStore()
    const manual = store.refresh()
    await started.promise
    const delivery = store.refresh({ afterPending: true })
    response.resolve(list(first.id, []))
    await Promise.all([manual, delivery])
    assert.deepEqual(store.messages, [message])
    assert.equal(reads, 2)
})

test('restores the server mailbox and unwraps the resource collection without rotating', async (t) => {
    const requests: string[] = []
    t.mock.method(globalThis, 'fetch', async (url: string, options: RequestInit) => {
        requests.push((options.method ?? 'GET') + ' ' + url)
        return url === '/api/email' ? address(first) : list(first.id)
    })
    const store = useEmailStore()
    await store.refresh()
    assert.deepEqual(requests, ['GET /api/email', 'GET /api/messages'])
    assert.equal(store.mailbox?.email, first.email)
    assert.deepEqual(store.messages, [message])
})

test('browsing the domain choices only reads options and preserves the current mailbox', async (t) => {
    const options = [{ domain: 'other.test', formats: ['random', 'words'] }]
    const requests: string[] = []
    t.mock.method(globalThis, 'fetch', async (url: string, init: RequestInit) => {
        requests.push(init.method + ' ' + url)
        if (url === '/api/email/options') return Response.json({ data: options })
        return url === '/api/email' ? address(first) : list(first.id)
    })
    const store = useEmailStore()
    await store.refresh()
    await store.loadOptions()
    const reopened = store.loadOptions()
    assert.equal(store.optionsLoading, false)
    await reopened
    assert.deepEqual(requests, ['GET /api/email', 'GET /api/messages', 'GET /api/email/options'])
    assert.deepEqual(store.domainOptions, options)
    assert.deepEqual(store.mailbox, first)
    assert.deepEqual(store.messages, [message])
})

test('a failed domain lookup can be retried and an empty catalogue is cached until it expires', async t => {
    t.mock.timers.enable({ apis: ['Date'], now: Date.UTC(2026, 0, 1) })
    const available = [{ domain: 'new.test', formats: ['random'] }]
    const fetch = t.mock.method(globalThis, 'fetch', async () => Response.json({ data: available }))
    fetch.mock.mockImplementationOnce(async () => new Response(null, { status: 503 }))
    fetch.mock.mockImplementationOnce(async () => Response.json({ data: [] }), 1)
    const store = useEmailStore()
    await store.loadOptions()
    assert.ok(store.optionsError)
    await store.loadOptions()
    assert.equal(store.optionsError, '')
    await store.loadOptions()
    assert.equal(fetch.mock.callCount(), 2)
    assert.deepEqual(store.domainOptions, [])

    t.mock.timers.tick(60 * 60 * 1000)
    const refresh = store.loadOptions()
    assert.equal(store.optionsLoading, false)
    await refresh
    assert.deepEqual(store.domainOptions, available)
})

test('one confirmation sends the chosen domain and format as JSON with CSRF and the expected mailbox', async (t) => {
    const posts: RequestInit[] = []
    const issued = { ...second, email: 'mint.fox@other.test' }
    Object.defineProperty(globalThis, 'document', { configurable: true, value: { cookie: 'XSRF-TOKEN=csrf%3D' } })
    t.after(() => { Reflect.deleteProperty(globalThis, 'document') })
    t.mock.method(globalThis, 'fetch', async (url: string, init: RequestInit) => {
        if (url === '/api/emails') {
            posts.push(init)
            return address(issued)
        }
        return url === '/api/email' ? address(first) : list(posts.length ? issued.id : first.id)
    })
    const store = useEmailStore()
    await store.refresh()
    await Promise.all([store.rotate({ domain: 'other.test', format: 'words' }), store.rotate()])
    assert.equal(posts.length, 1)
    assert.equal(posts[0].method, 'POST')
    assert.deepEqual(JSON.parse(String(posts[0].body)), { domain: 'other.test', format: 'words' })
    const headers = new Headers(posts[0].headers)
    assert.equal(headers.get('Content-Type'), 'application/json')
    assert.equal(headers.get('X-XSRF-TOKEN'), 'csrf=')
    assert.equal(headers.get('X-Expected-Mailbox-Id'), String(first.id))
    assert.deepEqual(store.mailbox, issued)
})

test('a disabled domain refreshes choices and keeps the old mailbox until a new confirmation', async (t) => {
    const requests: string[] = []
    const available = { domain: 'other.test', formats: ['random'] }
    const rejected = 'На выбранном домене выдача остановлена.'
    t.mock.method(globalThis, 'fetch', async (url: string, init: RequestInit) => {
        requests.push(init.method + ' ' + url)
        if (url === '/api/email') return address(first)
        if (url === '/api/messages') return list(first.id)
        if (url === '/api/email/options') return Response.json({ data: requests.includes('POST /api/emails')
            ? [available] : [{ domain: 'mail.test', formats: ['random', 'words'] }] })
        return Response.json({ errors: { domain: [rejected] } }, { status: 422 })
    })
    const store = useEmailStore()
    await store.refresh()
    await store.loadOptions()
    const correction = await store.rotate({ domain: 'mail.test', format: 'words' })
    assert.deepEqual(requests, ['GET /api/email', 'GET /api/messages', 'GET /api/email/options', 'POST /api/emails', 'GET /api/email/options'])
    assert.equal(correction, rejected)
    assert.deepEqual(store.domainOptions, [available])
    assert.deepEqual(store.mailbox, first)
    assert.deepEqual(store.messages, [message])
})

test('a rejected rotation can return corrected choices without issuing a second options request', async t => {
    const available = { domain: 'active.example.test', formats: ['random'] }
    const paths: string[] = []
    t.mock.method(globalThis, 'fetch', async (path: string) => {
        paths.push(path)
        if (path === '/api/emails') return Response.json({
            errors: { domain: ['Домен больше недоступен.'] }, options: [available],
        }, { status: 422 })
        return path === '/api/email' ? address(first) : list(first.id)
    })
    const store = useEmailStore()
    await store.refresh()
    assert.equal(await store.rotate({ domain: 'closed.example.test' }), 'Домен больше недоступен.')
    await store.loadOptions()
    assert.deepEqual(store.domainOptions, [available])
    assert.deepEqual(paths, ['/api/email', '/api/messages', '/api/emails'])
})

test('unavailable domains explain why an old session can no longer get an address', async (t) => {
    let available = true
    t.mock.method(globalThis, 'fetch', async (url: string) => {
        if (url === '/api/email') return available ? address(first) : Response.json({ code: 'no_issuable_domains' }, { status: 503 })
        return list(first.id)
    })
    const store = useEmailStore()
    await store.refresh()
    available = false
    await store.refresh()
    assert.equal(store.mailbox, null)
    assert.deepEqual(store.messages, [])
    assert.equal(store.issuancePaused, false)
    assert.match(store.error, /домен/iu)
})

test('an exhausted generator lets the guest retry with a different format on the same mailbox', async (t) => {
    t.mock.method(globalThis, 'fetch', async (url: string) => {
        if (url === '/api/emails') return Response.json({ code: 'mailbox_candidates_exhausted' }, { status: 503 })
        return url === '/api/email' ? address(first) : list(first.id)
    })
    const store = useEmailStore()
    await store.refresh()
    assert.match(await store.rotate({ format: 'words' }) ?? '', /формат/iu)
    assert.deepEqual(store.mailbox, first)
    assert.deepEqual(store.messages, [message])
})

test('a lost rotation response reads the server mailbox without repeating the POST', async (t) => {
    let current = first
    const requests: string[] = []
    t.mock.method(globalThis, 'fetch', async (url: string, init: RequestInit) => {
        requests.push(init.method + ' ' + url)
        if (url === '/api/emails') {
            current = second
            throw new TypeError('Network error')
        }
        return url === '/api/email' ? address(current, 'same-count-and-oldest') : list(current.id)
    })
    const store = useEmailStore()
    await store.refresh()
    await store.rotate({ format: 'words' })
    assert.deepEqual(requests, ['GET /api/email', 'GET /api/messages', 'POST /api/emails', 'GET /api/email', 'GET /api/messages'])
    assert.deepEqual(store.mailbox, second)
})

test('a transient failure reading the mailbox after rotation can be retried with GET', async t => {
    const requests: string[] = []
    Object.defineProperty(globalThis, 'window', { configurable: true, value: new EventTarget() })
    t.after(() => { Reflect.deleteProperty(globalThis, 'window') })
    const signals = t.mock.method(BroadcastChannel.prototype, 'postMessage', () => {})
    let current = first
    let failRead = false
    t.mock.method(globalThis, 'fetch', async (path: string, init: RequestInit) => {
        requests.push(init.method + ' ' + path)
        if (path === '/api/emails') {
            current = second
            failRead = true
            throw new TypeError('Lost response')
        }
        if (path === '/api/email' && failRead) {
            failRead = false
            return new Response(null, { status: 503 })
        }
        return path === '/api/email' ? address(current) : list(current.id)
    })
    const store = useEmailStore()
    await store.refresh()
    requests.length = 0
    await store.rotate()
    assert.equal(store.mailbox, null)
    assert.equal(store.bootstrapFailure, 'recovering')
    assert.equal(signals.mock.callCount(), 0)
    await store.refresh()
    assert.deepEqual(store.mailbox, second)
    assert.equal(store.bootstrapFailure, null)
    assert.equal(signals.mock.callCount(), 1)
    assert.deepEqual(requests, ['POST /api/emails', 'GET /api/email', 'GET /api/email', 'GET /api/messages'])
})

test('a late list response cannot restore an open letter after a confirmed rotation', async (t) => {
    let current = first
    let holdNext = false
    const started = Promise.withResolvers<void>()
    const late = Promise.withResolvers<Response>()
    t.mock.method(globalThis, 'fetch', async (url: string) => {
        if (url === '/api/emails') current = second
        if (url !== '/api/messages') return address(current)
        if (holdNext) {
            holdNext = false
            started.resolve()
            return late.promise
        }
        return list(current.id, current.id === first.id ? [message] : [])
    })
    const store = useEmailStore()
    await store.refresh()
    store.selectedId = message.id
    holdNext = true
    const refresh = store.refresh()
    await started.promise
    const rotation = store.rotate()
    assert.equal(store.selectedId, null)
    late.resolve(list(first.id))
    await Promise.all([refresh, rotation])
    assert.equal(store.mailbox?.id, second.id)
    assert.deepEqual(store.messages, [])
    assert.equal(store.selectedId, null)
})

test('a session rotated in another tab never shows its letters under the previous address', async (t) => {
    let reads = 0
    t.mock.method(globalThis, 'fetch', async (url: string) => {
        if (url === '/api/email') return address(++reads === 1 ? first : second)
        return list(second.id)
    })
    const store = useEmailStore()
    await store.refresh()
    assert.equal(store.mailbox?.id, second.id)
    assert.deepEqual(store.messages, [message])
})

test('expiry clears cached mail and selection even while offline', async (t) => {
    t.mock.method(globalThis, 'fetch', async (url: string) => url === '/api/email' ? address(first) : list(first.id))
    const store = useEmailStore()
    await store.refresh()
    store.selectedId = message.id
    assert.equal(store.expire(Date.parse(first.expires_at)), true)
    assert.equal(store.mailbox, null)
    assert.equal(store.selectedId, null)
    assert.deepEqual(store.messages, [])
})

test('an offline mailbox closes an open letter at its server-provided deadline', async (t) => {
    const delivered = { ...message, received_at: '2026-01-01T00:00:00Z', expires_at: '2026-01-08T12:15:00Z' }
    const fresh = { ...delivered, id: message.id + 1, expires_at: '2026-01-09T00:00:00Z' }
    t.mock.method(globalThis, 'fetch', async (url: string) => {
        if (url === '/api/email') return Response.json({ data: { ...first, expires_at: null } })
        if (url === '/api/messages') return list(first.id, [fresh, delivered])
        return Response.json({ data: { ...delivered, body_text: 'Cached body', attachments: [] } },
            { headers: { 'X-Mailbox-Id': String(first.id) } })
    })
    const store = useEmailStore()
    await store.refresh()
    await store.openMessage(delivered.id)
    const limit = Date.parse(delivered.expires_at)
    store.expire(limit - 1)
    assert.equal(store.detail?.body_text, 'Cached body')
    store.expire(limit)
    assert.equal(store.mailbox?.id, first.id)
    assert.deepEqual(store.messages, [fresh])
    assert.equal(store.selectedId, null)
    assert.equal(store.detail, null)
})

test('loads older summaries once and keeps their cursor when new mail arrives', async (t) => {
    let newMail = false
    const item = (id: number) => ({ ...message, id })
    t.mock.method(globalThis, 'fetch', async (url: string) => {
        if (url === '/api/email') return address(first)
        if (url === '/api/messages?cursor=older%2B') return list(first.id, [item(2), item(1)])
        if (url === '/api/messages?cursor=page2') return list(first.id, [item(3), item(2)], 'page3')
        if (url === '/api/messages?cursor=page3') return list(first.id, [item(1)])
        return newMail ? list(first.id, [item(5), item(4)], 'page2') : list(first.id, [item(4), item(3)], 'older+')
    })
    const store = useEmailStore()
    await store.refresh()
    assert.equal(store.nextCursor, 'older+')
    await store.loadMore()
    assert.deepEqual(store.messages.map(m => m.id), [4, 3, 2, 1])
    newMail = true
    await store.refresh()
    assert.deepEqual(store.messages.map(m => m.id), [5, 4, 3, 2, 1])
    assert.equal(store.nextCursor, null)
})

test('an arriving letter cannot close a message being read at the end of the first page', async (t) => {
    let newMail = false
    const item = (id: number) => ({ ...message, id })
    t.mock.method(globalThis, 'fetch', async (url: string) => {
        if (url === '/api/email') return address(first)
        if (url === '/api/messages/3') return detail(3)
        if (url === '/api/messages?cursor=page2') return list(first.id, [item(3), item(2)], 'page3')
        return list(first.id, newMail ? [item(5), item(4)] : [item(4), item(3)], 'page2')
    })
    const store = useEmailStore()
    await store.refresh()
    await store.openMessage(3)
    newMail = true
    await store.refresh()
    assert.equal(store.selectedId, 3)
    assert.equal(store.detail?.id, 3)
    assert.ok(store.messages.some(row => row.id === 3))
})

test('selecting a letter during a pending list request keeps it open across a page boundary', async (t) => {
    const started = Promise.withResolvers<void>()
    const updatedPage = Promise.withResolvers<Response>()
    const requests: string[] = []
    const item = (id: number) => ({ ...message, id })
    let updating = false
    t.mock.method(globalThis, 'fetch', async (url: string, options: RequestInit) => {
        requests.push(options.method + ' ' + url)
        if (url === '/api/email') return address(first)
        if (url === '/api/messages/4') return detail(4)
        if (url === '/api/messages?cursor=next') return list(first.id, [item(4), item(3)])
        if (!updating) return list(first.id, [item(5), item(4)], 'older')
        started.resolve()
        return updatedPage.promise
    })
    const store = useEmailStore()
    await store.refresh()
    requests.length = 0
    updating = true
    const refreshing = store.refresh()
    await started.promise
    await store.openMessage(4)
    updatedPage.resolve(list(first.id, [item(6), item(5)], 'next'))
    await refreshing
    assert.equal(store.selectedId, 4)
    assert.equal(store.detail?.id, 4)
    assert.ok(store.messages.some(row => row.id === 4))
    assert.deepEqual(requests, ['GET /api/email', 'GET /api/messages', 'GET /api/messages/4', 'GET /api/messages?cursor=next'])
})

test('refreshes expanded pages when a delayed letter arrives below the first page', async (t) => {
    let delivered = false
    const item = (id: number) => ({ ...message, id })
    t.mock.method(globalThis, 'fetch', async (url: string) => {
        if (url === '/api/email') return address(first, delivered ? 'after-delivery' : 'before-delivery')
        if (url === '/api/messages') return list(first.id, [item(5), item(4)], 'older')
        if (url === '/api/messages?cursor=older') {
            return delivered ? list(first.id, [item(3), item(2)], 'last') : list(first.id, [item(3), item(1)])
        }
        return list(first.id, [item(1)])
    })
    const store = useEmailStore()
    await store.refresh()
    await store.loadMore()
    delivered = true
    await store.refresh()
    assert.deepEqual(store.messages.map(m => m.id), [5, 4, 3, 2, 1])
    assert.equal(store.nextCursor, null)
})

test('checking an unchanged 100-page inbox keeps the open letter without rereading the pages', async (t) => {
    const requests: string[] = []
    t.mock.method(globalThis, 'fetch', async (url: string) => {
        requests.push(url)
        if (url === '/api/email') return address(first, 'unchanged')
        if (url === '/api/messages/1') return detail(1)
        const start = Number(new URL(url, 'http://localhost').searchParams.get('cursor') ?? 3000)
        const page = Array.from({ length: 30 }, (_, index) => ({ ...message, id: start - index }))
        return list(first.id, page, start > 30 ? String(start - 30) : null)
    })
    const store = useEmailStore()
    await store.refresh()
    while (store.nextCursor) await store.loadMore()
    await store.openMessage(1)
    requests.length = 0

    await store.refresh()
    assert.equal(store.messages.length, 3000)
    assert.equal(store.detail?.id, 1)
    assert.deepEqual(requests, ['/api/email'])
})

test('anchors the mailbox clock to server time even when the device clock is far ahead', async (t) => {
    const clock = '2026-01-01T17:00:00Z'
    t.mock.method(Date, 'now', () => Date.parse('2099-01-02T11:30:00Z'))
    t.mock.method(globalThis, 'fetch', async (url: string) => url === '/api/email'
        ? Response.json({ data: { ...first, expires_at: '2026-01-01T17:02:00Z' } }, { headers: { 'X-Server-Time': clock } })
        : list(first.id))
    const store = useEmailStore()
    await store.refresh()
    assert.ok(Math.abs(store.serverNow() - Date.parse(clock)) < 1500)
    assert.equal(store.expire(store.serverNow()), false)
})

test('a fresh server clock restores retained mail after a forward jump of the device clock', async (t) => {
    const serverTime = '2026-01-01T00:00:00Z'
    const letter = { ...message, received_at: serverTime, expires_at: '2026-01-03T00:00:00Z' }
    let deviceTime = Date.parse(serverTime)
    t.mock.method(Date, 'now', () => deviceTime)
    t.mock.method(globalThis, 'fetch', async (url: string) => url === '/api/email'
        ? Response.json({ data: { ...first, expires_at: null } }, {
            headers: { 'X-Server-Time': serverTime, 'X-Inbox-Snapshot': 'unchanged' },
        }) : list(first.id, [letter]))
    const store = useEmailStore()
    await store.refresh()
    assert.deepEqual(store.messages, [letter])

    deviceTime += 366 * 86400000
    store.expire(store.serverNow())
    assert.deepEqual(store.messages, [])
    await store.refresh()
    assert.deepEqual(store.messages, [letter])
})

test('keeps an issued mailbox accessible and explains the pause after a rejected rotation', async (t) => {
    const paused = () => Response.json({ code: 'guest_issuance_paused', message: 'Выдача временных адресов приостановлена.' }, { status: 503 })
    t.mock.method(globalThis, 'fetch', async (url: string) => {
        if (url === '/api/emails') return paused()
        return url === '/api/email' ? address(first) : list(first.id)
    })
    const store = useEmailStore()
    await store.refresh()
    await store.rotate()
    assert.equal(store.mailbox?.id, first.id)
    assert.deepEqual(store.messages, [message])
    assert.match(store.rotationError, /приостановлена/)
})

test('a failed change of a guest address does not leave an alert on the next mailbox', async (t) => {
    let ownerLoggedIn = false
    t.mock.method(globalThis, 'fetch', async (url: string) => {
        if (url === '/api/emails') return Response.json({ code: 'guest_issuance_paused' }, { status: 503 })
        if (url === '/api/email') return Response.json({ data: ownerLoggedIn ? { ...second, expires_at: null } : first })
        return list(ownerLoggedIn ? second.id : first.id)
    })
    const store = useEmailStore()
    await store.refresh()
    await store.rotate()
    assert.match(store.rotationError, /приостановлена/)
    ownerLoggedIn = true
    await store.refresh()
    assert.equal(store.mailbox?.id, second.id)
    assert.equal(store.rotationError, '')
})

test('reports an administrative pause to a new visitor', async (t) => {
    t.mock.method(globalThis, 'fetch', async () => Response.json({ code: 'guest_issuance_paused' }, { status: 503 }))
    const store = useEmailStore()
    await store.refresh()
    assert.equal(store.issuancePaused, true)
    assert.match(store.error, /приостановлена/)
})

test('clears the previous owner and open letter when the new guest session cannot get an address', async (t) => {
    let revoked = false
    t.mock.method(globalThis, 'fetch', async (url: string) => {
        if (url === '/api/email') return revoked
            ? Response.json({ code: 'guest_issuance_quota_exceeded' }, { status: 429 })
            : Response.json({ data: { ...first, expires_at: null } })
        return url === '/api/messages' ? list(first.id) : detail(message.id)
    })
    const store = useEmailStore()
    await store.refresh()
    await store.openMessage(message.id)
    assert.equal(store.detail?.id, message.id)

    revoked = true
    await store.refresh()
    assert.equal(store.mailbox, null)
    assert.deepEqual(store.messages, [])
    assert.equal(store.selectedId, null)
    assert.equal(store.detail, null)
    assert.match(store.error, /лимит/i)
})

test('restores an active guest mailbox when its rotation exceeds the issuance quota', async (t) => {
    t.mock.method(globalThis, 'fetch', async (url: string) => {
        if (url === '/api/emails') return Response.json({ code: 'guest_issuance_quota_exceeded' }, { status: 429 })
        return url === '/api/email' ? address(first) : list(first.id)
    })
    const store = useEmailStore()
    await store.refresh()
    await store.rotate()
    assert.equal(store.mailbox?.id, first.id)
    assert.deepEqual(store.messages, [message])
    assert.match(store.rotationError, /лимит/i)
})

test('loads one selected body on demand and ignores an older selection resolving later', async (t) => {
    const late = Promise.withResolvers<Response>()
    let firstSignal: AbortSignal | null | undefined
    const requests: string[] = []
    t.mock.method(globalThis, 'fetch', async (url: string, options: RequestInit) => {
        requests.push(url)
        if (url === '/api/email') return address(first)
        if (url === '/api/messages') return list(first.id, [message, { ...message, id: 3 }])
        if (url.endsWith('/4')) {
            firstSignal = options.signal
            return late.promise
        }
        return detail(3)
    })
    const store = useEmailStore()
    await store.refresh()
    assert.deepEqual(requests, ['/api/email', '/api/messages'])
    const firstRead = store.openMessage(4)
    await store.openMessage(3)
    assert.equal(firstSignal?.aborted, true)
    late.resolve(detail(4))
    await firstRead
    assert.equal(store.detail?.id, 3)
    assert.equal(store.detail?.body_text, 'Full text')
    assert.equal(store.messages.some(m => 'body_text' in m), false)
    store.closeMessage()
    assert.equal(store.detail, null)
})

test('a stale rotation sends the confirmed mailbox id and reads the newer address without rotating again', async (t) => {
    const rotations: (string | null)[] = []
    let current = first
    t.mock.method(globalThis, 'fetch', async (url: string, options: RequestInit) => {
        if (url === '/api/emails') {
            rotations.push(new Headers(options.headers).get('X-Expected-Mailbox-Id'))
            return new Response(null, { status: 409 })
        }
        return url === '/api/email' ? address(current) : list(current.id, [])
    })
    const store = useEmailStore()
    await store.refresh()
    current = second
    await store.rotate()
    assert.deepEqual(rotations, [String(first.id)])
    assert.equal(store.mailbox?.id, second.id)
    assert.equal(store.error, '')
    assert.match(store.rotationError, /другой вкладке/iu)
})

test('new arrivals preserve the expanded boundary and load the next page without skipping older messages', async (t) => {
    const ids = [8, 7, 6, 5, 4, 3, 2, 1]
    t.mock.method(globalThis, 'fetch', async (url: string) => {
        if (url === '/api/email') return address(first)
        const cursor = new URL(url, 'http://localhost').searchParams.get('cursor')
        const start = cursor ? ids.indexOf(Number(cursor)) + 1 : 0
        const page = ids.slice(start, start + 2).map(id => ({ ...message, id }))
        return list(first.id, page, start + page.length < ids.length ? String(page.at(-1)?.id) : null)
    })
    const store = useEmailStore()
    await store.refresh()
    await store.loadMore()
    assert.deepEqual(store.messages.map(row => row.id), [8, 7, 6, 5])
    ids.unshift(9)
    await store.refresh()
    assert.deepEqual(store.messages.map(row => row.id), [9, 8, 7, 6, 5])
    ids.unshift(10)
    await store.refresh()
    assert.deepEqual(store.messages.map(row => row.id), [10, 9, 8, 7, 6, 5])
    await store.loadMore()
    assert.deepEqual(store.messages.map(row => row.id), [10, 9, 8, 7, 6, 5, 4, 3])
})

test('opens a message when the browser has no AbortSignal.any', async (t) => {
    t.mock.method(AbortSignal, 'any', () => { throw new TypeError('AbortSignal.any is unavailable') })
    t.mock.method(globalThis, 'fetch', async (url: string) => {
        if (url === '/api/email') return address(first)
        return url === '/api/messages' ? list(first.id) : detail(message.id)
    })
    const store = useEmailStore()
    await store.refresh()
    await store.openMessage(message.id)
    assert.equal(store.detail?.id, message.id)
})

test('a sleeping offline device drops an expired mailbox even when its monotonic clock was suspended', async (t) => {
    const serverTime = '2026-01-01T00:00:00Z'
    let deviceTime = Date.parse('2099-09-01T00:00:00Z')
    t.mock.method(Date, 'now', () => deviceTime)
    t.mock.method(performance, 'now', () => 1000)
    t.mock.method(globalThis, 'fetch', async (url: string) => url === '/api/email'
        ? Response.json({ data: { ...first, expires_at: '2026-01-02T00:00:00Z' } }, { headers: { 'X-Server-Time': serverTime } })
        : list(first.id))
    const store = useEmailStore()
    await store.refresh()
    assert.equal(store.expire(store.serverNow()), false)
    deviceTime += 24 * 60 * 60 * 1000
    assert.equal(store.expire(store.serverNow()), true)
    assert.equal(store.mailbox, null)
    assert.deepEqual(store.messages, [])
})

for (const revokedEndpoint of ['/api/email', '/api/messages', '/api/messages/4', '/api/messages?cursor=older']) {
    test('HTTP 401 at ' + revokedEndpoint + ' clears the previous owner and cached mail', async (t) => {
        let revoked = false
        t.mock.method(globalThis, 'fetch', async (url: string) => {
            if (revoked && url === revokedEndpoint) return new Response(null, { status: 401 })
            if (url === '/api/email') return Response.json({ data: { ...first, expires_at: null } }, { headers: { 'X-Panel-Url': '/admin' } })
            return url === '/api/messages/4' ? detail(4) : list(first.id, [message], 'older')
        })
        const store = useEmailStore()
        await store.refresh()
        await store.openMessage(4)
        assert.equal(store.detail?.id, 4)
        assert.equal(store.panelUrl, '/admin')
        revoked = true
        if (revokedEndpoint.includes('?')) await store.loadMore()
        else if (revokedEndpoint.endsWith('/4')) await store.openMessage(4)
        else await store.refresh()
        assert.equal(store.mailbox, null)
        assert.equal(store.detail, null)
        assert.equal(store.panelUrl, null)
        assert.deepEqual(store.messages, [])
    })
}

test('a failed network refresh preserves the readable cached message', async (t) => {
    let offline = false
    t.mock.method(globalThis, 'fetch', async (url: string) => {
        if (offline) throw new TypeError('Failed to fetch')
        if (url === '/api/email') return address(first)
        return url === '/api/messages' ? list(first.id) : detail(message.id)
    })
    const store = useEmailStore()
    await store.refresh()
    await store.openMessage(message.id)
    offline = true
    await store.refresh()
    assert.equal(store.mailbox?.id, first.id)
    assert.equal(store.detail?.id, message.id)
    assert.deepEqual(store.messages, [message])
    assert.match(store.error, /соединение/)
})

test('a pending list cannot restore cached mail after a concurrent request reports a revoked session', async (t) => {
    const pendingList = Promise.withResolvers<Response>()
    const listStarted = Promise.withResolvers<void>()
    let revoked = false
    t.mock.method(globalThis, 'fetch', async (url: string) => {
        if (url === '/api/email') return address(first)
        if (!revoked) return list(first.id)
        if (url === '/api/messages/4') return new Response(null, { status: 401 })
        listStarted.resolve()
        return pendingList.promise
    })
    const store = useEmailStore()
    await store.refresh()
    revoked = true
    const refreshing = store.refresh()
    await listStarted.promise
    await store.openMessage(message.id)
    pendingList.resolve(list(first.id))
    await refreshing
    assert.equal(store.mailbox, null)
    assert.deepEqual(store.messages, [])
})

test('an HTTP revocation drops cached choices and discards an outstanding options response', async t => {
    t.mock.timers.enable({ apis: ['Date'], now: Date.UTC(2026, 0, 1) })
    const pendingOptions = Promise.withResolvers<Response>()
    const choices = [{ domain: 'mail.test', formats: ['random'] }]
    let optionsReads = 0
    t.mock.method(globalThis, 'fetch', async (path: string) => {
        if (path === '/api/email') return address(first)
        if (path === '/api/email/options') return ++optionsReads === 1 ? Response.json({ data: choices }) : pendingOptions.promise
        if (path === '/api/messages/4') return new Response(null, { status: 401 })
        return list(first.id)
    })
    const store = useEmailStore()
    await store.refresh()
    await store.loadOptions()
    assert.deepEqual(store.domainOptions, choices)
    t.mock.timers.tick(60 * 60 * 1000)
    const late = store.loadOptions()
    assert.equal(optionsReads, 2)
    await store.openMessage(4)
    assert.deepEqual(store.domainOptions, [])
    assert.equal(store.optionsLoading, false)
    assert.equal(store.bootstrapFailure, 'denied')
    pendingOptions.resolve(Response.json({ data: choices }))
    await late
    assert.deepEqual(store.domainOptions, [])
})

test('a late HTTP 401 from a previous session leaves the new mailbox accessible', async t => {
    const late = Promise.withResolvers<Response>()
    let current = first
    t.mock.method(globalThis, 'fetch', async (path: string) => {
        if (path === '/api/email/options') return late.promise
        return path === '/api/email' ? address(current) : list(current.id)
    })
    const store = useEmailStore()
    await store.refresh()
    const oldOptions = store.loadOptions()
    store.resetSession()
    current = second
    await store.refresh()
    late.resolve(new Response(null, { status: 401 }))
    await oldOptions
    assert.deepEqual(store.mailbox, second)
    assert.equal(store.bootstrapFailure, null)
})
