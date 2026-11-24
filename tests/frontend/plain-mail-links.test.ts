import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPinia } from 'pinia'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createMemoryHistory, createRouter } from 'vue-router'
import { loadComponent } from './vueFixture.ts'

for (const reader of ['public', 'panel']) {
    test(`the ${reader} reader links web URLs in plain mail without changing the surrounding text`, async () => {
        const letter = {
            id: 7, from: 'sender@example.test', recipient: 'owner@example.test',
            subject: 'Ссылки', received_at: '2026-11-25T00:00:00Z', attachments: [],
            body_text: 'Открыть (https://example.test/wiki/Order_(123)?a=1&b=2).\n'
                + "«http://[::1]/order_(2)», 'https://example.test/O'Brien'. <b>https:///missing-host.test</b> "
                + 'https://example.test:bad/path javascript:example',
        }
        const component = await loadComponent(reader === 'public'
            ? '/resources/js/components/MessageReader.vue' : '/resources/js/panel/Message.vue')
        const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component }] })
        await router.push('/')
        const props = reader === 'public' ? { summary: letter, message: letter, error: '' }
            : { page: { user: { id: 1, is_admin: false }, letter, backUrl: '/' } }
        const html = await renderToString(createSSRApp(component, props).use(createPinia()).use(router))
        const body = html.match(/<(p|div)\b[^>]*class="(?:letter-body|message-body)"[^>]*>([\s\S]*?)<\/\1>/)?.[2]
        assert.ok(body)
        const links = [...body.matchAll(/<a\b[^>]*\bhref="([^"]+)"[^>]*>([^<]*)<\/a>/g)]
        assert.deepEqual(links.map(match => [match[1], match[2]]), [
            ['https://example.test/wiki/Order_(123)?a=1&amp;b=2', 'https://example.test/wiki/Order_(123)?a=1&amp;b=2'],
            ['http://[::1]/order_(2)', 'http://[::1]/order_(2)'],
            ['https://example.test/O&#39;Brien', 'https://example.test/O&#39;Brien'],
        ])
        assert.equal(body.replace(/<[^>]*>/g, ''), letter.body_text
            .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll("'", '&#39;'))
    })
}
