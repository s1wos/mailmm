import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPinia, setActivePinia } from 'pinia'
import { useEmailStore } from '../../resources/js/stores/emailStore.ts'
import { listenForMailboxSessionChange, MailboxSocket, notifyMailboxSessionChanged, SocketError } from '../../resources/js/mailboxSocket.ts'

test('a changed socket snapshot refreshes expanded pages atomically without HTTP', async t => {
    setActivePinia(createPinia())
    const store = useEmailStore()
    const row = (id: number) => ({ id, from: 'sender', subject: String(id), received_at: '2026-11-20T00:00:00Z', expires_at: '2027-11-20T00:00:00Z', has_attachments: false })
    const page = (ids: number[], cursor: string | null) => ({ data: ids.map(row), meta: { next_cursor: cursor } })
    const box = { id: 1, email: 'guest@example.test', expires_at: null }
    let allowHttp = true
    const calls: string[] = []
    t.mock.method(globalThis, 'fetch', (url: string) => {
        if (!allowHttp) calls.push(url)
        if (url === '/api/email') return Response.json({ data: box }, { headers: { 'X-Inbox-Snapshot': 'old' } })
        return Response.json(url.includes('?') ? page([2, 1], null) : page([4, 3], 'older'), { headers: { 'X-Mailbox-Id': '1' } })
    })
    await store.refresh()
    await store.loadMore()
    allowHttp = false
    const originalTime = store.checkedAt
    const snapshots: (string | null)[] = []
    let fail = true
    const reply = { mailboxId: 1, snapshot: 'new', serverTime: new Date().toISOString(), unchanged: false,
        grantExpiresAt: '2099-01-01T00:00:00Z', requestId: 'test' }
    const socket = {
        mailboxId: 1, active: true,
        async check(snapshot: string | null) {
            snapshots.push(snapshot)
            return { ...reply, page: page([5, 4, 3], 'next'), continuation: 'ticket' }
        },
        async page(token: string) {
            assert.equal(token, 'ticket')
            if (fail) throw new Error('connection lost')
            return { ...reply, page: page([2, 1], null) }
        },
    }
    await assert.rejects(store.checkSocket(socket, 'manual'), /connection lost/)
    assert.deepEqual(store.messages.map(row => row.id), [4, 3, 2, 1])
    assert.equal(store.checkedAt, originalTime)
    fail = false
    assert.equal(await store.checkSocket(socket, 'manual'), true)
    assert.deepEqual(snapshots, ['old', 'old'])
    assert.deepEqual(store.messages.map(row => row.id), [5, 4, 3, 2, 1])
    assert.deepEqual(calls, [])
})

test('socket commands match only their own response, including a split Unicode payload', async () => {
    const frames: { event: string, data: Record<string, unknown> }[] = []
    const socket = new MailboxSocket(5, (event, data) => { frames.push({ event, data }); return true })
    const check = socket.check('previous', 'auto', 92)
    const more = socket.page('continuation')
    assert.deepEqual(frames.map(frame => frame.event), ['mailbox.check', 'mailbox.page'])
    assert.equal(frames[0].data.deliveryId, 92)
    const requestId = frames[0].data.requestId
    const result = { requestId, mailboxId: 5, serverTime: '2026-01-01T00:00:00Z', grantExpiresAt: '2026-01-02T00:00:00Z',
        snapshot: 'снимок', unchanged: true }
    socket.receive('checked', { ...result, requestId: 'old' })
    const text = JSON.stringify(result)
    socket.receive('chunk', { requestId, index: 0, total: 2, content: text.slice(0, 20) })
    socket.receive('chunk', { requestId, index: 1, total: 2, content: text.slice(20) })
    assert.deepEqual(await check, result)
    socket.receive('error', { requestId: frames[1].data.requestId, code: 'rate_limited', retryAfter: 4 })
    await assert.rejects(more, (e: SocketError) => e.code === 'rate_limited' && e.retryAfter === 4)
})

