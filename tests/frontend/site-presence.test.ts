import assert from 'node:assert/strict'
import { test } from 'node:test'
import { setImmediate as flush } from 'node:timers/promises'
import { trackSitePresence } from '../../resources/js/sitePresence.ts'

test('a tab joins only while visible and leaves on pagehide and unmount', t => {
    const doc = Object.assign(new EventTarget(), { hidden: true })
    const browser = new EventTarget()
    Object.assign(globalThis, { document: doc, window: browser })
    t.after(() => {
        Reflect.deleteProperty(globalThis, 'document')
        Reflect.deleteProperty(globalThis, 'window')
    })
    const subscriptions = new Set<string>()
    const connection = {
        private(name: string) {
            assert.ok(!subscriptions.has(name))
            subscriptions.add(name)
            return { subscribed() { return this }, error() {} }
        },
        leave(name: string) { assert.ok(subscriptions.delete(name)) },
    }
    const stop = trackSitePresence(connection, 'site-visitor.123')
    assert.equal(subscriptions.size, 0)
    doc.hidden = false
    doc.dispatchEvent(new Event('visibilitychange'))
    browser.dispatchEvent(new Event('pageshow'))
    assert.deepEqual([...subscriptions], ['site-visitor.123'])
    doc.hidden = true
    doc.dispatchEvent(new Event('visibilitychange'))
    assert.equal(subscriptions.size, 0)
    doc.hidden = false
    doc.dispatchEvent(new Event('visibilitychange'))
    browser.dispatchEvent(new Event('pagehide'))
    assert.equal(subscriptions.size, 0)
    browser.dispatchEvent(new Event('pageshow'))
    assert.equal(subscriptions.size, 1)
    stop()
    browser.dispatchEvent(new Event('pageshow'))
    assert.equal(subscriptions.size, 0)
})

test('an already open tab rejoins the identity shared by the browser after its cookie changes', async t => {
    Object.assign(globalThis, { document: Object.assign(new EventTarget(), { hidden: false, cookie: 'XSRF-TOKEN=test' }), window: new EventTarget() })
    t.after(() => {
        Reflect.deleteProperty(globalThis, 'document')
        Reflect.deleteProperty(globalThis, 'window')
    })
    const initial = 'site-visitor.' + '1'.repeat(32)
    const current = 'site-visitor.' + '2'.repeat(32)
    t.mock.method(globalThis, 'fetch', async () => Response.json({ visitorChannel: current }, { status: 403 }))
    let denied!: (error: { status: number }) => void
    const subscriptions = new Set<string>()
    const connection = {
        socketId: () => '1.1',
        private(name: string) {
            subscriptions.add(name)
            return { subscribed() { return this }, error(handler: typeof denied) { denied = handler } }
        },
        leave(name: string) { subscriptions.delete(name) },
    }
    const stop = trackSitePresence(connection, initial)
    try {
        denied({ status: 403 })
        await flush()
        assert.deepEqual([...subscriptions], [current])
    } finally {
        stop()
    }
})

test('two visible first visits that each joined successfully converge on the last visitor cookie', async t => {
    Object.assign(globalThis, { document: Object.assign(new EventTarget(), { hidden: false, cookie: 'XSRF-TOKEN=test' }), window: new EventTarget() })
    t.after(() => {
        Reflect.deleteProperty(globalThis, 'document')
        Reflect.deleteProperty(globalThis, 'window')
    })
    const first = 'site-visitor.' + '1'.repeat(32)
    const second = 'site-visitor.' + '2'.repeat(32)
    let cookie = first
    t.mock.method(globalThis, 'fetch', async () => Response.json({ visitorChannel: cookie }, { status: 403 }))
    const tab = () => {
        const joined = new Set<string>()
        return {
            joined,
            socketId: () => '1.1',
            private(name: string) {
                const allowed = name === cookie
                if (allowed) joined.add(name)
                const channel = {
                    subscribed(ready: () => void) { if (allowed) queueMicrotask(ready); return channel },
                    error(denied: (error: { status: number }) => void) {
                        if (!allowed) queueMicrotask(() => denied({ status: 403 }))
                        return channel
                    },
                }
                return channel
            },
            leave(name: string) { joined.delete(name) },
        }
    }
    const a = tab()
    const b = tab()
    const closeFirst = trackSitePresence(a, first)
    await flush()
    assert.deepEqual([...a.joined], [first])
    cookie = second
    const closeSecond = trackSitePresence(b, second)
    try {
        await t.waitFor(() => {
            assert.deepEqual([...a.joined], [second])
            assert.deepEqual([...b.joined], [second])
        }, { timeout: 1000, interval: 5 })
    } finally {
        closeFirst()
        closeSecond()
    }
})
