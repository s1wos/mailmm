import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPinia } from 'pinia'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useEmailStore } from '../../resources/js/stores/emailStore.ts'
import { loadComponent } from './vueFixture.ts'

test('only signed-in mailbox navigation links back to the panel', async () => {
    const [layout, card, about, help] = await Promise.all([
        '/resources/js/layout/AppLayout.vue', '/resources/js/components/AddressCard.vue',
        '/resources/js/pages/About.vue', '/resources/js/pages/Help.vue',
    ].map(loadComponent))
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', component: card, props: { now: Date.now() } },
            { path: '/about', component: about },
            { path: '/help', component: help },
            { path: '/admin', component: {} },
        ],
    })
    const cases = [
        { path: '/', expected: 'Ваш временный адрес', expires: '2099-01-01', panelUrl: null, showLink: false },
        { path: '/', expected: 'Ваш постоянный адрес', expires: null, panelUrl: null, showLink: false },
        { path: '/', expected: 'Ваш постоянный адрес', expires: null, panelUrl: '/admin', showLink: true },
        { path: '/', expected: 'Ваш временный адрес', expires: '2099-01-01', panelUrl: '/admin', showLink: true },
        { path: '/about', expected: 'Хранение писем', expires: null, panelUrl: null, showLink: false },
        { path: '/help', expected: 'Как получить письмо?', expires: null, panelUrl: null, showLink: false },
        { path: '/about', expected: 'Хранение писем', expires: null, panelUrl: '/admin', showLink: false },
        { path: '/help', expected: 'Как получить письмо?', expires: null, panelUrl: '/admin', showLink: false },
    ]
    for (const { path, expected, expires, panelUrl, showLink } of cases) {
        const app = createSSRApp(layout)
        const pinia = createPinia()
        app.use(pinia)
        app.use(router)
        const store = useEmailStore(pinia)
        store.mailbox = { id: 1, email: 'mail@example.test', expires_at: expires }
        store.panelUrl = panelUrl
        await router.push(path)
        const html = await renderToString(app)
        assert.ok(html.includes(expected) && html.includes('Помощь') && html.includes('О сервисе'))
        if (path === '/' && expires === null) assert.doesNotMatch(html, /<dialog|Сменить адрес/u)
        if (path === '/' && expires !== null) assert.match(html, /Случайный домен/u)
        if (showLink) {
            assert.match(html, /<a\b[^>]*href="\/admin"[^>]*>.*?Вернуться в панель\s*<\/a>/su)
        } else {
            assert.doesNotMatch(html, /href=["']\/admin(?:[/"'?#])|Вернуться в панель|личн(?:ый|ом) кабинет/iu)
        }
    }
})
