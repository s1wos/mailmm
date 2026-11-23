import { createRouter, createWebHistory } from 'vue-router'
import AppLayout from '../layout/AppLayout.vue'
import Home from '../pages/Home.vue'
import Help from '../pages/Help.vue'
import About from '../pages/About.vue'

import { panelRoutes } from '../panel/routes'
import { resetScrollRestoration, scrollBehavior } from './scroll'
export const router = createRouter({
    history: createWebHistory(),
    routes: [{
        path: '/',
        component: AppLayout,
        children: [
            { path: '', component: Home, meta: { title: 'Временная почта' } },
            { path: 'help', component: Help, meta: { title: 'Помощь' } },
            { path: 'about', component: About, meta: { title: 'О сервисе' } },
        ],
    }, ...panelRoutes],
    scrollBehavior,
})

router.beforeEach(resetScrollRestoration)
router.afterEach((to, from, failure) => {
    if (failure) return
    const inPanel = Boolean(to.meta.panel)
    document.getElementById('public-css')?.setAttribute('media', inPanel ? 'not all' : 'all')
    document.getElementById('panel-css')?.setAttribute('media', inPanel ? 'all' : 'not all')
    document.querySelector('meta[name="robots"]')?.setAttribute('content', inPanel ? 'noindex' : 'index,follow')
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', inPanel ? '#e9ebfa' : '#e5e6f5')
    if (!inPanel || !from.meta.panel) document.title = (to.meta.title ?? 'Панель') + ' · mailmm'
})
