<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useEmailStore } from '../stores/emailStore'
import { useActionGuard, useNavigationGuard } from '../useActionGuard'
import { notifyMailboxSessionChanged } from '../mailboxSocket'
import Archive from './Archive.vue'
import Dashboard from './Dashboard.vue'
import Errors from './Errors.vue'
import Login from './Login.vue'
import Mailboxes from './Mailboxes.vue'
import Message from './Message.vue'
import Settings from './Settings.vue'
import TemporaryMailboxes from './TemporaryMailboxes.vue'
import PanelIcon from './PanelIcon.vue'
import PanelNav from './PanelNav.vue'
import { PanelHttpError, panelRequest, setPanelUser } from './http'
import { panelLoginTarget, panelTabs } from './routes'
import type { ErrorsPage, PanelPageData, PanelUser } from './types'

const route = useRoute()
const router = useRouter()
const mailbox = useEmailStore()
const path = computed(() => route.fullPath.split('#')[0])
const page = shallowRef<PanelPageData | null>(null)
const user = shallowRef<PanelUser | null>(null)
const tabCache = new Map<string, { data: PanelPageData, fetchedAt: number }>()
const tabFreshnessMs = 60_000
const restoredTab = ref(false)
const tabRefreshFailed = ref(false)
let navigationTarget = ''
const actions = useActionGuard(() => !!user.value?.is_admin)
const navigation = useNavigationGuard(() => !!user.value?.is_admin, url => {
    navigationTarget = url.pathname + url.search
})
const error = ref('')
const notice = ref('')
const errorRefreshFailed = ref(false)
const listRefresh = ref<'loading' | 'failed'>()
const signingOut = ref(false)
const main = ref<HTMLElement>()
const menu = ref<HTMLDetailsElement>()
const lifetime = new AbortController()
let currentRequest: AbortController | undefined
let sessionCheck: AbortController | undefined
let checkAfterLoad = false
let expiryTimer: ReturnType<typeof setTimeout> | undefined

const active = computed(() => {
    const screen = page.value
    if (screen?.screen === 'message') return screen.backUrl.split('?')[0]
    if (screen?.screen === 'archive') return screen.user.is_admin ? '/admin/archive' : '/admin'
    if (screen?.screen === 'mailboxes') return '/admin/mailboxes'
    return route.path
})

function title(data: PanelPageData) {
    switch (data.screen) {
        case 'message': return data.letter.subject || 'Без темы'
        case 'archive': return data.user.is_admin ? 'Архив писем' : 'Моя почта'
        case 'dashboard': return 'Обзор почты'
        case 'errors': return 'Ошибки'
        case 'login': return 'Вход в панель'
        case 'mailboxes': return 'Ящики'
        case 'temporary-mailboxes': return 'Временные ящики'
        case 'settings': return 'Настройки'
    }
}

function resetAccount() {
    clearTimeout(expiryTimer)
    tabCache.clear()
    restoredTab.value = false
    tabRefreshFailed.value = false
    listRefresh.value = undefined
    actions.reset()
    navigation.reset()
    navigationTarget = ''
    currentRequest?.abort()
    sessionCheck?.abort()
    page.value = null
    user.value = null
    setPanelUser(null)
    notice.value = ''
    mailbox.resetSession()
}

async function requireLogin() {
    const next = path.value
    resetAccount()
    if (route.path === '/admin/login') await load()
    else await router.replace({ path: '/admin/login', query: { next } })
}

async function authenticated(fallback: string) {
    const target = panelLoginTarget(router, route.query.next, fallback)
    resetAccount()
    await router.replace(target.page)
    if (target.download) {
        const link = document.createElement('a')
        link.href = target.download
        link.download = ''
        link.click()
    }
}

async function focusContent() {
    await nextTick()
    if (route.hash) document.getElementById(route.hash.slice(1))?.scrollIntoView()
    else if (page.value?.screen === 'login') main.value?.querySelector<HTMLInputElement>('input')?.focus({ preventScroll: true })
    else main.value?.focus({ preventScroll: true })
}

function applyResponse(data: PanelPageData) {
    if (data.user?.is_admin && panelTabs.includes(path.value) && !(data.screen === 'mailboxes' && data.credentials)) {
        tabCache.set(path.value, { data, fetchedAt: Date.now() })
    }
    page.value = data
}

function invalidateOnSubmit(event: SubmitEvent) {
    if ((event.target as HTMLFormElement).method !== 'post') return
    currentRequest?.abort()
    tabCache.clear()
}

