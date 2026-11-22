import assert from 'node:assert/strict'
import { after, before, test } from 'node:test'
import vue from '@vitejs/plugin-vue'
import { createServer, type ViteDevServer } from 'vite'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createMemoryHistory, createRouter } from 'vue-router'

let vite: ViteDevServer
before(async () => {
    vite = await createServer({
        configFile: false,
        plugins: [vue()],
        server: { middlewareMode: true, ws: false, watch: null },
    })
})
after(() => vite.close())

const admin = { id: 1, email: 'admin@example.test', is_admin: true }
const owner = { id: 2, email: 'owner@example.test', is_admin: false }
const letter = {
    id: 37, from: '', subject: '', recipient: 'owner@example.test',
    received_at: '2026-11-20T02:59:00+03:00',
}

async function render(screen: string, page: object, url: string) {
    const component = (await vite.ssrLoadModule(`/resources/js/panel/${screen}.vue`)).default
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [{ path: '/:pathMatch(.*)*', component }],
    })
    await router.push(url)
    return renderToString(createSSRApp(component, { page }).use(router))
}

test('a filtered archive keeps the recipient and page when opening a letter and paging', async () => {
    const html = await render('Archive', {
        user: admin, recipient: letter.recipient,
        listQuery: { recipient: letter.recipient, page: 2 },
        messages: {
            data: [letter], current_page: 2, last_page: 3, total: 60,
            prev_page_url: '/admin/archive?recipient=owner%40example.test&page=1',
            next_page_url: '/admin/archive?recipient=owner%40example.test&page=3',
        },
    }, '/admin/archive?recipient=owner@example.test&page=2')
    assert.match(html, /href="\/admin\/messages\/37\?recipient=owner@example\.test&amp;page=2"/)
    assert.match(html, /href="\/admin\/archive\?recipient=owner@example\.test&amp;page=3"/)
    assert.match(html, /Без темы/)
    assert.match(html, /Отправитель не указан/)
    assert.match(html, /19\.11\.2026, 23:59/)
})

test('the mailbox owner sees their own inbox without the administrator search or recipient column', async () => {
    const html = await render('Archive', {
        user: owner, recipient: '', listQuery: {},
        messages: { data: [letter], current_page: 1, last_page: 1, total: 1, prev_page_url: null, next_page_url: null },
    }, '/admin')
    assert.match(html, /Моя почта/)
    assert.match(html, /owner@example\.test/)
    assert.doesNotMatch(html, /type="search"|recipient-cell/)
})

test('a message keeps its exact return URL, displays text literally, and links to a downloadable attachment', async () => {
    const html = await render('Message', {
        user: admin, backUrl: '/admin/archive?recipient=owner%40example.test&page=2',
        letter: {
            ...letter, body_text: '<b>Текст письма</b>',
            attachments: [{ filename: 'file&notes.txt', url: '/admin/messages/37/attachments/0' }],
        },
    }, '/admin/messages/37?recipient=owner@example.test&page=2')
    assert.match(html, /href="\/admin\/archive\?recipient=owner%40example\.test&amp;page=2"/)
    assert.match(html, /К списку писем/)
    assert.match(html, /&lt;b&gt;Текст письма&lt;\/b&gt;/)
    assert.match(html, /<a\b(?=[^>]*\bdownload\b)(?=[^>]*href="\/admin\/messages\/37\/attachments\/0")[^>]*>/)
    assert.match(html, /file&amp;notes\.txt/)
})

test('the overview shows UTC days, accessible daily counts, and the share delivered to mailboxes', async () => {
    const html = await render('Dashboard', {
        user: admin,
        metrics: { received24h: 3, archived24h: 1, guests: 9, permanent: 2, activeUsers: 17 },
        days: [{ date: '2026-11-20', mailbox: 6, archive: 2 }],
        recentMessages: [letter],
    }, '/admin')
    assert.match(html, /aria-label="20 ноября\. В ящики 6, в общий архив 2\."/)
    assert.match(html, /20\.11<\/span>/)
    assert.match(html, /--percent:\s*75%/)
    assert.match(html, /Пользователи онлайн[\s\S]*?class="metric-value">17</)
    assert.match(html, /href="\/admin\/messages\/37\?from=overview"/)
})
