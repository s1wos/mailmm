import assert from 'node:assert/strict'
import { test } from 'node:test'
import vue from '@vitejs/plugin-vue'
import { createPinia } from 'pinia'
import { createServer } from 'vite'
import { createSSRApp } from 'vue'
import { renderToString, type SSRContext } from 'vue/server-renderer'
import { useEmailStore } from '../../resources/js/stores/emailStore.ts'

test('a rejected address change shows one warning outside the mailbox layout', async (t) => {
    t.mock.getter(globalThis, 'navigator', () => ({ onLine: true }) as Navigator)
    const vite = await createServer({
        configFile: false,
        plugins: [vue()],
        server: { middlewareMode: true, ws: false, watch: null },
    })
    try {
        const home = (await vite.ssrLoadModule('/resources/js/pages/Home.vue')).default
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
    } finally {
        await vite.close()
    }
})