function syncClock(serverTime: string, refreshStartedAt?: number) {
    if (refreshStartedAt === undefined) {
        mailbox.setClock(serverTime)
        return
    }
    const now = mailbox.serverNow()
    const time = Date.parse(serverTime)
    if (time < refreshStartedAt) {
        const elapsed = Math.max(0, Math.ceil(now - refreshStartedAt))
        mailbox.setClock(new Date(time + elapsed).toISOString())
    } else if (time > now) mailbox.setClock(serverTime)
}

async function load(message = '', mode: 'reload' | 'navigate' | 'retry' = 'reload') {
    clearTimeout(expiryTimer)
    listRefresh.value = undefined
    currentRequest?.abort()
    const cached = mode !== 'reload' && !checkAfterLoad ? tabCache.get(path.value) : undefined
    const interactive = mode === 'retry' || navigationTarget === path.value
    navigationTarget = ''
    restoredTab.value = !!cached
    tabRefreshFailed.value = false
    page.value = cached?.data ?? null
    error.value = ''
    notice.value = message
    document.title = (cached ? title(cached.data) : 'Панель') + ' · mailmm'
    if (menu.value) menu.value.open = false
    if (cached) {
        checkExpiry()
        void focusContent()
        // The journal polls on its own, and expiry can already have started a list refresh.
        if (cached.data.screen === 'errors' || listRefresh.value || (mode !== 'retry' && Date.now() - cached.fetchedAt < tabFreshnessMs)) return
    }
    const refreshStartedAt = cached ? mailbox.serverNow() : undefined
    const request = new AbortController()
    currentRequest = request
    try {
        const data = await panelRequest<PanelPageData | { redirect: string }>(path.value, {
            signal: request.signal,
            headers: interactive ? { 'X-Mailmm-Action': 'navigate' } : {},
        })
        if (request.signal.aborted) return
        if ('redirect' in data) {
            await authenticated(data.redirect)
            return
        }
        if (data.screen === 'errors' && route.query.cursor && !data.entries.prev_page_url && data.entries.data.length) {
            tabCache.delete('/admin/errors')
            await router.replace('/admin/errors')
            return
        }
        if ('serverTime' in data) syncClock(data.serverTime, refreshStartedAt)
        while (checkAfterLoad) {
            checkAfterLoad = false
            const session = await panelRequest<{ user: PanelUser | null }>('/admin/session', { signal: request.signal })
            if (request.signal.aborted) return
            if (session.user?.id !== data.user?.id || session.user?.is_admin !== data.user?.is_admin) {
                await accountChanged(session.user)
                return
            }
        }
        if (user.value && (user.value.id !== data.user?.id || user.value.is_admin !== data.user?.is_admin)) {
            await accountChanged(data.user)
            return
        }
        if (user.value?.id !== data.user?.id) mailbox.resetSession()
        user.value = data.user
        setPanelUser(data.user?.id ?? null)
        if (goToLastPage(data)) return
        applyResponse(data)
        document.title = title(data) + ' · mailmm'
        checkExpiry()
        if (!cached) await focusContent()
    } catch (cause) {
        if (request.signal.aborted) return
        if (cached && (!(cause instanceof PanelHttpError) || cause.status >= 500 || cause.status === 429)) {
            tabRefreshFailed.value = true
            return
        }
        if (cause instanceof PanelHttpError && cause.status === 401 && route.path !== '/admin/login') {
            await requireLogin()
            return
        }
        error.value = cause instanceof PanelHttpError ? cause.message : 'Не удалось загрузить страницу. Проверьте соединение.'
        if (cause instanceof PanelHttpError && (cause.status === 403 || cause.status === 404)) {
            tabCache.clear()
            page.value = null
            void checkSession()
        }
        await focusContent()
    }
}

function goToLastPage(data: PanelPageData) {
    const list = data.screen === 'archive' ? data.messages : data.screen === 'temporary-mailboxes' ? data.mailboxes : null
    if (!list || list.current_page <= list.last_page) return false
    const last = list.last_page
    tabCache.delete(route.path)
    void router.replace({ path: route.path, query: { ...route.query, page: last > 1 ? last : undefined }, hash: route.hash })
    return true
}

