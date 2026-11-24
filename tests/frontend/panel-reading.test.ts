import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createMemoryHistory, createRouter } from 'vue-router'
import { loadComponent } from './vueFixture.ts'

const admin = { id: 1, email: 'admin@example.test', is_admin: true }
const owner = { id: 2, email: 'owner@example.test', is_admin: false }
const letter = {
    id: 37, from: '', subject: '', recipient: 'owner@example.test',
    received_at: '2026-11-20T02:59:00+03:00',
}

async function render(screen: string, page: object, url: string) {
    const component = await loadComponent(`/resources/js/panel/${screen}.vue`)
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

test('owners and administrators can forward archive copies while the global switch still controls sending', async () => {
    const page = {
        user: owner, backUrl: '/admin',
        letter: { ...letter, body_text: '', attachments: [], forwarding: { available: true, reason: null, content: 'original' } },
        forwards: [{ id: 'f1', mode: 'manual', request_id: 'k1', status: 'accepted', destination: 'reader@example.test',
            error_code: null, created_at: letter.received_at }],
    }
    const html = await render('Message', page, '/admin/messages/37')
    assert.match(html, /<button\b[^>]*>\s*Переслать\s*<\/button>/)
    assert.match(html, /Передано почтовому серверу/)
    assert.match(html, /reader@example\.test/)
    const legacy = await render('Message', { ...page, user: admin, letter: { ...page.letter,
        forwarding: { available: true, reason: null, content: 'archive' } }, forwards: [] }, '/admin/messages/37')
    assert.match(legacy, /<button\b[^>]*>\s*Переслать/)
    const paused = await render('Message', { ...page, letter: { ...page.letter,
        forwarding: { available: false, reason: 'mode_disabled', content: 'archive' } } }, '/admin/messages/37')
    assert.match(paused, /Ручная пересылка выключена/)
    assert.doesNotMatch(paused, /<button\b[^>]*>\s*Переслать/)
})

test('only the owner sees the pending rule, its destination and confirmation controls', async () => {
    const page = {
        user: owner, recipient: '', listQuery: {}, serverTime: '2026-11-20T00:00:00Z',
        forwarding: { status: 'pending', sending_enabled: true, version: 7, destination: 'confirm@example.test', activated_at: null,
            code_expires_at: '2026-11-20T00:10:00Z', resend_at: null, disabled_reason: null, last_error_code: null },
        messages: { data: [], current_page: 1, last_page: 1, total: 0, prev_page_url: null, next_page_url: null },
    }
    const html = await render('Archive', page, '/admin')
    assert.match(html, /Ожидает подтверждения/)
    assert.match(html, /confirm@example\.test/)
    assert.match(html, /autocomplete="one-time-code"/)
    assert.match(html, /Выслать код снова/)
    assert.match(html, /Отменить/)
    assert.doesNotMatch(await render('Archive', { ...page, user: admin }, '/admin/archive'), /confirm@example\.test/)
    const paused = await render('Archive', { ...page, forwarding: {
        ...page.forwarding, sending_enabled: false, code_expires_at: null,
    } }, '/admin#auto-forwarding')
    assert.match(paused, /<h2\b(?=[^>]*id="auto-forwarding")(?=[^>]*tabindex="-1")/)
    assert.match(paused, /Адрес сохранён/)
    assert.doesNotMatch(paused, /autocomplete="one-time-code"|Выслать код снова|>Получить код</)
    assert.match(paused, /Изменить адрес/)
    assert.match(paused, /Отменить/)
    const ready = await render('Archive', { ...page, forwarding: { ...page.forwarding, code_expires_at: null } }, '/admin')
    assert.match(ready, /Получить код/)
    assert.doesNotMatch(ready, /autocomplete="one-time-code"/)
    const activePaused = await render('Archive', { ...page, forwarding: {
        ...page.forwarding, status: 'active', sending_enabled: false,
    } }, '/admin')
    assert.match(activePaused, /Отправка приостановлена/)
    assert.doesNotMatch(activePaused, /Включена/)
    assert.match(activePaused, /Выключить/)
})
