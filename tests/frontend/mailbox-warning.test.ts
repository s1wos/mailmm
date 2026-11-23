import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPinia } from 'pinia'
import { createSSRApp } from 'vue'
import { renderToString, type SSRContext } from 'vue/server-renderer'
import { useEmailStore } from '../../resources/js/stores/emailStore.ts'
import { loadComponent } from './vueFixture.ts'

test('a rejected address change shows one warning outside the mailbox layout', async (t) => {
    t.mock.getter(globalThis, 'navigator', () => ({ onLine: true }) as Navigator)
    const home = await loadComponent('/resources/js/pages/Home.vue')
    const app = createSSRApp(home)
    const pinia = createPinia()
    app.use(pinia)
    const store = useEmailStore(pinia)
    const quotaMessage = 'Лимит выдачи адресов исчерпан. Повторите позже.'
    store.mailbox = { id: 1, email: 'guest@mail.test', expires_at: '2099-01-02T00:00:00Z' }
    store.checkedAt = Date.now()
    store.rotationError = quotaMessage

    const context: SSRContext = {}
    const mailbox = await renderToString(app, context)
    const notifications = Object.values(context.teleports ?? {}).join('')
    assert.ok(!mailbox.includes(quotaMessage), 'the warning must not expand the mailbox layout')
    assert.equal(notifications.split(quotaMessage).length - 1, 1)
    assert.match(notifications, /Текущий адрес доступен/u)
    assert.match(notifications, /role="alert"/u)
})