async function refreshList() {
    const screen = page.value?.screen
    if ((screen !== 'archive' && screen !== 'temporary-mailboxes') || listRefresh.value === 'loading') return
    listRefresh.value = 'loading'
    tabRefreshFailed.value = false
    currentRequest?.abort()
    const startedAt = mailbox.serverNow()
    const request = new AbortController()
    currentRequest = request
    try {
        const data = await panelRequest<PanelPageData>(path.value, { signal: request.signal })
        if (request.signal.aborted) return
        if (data.screen !== screen || data.user.id !== user.value?.id || data.user.is_admin !== user.value?.is_admin) {
            await accountChanged(data.user)
            return
        }
        if (goToLastPage(data)) return
        syncClock(data.serverTime, startedAt)
        applyResponse(data)
        listRefresh.value = undefined
        checkExpiry()
    } catch (cause) {
        if (request.signal.aborted) return
        if (cause instanceof PanelHttpError && cause.status === 401) await requireLogin()
        else if (cause instanceof PanelHttpError && cause.status === 403) await accountChanged(user.value)
        else listRefresh.value = 'failed'
    }
}

function checkExpiry() {
    clearTimeout(expiryTimer)
    const current = page.value
    if (!current || !('serverTime' in current)) return
    const now = mailbox.serverNow()
    let nextExpiry = Infinity
    let expired = false
    function isCurrent(row: { expires_at: string }) {
        const expiry = Date.parse(row.expires_at)
        if (expiry > now) {
            nextExpiry = Math.min(nextExpiry, expiry)
            return true
        }
        expired = true
        return false
    }
    if (current.screen === 'message') {
        if (!isCurrent(current.letter)) {
            page.value = null
            error.value = 'Срок хранения письма истёк.'
            document.title = 'Письмо недоступно · mailmm'
            return
        }
    } else if (current.screen === 'dashboard') {
        const recentMessages = current.recentMessages.filter(isCurrent)
        if (expired) page.value = { ...current, recentMessages }
    } else if (current.screen === 'archive') {
        const data = current.messages.data.filter(isCurrent)
        if (expired) page.value = { ...current, messages: { ...current.messages, data } }
    } else {
        const data = current.mailboxes.data.filter(isCurrent)
        if (expired) page.value = { ...current, mailboxes: { ...current.mailboxes, data } }
    }
    if (current.screen === 'archive' || current.screen === 'temporary-mailboxes') {
        const listExpiry = current.nextExpiryAt ? Date.parse(current.nextExpiryAt) : Infinity
        if (expired || listExpiry <= now) {
            if (!listRefresh.value) void refreshList()
        } else nextExpiry = Math.min(nextExpiry, listExpiry)
    }
    if (Number.isFinite(nextExpiry)) {
        expiryTimer = setTimeout(checkExpiry, Math.min(nextExpiry - now, 2_147_483_647))
    }
}

async function accountChanged(account: PanelUser | null) {
    if (!account) return requireLogin()
    resetAccount()
    if (path.value === '/admin') await load()
    else await router.replace('/admin')
}

async function checkSession() {
    checkExpiry()
    if (document.hidden) return
    if (!page.value && !error.value) {
        tabCache.clear()
        checkAfterLoad = true
        return
    }
    sessionCheck?.abort()
    const request = new AbortController()
    sessionCheck = request
    try {
        const result = await panelRequest<{ user: PanelUser | null }>('/admin/session', { signal: request.signal })
        if (request.signal.aborted) return
        if (result.user?.id === user.value?.id && result.user?.is_admin === user.value?.is_admin) {
            if (listRefresh.value === 'failed') void refreshList()
            return
        }
        await accountChanged(result.user)
    } catch (cause) {
        if (!request.signal.aborted && cause instanceof PanelHttpError && cause.status === 401) await requireLogin()
    }
}

async function logout() {
    if (signingOut.value) return
    signingOut.value = true
    try {
        const result = await panelRequest<{ redirect: string }>('/admin/logout', { method: 'POST', signal: lifetime.signal })
        if (lifetime.signal.aborted) return
        notifyMailboxSessionChanged()
        resetAccount()
        await router.replace(result.redirect)
    } catch (cause) {
        if (!lifetime.signal.aborted && user.value) error.value = cause instanceof PanelHttpError ? cause.message : 'Не удалось выйти. Проверьте соединение.'
    } finally {
        signingOut.value = false
    }
}

function restoreDocument(event: PageTransitionEvent) {
    if (event.persisted) void load()
    else checkExpiry()
}

