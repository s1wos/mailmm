import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createMemoryHistory, createRouter } from 'vue-router'
import { panelLoginTarget, panelRoutes } from '../../resources/js/panel/routes.ts'

const router = createRouter({ history: createMemoryHistory(), routes: [...panelRoutes, { path: '/:other(.*)', component: {} }] })

test('login resumes an intended download through the attachment endpoint and opens its letter', () => {
    const attachment = '/admin/messages/42/attachments/1'
    assert.deepEqual(panelLoginTarget(router, null, attachment), { page: '/admin/messages/42', download: attachment })
})

test('login accepts a panel page with its query and ignores destinations outside the panel', () => {
    const archive = '/admin/archive?recipient=ops%40example.test&page=2'
    assert.deepEqual(panelLoginTarget(router, archive, '/admin'), { page: archive })
    for (const next of ['/', '/admin/login', '//example.test/admin', '/admin/session', '/admin/../help']) {
        assert.deepEqual(panelLoginTarget(router, next, '/admin/settings'), { page: '/admin/settings' })
    }
})

test('login returns an administrator to the requested page of temporary mailboxes', () => {
    const page = '/admin/temporary-mailboxes?page=2'
    assert.deepEqual(panelLoginTarget(router, page, '/admin'), { page })
})
