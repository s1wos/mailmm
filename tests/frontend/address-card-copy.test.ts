import assert from 'node:assert/strict'
import { after, before, test, type TestContext } from 'node:test'
import vue from '@vitejs/plugin-vue'
import { createPinia } from 'pinia'
import { createServer, type ViteDevServer } from 'vite'
import { createRenderer, createSSRApp, nextTick, nodeOps, patchProp, ssrContextKey } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { useEmailStore } from '../../resources/js/stores/emailStore.ts'

let vite: ViteDevServer
before(async () => {
    vite = await createServer({ configFile: false, plugins: [vue()], server: { middlewareMode: true, ws: false, watch: null } })
})
after(() => vite.close())

async function mountCard(t: TestContext) {
    const Card = (await vite.ssrLoadModule('/resources/js/components/AddressCard.vue')).default
    const writes: { address: string, finish: () => void }[] = []
    t.mock.getter(globalThis, 'navigator', () => ({ clipboard: { writeText(address: string) {
        const write = Promise.withResolvers<void>()
        writes.push({ address, finish: write.resolve })
        return write.promise
    } } } as Navigator))
    const feedbackTimer = t.mock.fn(() => 1)
    Object.assign(globalThis, { window: { setTimeout: feedbackTimer } })
    const pinia = createPinia()
    const store = useEmailStore(pinia)
    store.mailbox = { id: 1, email: 'first@mail.test', expires_at: null }
    const props = { now: Date.now() }
    let copy!: () => Promise<void> | undefined
    let html!: () => Promise<string>
    const renderer = createRenderer({ ...nodeOps, patchProp, createComment: () => ({} as Comment), insert() {}, remove() {} })
    const app = renderer.createApp({
        setup(_, ctx) {
            const state = Card.setup(props, ctx)
            copy = state.copyAddress
            html = () => renderToString(createSSRApp({ ...Card, setup: () => state }, props).use(pinia))
            return () => null
        },
    }).provide(ssrContextKey, {}).use(pinia)
    app.mount({} as Element)
    let mounted = true
    const unmount = () => {
        if (mounted) app.unmount()
        mounted = false
    }
    t.after(() => { unmount(); Reflect.deleteProperty(globalThis, 'window') })
    return { copy, html, writes, feedbackTimer, store, unmount }
}

test('a pending clipboard write for the previous address cannot lock or confirm the new address', async t => {
    const card = await mountCard(t)
    const oldWrite = card.copy()
    card.store.mailbox = { id: 2, email: 'second@mail.test', expires_at: null }
    await nextTick()
    const newWrite = card.copy()
    assert.deepEqual(card.writes.map(write => write.address), ['first@mail.test', 'second@mail.test'])
    card.writes[0].finish()
    await oldWrite
    assert.doesNotMatch(await card.html(), /Скопировано/u)
    assert.equal(card.feedbackTimer.mock.callCount(), 0)
    card.writes[1].finish()
    await newWrite
    assert.match(await card.html(), /Скопировано/u)
    assert.equal(card.feedbackTimer.mock.callCount(), 1)
})

test('leaving the address card while copying prevents late success feedback and timers', async t => {
    const card = await mountCard(t)
    const pending = card.copy()
    assert.equal(card.writes.length, 1)
    card.unmount()
    card.writes[0].finish()
    await pending
    assert.equal(card.feedbackTimer.mock.callCount(), 0)
})