test('an expired or disconnected socket cannot repopulate cached mail', async t => {
    setActivePinia(createPinia())
    const store = useEmailStore()
    const box = { id: 1, email: 'owner@example.test', expires_at: null }
    let http = 0
    t.mock.method(globalThis, 'fetch', (url: string) => {
        http++
        return url === '/api/email'
            ? Response.json({ data: box }, { headers: { 'X-Inbox-Snapshot': 'a', 'X-Mailmm-Is-Admin': '1', 'X-Panel-Url': '/admin' } })
            : Response.json({ data: [], meta: { next_cursor: null } }, { headers: { 'X-Mailbox-Id': '1' } })
    })
    await store.refresh()
    const started = Promise.withResolvers<string>()
    const socket = new MailboxSocket(1, (_event, data) => { started.resolve(String(data.requestId)); return true })
    const old = store.checkSocket(socket, 'manual')
    const id = await started.promise
    socket.close()
    assert.equal(await old, false)
    socket.receive('checked', { requestId: id, mailboxId: 1, snapshot: 'late' })
    let deniedId = ''
    const revoked = new MailboxSocket(1, (_event, data) => {
        deniedId = String(data.requestId)
        queueMicrotask(() => revoked.receive('error', { requestId: deniedId, code: 'access_revoked' }))
        return true
    })
    await assert.rejects(store.checkSocket(revoked, 'manual'), { code: 'access_revoked' })
    assert.equal(store.mailbox, null)
    assert.equal(store.checkedAt, null)
    assert.equal(store.isAdmin, false)
    assert.equal(store.panelUrl, null)
    assert.equal(http, 2)
})

test('a confirmed rotation signals the other tab without rebootstrapping the sender', async t => {
    const previous = Object.getOwnPropertyDescriptor(globalThis, 'window')
    Object.defineProperty(globalThis, 'window', { configurable: true, value: new EventTarget() })
    let local = 0
    const stop = listenForMailboxSessionChange(() => local++)
    const peer = new BroadcastChannel('mailmm-session-changed')
    t.after(() => {
        peer.close()
        stop()
        if (previous) Object.defineProperty(globalThis, 'window', previous)
        else Reflect.deleteProperty(globalThis, 'window')
    })
    const delivered = new Promise<void>(resolve => peer.onmessage = () => resolve())
    notifyMailboxSessionChanged()
    await delivered
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(local, 0)
})

for (const allowed of [false, true]) {
    test('a revocation signal hides private data immediately and ' + (allowed ? 'restores a freshly authorized owner' : 'denies a revoked owner without issuing a guest'), async t => {
        setActivePinia(createPinia())
        const store = useEmailStore()
        const box = { id: 12, email: 'owner@example.test', expires_at: null }
        const letter = { id: 7, from: 'sender', subject: 'Private', received_at: '2026-11-21T00:00:00Z', expires_at: '2027-11-21T00:00:00Z', has_attachments: false }
        const http: string[] = []
        t.mock.method(globalThis, 'fetch', (path: string) => {
            http.push(path)
            if (path === '/api/email') return Response.json({ data: box }, { headers: { 'X-Inbox-Snapshot': 'old', 'X-Panel-Url': '/admin' } })
            const data = path === '/api/messages' ? { data: [letter], meta: { next_cursor: null } } : { data: { ...letter, body_text: 'Private body' } }
            return Response.json(data, { headers: { 'X-Mailbox-Id': '12' } })
        })
        await store.refresh()
        await store.openMessage(7)
        http.length = 0
        assert.equal(typeof store.suspendAccess, 'function')
        store.suspendAccess()
        assert.equal(store.socketMailboxId, box.id)
        assert.equal(store.mailbox, null)
        assert.equal(store.panelUrl, null)
        assert.equal(store.detail, null)
        assert.deepEqual(store.messages, [])
        const fresh = new MailboxSocket(box.id, (_event, data) => {
            assert.equal(data.snapshot, null)
            queueMicrotask(() => allowed
                ? fresh.receive('checked', { requestId: data.requestId, mailboxId: box.id, serverTime: new Date().toISOString(),
                    grantExpiresAt: '2099-01-01T00:00:00Z', snapshot: 'old', unchanged: false, page: { data: [letter], meta: { next_cursor: null } } })
                : fresh.receive('error', { requestId: data.requestId, code: 'access_revoked' }))
            return true
        })
        if (allowed) {
            assert.equal(await store.checkSocket(fresh, 'auto'), true)
            assert.deepEqual(store.mailbox, box)
            assert.equal(store.panelUrl, '/admin')
            assert.deepEqual(store.messages, [letter])
            assert.equal(store.detail, null)
        } else {
            await assert.rejects(store.checkSocket(fresh, 'auto'), { code: 'access_revoked' })
            assert.equal(store.socketMailboxId, undefined)
            assert.match(store.error, /Сессия завершена/)
        }
        assert.deepEqual(http, [])
    })
}
