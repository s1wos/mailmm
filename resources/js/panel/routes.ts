import type { Router, RouteRecordRaw } from 'vue-router'

export const panelTabs = ['/admin', '/admin/archive', '/admin/mailboxes', '/admin/temporary-mailboxes', '/admin/settings', '/admin/errors']
const pages = [...panelTabs, '/admin/login', '/admin/messages/:id(\\d+)']
export const panelRoutes: RouteRecordRaw[] = pages.map(path => ({
    path, component: () => import('./PanelPage.vue'), meta: { panel: true },
}))

export function panelLoginTarget(router: Router, next: unknown, fallback: string): { page: string, download?: string } {
    for (const target of [next, fallback]) {
        if (typeof target !== 'string' || !/^\/admin(?:[/?#]|$)/.test(target)) continue
        const attachment = /^(\/admin\/messages\/\d+)\/attachments\/\d+(?:\?[^#]*)?$/.exec(target)
        if (attachment) return { page: attachment[1], download: target }
        const resolved = router.resolve(target)
        if (resolved.meta.panel && !/^\/admin\/login\/?$/i.test(resolved.path)) return { page: resolved.fullPath }
    }
    return { page: '/admin' }
}