watch(() => page.value?.screen === 'errors' && !route.query.cursor, (live, _, onCleanup) => {
    if (!live) return
    const request = new AbortController()
    let pending = false
    async function poll() {
        if (pending || document.hidden || signingOut.value) return
        pending = true
        try {
            const data = await panelRequest<ErrorsPage>('/admin/errors', { signal: request.signal })
            if (request.signal.aborted) return
            if (data.user.id !== user.value?.id || !data.user.is_admin) {
                await accountChanged(data.user)
                return
            }
            errorRefreshFailed.value = false
            applyResponse(data)
        } catch (cause) {
            if (request.signal.aborted) return
            if (cause instanceof PanelHttpError && cause.status === 401) await requireLogin()
            else if (cause instanceof PanelHttpError && cause.status === 403) {
                resetAccount()
                await router.replace('/admin')
            } else errorRefreshFailed.value = true
        } finally {
            pending = false
        }
    }
    const interval = 10_000
    const lastUpdate = tabCache.get('/admin/errors')?.fetchedAt
    if (lastUpdate !== undefined && Date.now() - lastUpdate >= interval) void poll()
    const timer = setInterval(poll, interval)
    onCleanup(() => {
        clearInterval(timer)
        request.abort()
        errorRefreshFailed.value = false
    })
}, { flush: 'sync' })

watch(path, () => { if (route.meta.panel) void load('', 'navigate') }, { immediate: true })
watch(() => route.hash, async hash => { if (hash && page.value) await focusContent() })
onMounted(() => {
    window.addEventListener('focus', checkSession)
    window.addEventListener('pagehide', resetAccount)
    window.addEventListener('pageshow', restoreDocument)
    document.addEventListener('visibilitychange', checkSession)
    window.addEventListener('panel-session-expired', requireLogin)
    window.addEventListener('panel-check-session', checkSession)
})
onBeforeUnmount(() => {
    lifetime.abort()
    resetAccount()
    window.removeEventListener('focus', checkSession)
    window.removeEventListener('pagehide', resetAccount)
    window.removeEventListener('pageshow', restoreDocument)
    document.removeEventListener('visibilitychange', checkSession)
    window.removeEventListener('panel-session-expired', requireLogin)
    window.removeEventListener('panel-check-session', checkSession)
})
</script>

<template>
    <a class="skip-link" href="#content">К содержимому</a>
    <div :class="{ workspace: user }" @click.capture="navigation.capture">
        <template v-if="user">
            <aside class="sidebar">
                <RouterLink class="wordmark" to="/admin" aria-label="mailmm, главная страница панели">mailmm</RouterLink>
                <PanelNav :user="user" :active="active" :signing-out="signingOut || actions.blocked('logout')" @logout="actions.run('logout', 1000, logout)" />
            </aside>
            <div class="mobile-bar">
                <RouterLink class="wordmark" to="/admin">mailmm</RouterLink>
                <details ref="menu" class="mobile-menu">
                    <summary><PanelIcon name="menu" />Меню</summary>
                    <div class="mobile-links"><PanelNav :user="user" :active="active" :signing-out="signingOut || actions.blocked('logout')" @logout="actions.run('logout', 1000, logout)" /></div>
                </details>
            </div>
        </template>
        <main id="content" ref="main" :class="[user ? 'page' : 'login-wrap', { 'restored-tab': restoredTab }]" tabindex="-1" @submit.capture="invalidateOnSubmit">
            <p v-if="notice" class="notice success" role="status"><PanelIcon name="check" />{{ notice }}</p>
            <div v-if="error" class="card form-card" role="alert">
                <p>{{ error }}</p>
                <button class="button secondary" :aria-disabled="actions.blocked(path) || undefined" @click="actions.run(path, 1000, () => load('', 'retry'))">Повторить</button>
                <RouterLink class="text-action" to="/admin">В панель</RouterLink>
            </div>
            <Login v-else-if="page?.screen === 'login'" :page="page" @authenticated="authenticated" />
            <Dashboard v-else-if="page?.screen === 'dashboard'" :page="page" />
            <Errors v-else-if="page?.screen === 'errors'" :page="page" :refresh-failed="errorRefreshFailed" />
            <Archive v-else-if="page?.screen === 'archive'" :page="page" :refresh="listRefresh" @retry="refreshList" />
            <Message v-else-if="page?.screen === 'message'" :page="page" />
            <Mailboxes v-else-if="page?.screen === 'mailboxes'" :key="path" :page="page" @navigate="router.push($event)" />
            <TemporaryMailboxes v-else-if="page?.screen === 'temporary-mailboxes'" :page="page" :refresh="listRefresh" @refresh="refreshList" />
            <Settings v-else-if="page?.screen === 'settings'" :page="page" @updated="load" />
            <p v-else role="status">Загрузка…</p>
            <p v-if="tabRefreshFailed" class="notice" role="status">
                Не удалось обновить данные. Показана сохранённая версия.
                <button class="button secondary" @click="load('', 'retry')">Повторить</button>
            </p>
        </main>
    </div>
</template>
