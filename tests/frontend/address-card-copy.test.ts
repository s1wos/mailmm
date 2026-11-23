import assert from 'node:assert/strict'
import { test, type TestContext } from 'node:test'
import { createPinia } from 'pinia'
import { nextTick } from 'vue'
import { useEmailStore } from '../../resources/js/stores/emailStore.ts'
import { loadComponent, mountSsrComponent } from './vueFixture.ts'

async function mountCard(t: TestContext) {
    const Card = await loadComponent('/resources/js/components/AddressCard.vue')
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
    const { state, html, unmount } = mountSsrComponent<{ copyAddress: () => Promise<void> | undefined }>(Card, { now: Date.now() }, pinia)
    t.after(() => { unmount(); Reflect.deleteProperty(globalThis, 'window') })
    return { copy: state.copyAddress, html, writes, feedbackTimer, store, unmount }
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
    assert.doesNotMatch(await card.html(), /Адрес скопирован/u)
    assert.equal(card.feedbackTimer.mock.callCount(), 0)
    card.writes[1].finish()
    await newWrite
    assert.match(await card.html(), /Адрес скопирован/u)
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
