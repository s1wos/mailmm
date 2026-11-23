import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { realtimeKey, subscribeMailbox } from './bootstrap'
import { MailboxSocket, SocketError } from './mailboxSocket'
import type { useEmailStore } from './stores/emailStore'
import { useActionGuard } from './useActionGuard'

const labels = {
    connecting: 'Подключаемся',
    ready: 'Автообновление включено',
    recovering: 'Восстанавливаем соединение',
    configuration: 'Автообновление недоступно',
    unavailable: 'Получение ящика приостановлено',
    denied: 'Доступ к ящику завершён',
}

export function useMailboxSync(store: ReturnType<typeof useEmailStore>) {
    const now = ref(store.serverNow())
    const online = ref(navigator.onLine)
    const state = ref<keyof typeof labels>('connecting')
    const manualCheck = ref<'idle' | 'checking' | 'checked'>('idle')
    const guard = useActionGuard(() => store.isAdmin)
    const statusText = computed(() => online.value ? labels[state.value] : 'Нет соединения')
    const manualDisabled = computed(() => !online.value || store.rotating || state.value !== 'ready')
    const refreshWarning = computed(() => {
        if (!online.value) return 'Нет соединения. Почта обновится, когда сеть появится.'
        return manualCheck.value === 'checking' ? '' : store.error
    })
    const { echo, configurationFailed } = inject(realtimeKey, { echo: null, configurationFailed: ref(false) })
    let socket: MailboxSocket | null = null
    let unsubscribe: (() => void) | undefined
    let ticker: number | undefined
    let feedback: number | undefined
    let retry: number | undefined
    let attempts = 0
    let mounted = false
    let deniedRetried = false
    let needsBootstrap = false
    let suspended = false
    let autoPending = false
    let autoRunning: MailboxSocket | null = null
    let deliveryId: number | undefined

    function available() {
        return mounted && navigator.onLine && !document.hidden
    }

    function clearRetry() {
        clearTimeout(retry)
        retry = undefined
    }

    function schedule(action: () => void, minimum = 0) {
        if (!available() || retry !== undefined) return
        const backoff = Math.min(30000, 1000 * 2 ** Math.min(attempts++, 5))
        retry = window.setTimeout(() => {
            retry = undefined
            if (available()) action()
        }, Math.max(minimum, backoff * (0.8 + Math.random() * 0.4)))
    }

    function resetFeedback() {
        clearTimeout(feedback)
        manualCheck.value = 'idle'
    }

    function detach() {
        unsubscribe?.()
        unsubscribe = undefined
        socket = null
        autoPending = false
        deliveryId = undefined
        clearRetry()
        resetFeedback()
    }

    function contextChanged() {
        store.resetSession()
        reloadContext()
    }

    function reauthorize() {
        needsBootstrap = false
        store.suspendAccess()
        guard.reset()
        attach(store.socketMailboxId)
    }

    function reloadContext() {
        detach()
        guard.reset()
        state.value = 'connecting'
        needsBootstrap = true
        if (available()) void bootstrap()
    }

    function bootstrap() {
        if (!available()) { needsBootstrap = true; return }
        needsBootstrap = false
        void store.refresh({ afterPending: true })
    }

    function attach(id: number | undefined) {
        detach()
        if (!mounted || !id) return
        if (!echo || configurationFailed.value) { state.value = 'configuration'; return }
        state.value = attempts ? 'recovering' : 'connecting'
        unsubscribe = subscribeMailbox(echo, id, {
            ready(connected) {
                clearRetry()
                socket = connected
                requestAuto()
            },
            disconnected() {
                socket = null
                deliveryId = undefined
                state.value = configurationFailed.value ? 'configuration' : 'recovering'
                resetFeedback()
                clearRetry()
            },
            denied(status) {
                if ([401, 403, 419].includes(status)) {
                    if (store.reauthorizing || store.mailbox?.expires_at === null || deniedRetried) {
                        needsBootstrap = false
                        store.revoke()
                        state.value = 'denied'
                        return
                    }
                    deniedRetried = true
                    contextChanged()
                } else {
                    schedule(() => attach(store.socketMailboxId))
                }
            },
            delivery: requestAuto,
            revoked: reauthorize,
        })
    }

    function requestAuto(id?: number) {
        if (id !== undefined) deliveryId = id
        autoPending = true
        queueMicrotask(() => void drain())
    }

    async function drain() {
        const current = socket
        if (!autoPending || !current || current === autoRunning || !available() || store.rotating) return
        autoRunning = current
        autoPending = false
        const delivery = deliveryId
        deliveryId = undefined
        try {
            const applied = await store.checkSocket(current, 'auto', delivery)
            if (socket !== current) return
            if (applied) {
                attempts = 0
                deniedRetried = false
                clearRetry()
                state.value = 'ready'
            } else if (store.mailbox?.id === current.mailboxId) {
                autoPending = true
                deliveryId ??= delivery
            }
        } catch (cause) {
            if (socket !== current || !mounted) return
            if (cause instanceof SocketError && cause.code === 'access_revoked') {
                state.value = 'denied'
            } else if (cause instanceof SocketError && cause.code === 'invalid_request') {
                state.value = 'configuration'
            } else {
                if (state.value !== 'ready' || !(cause instanceof SocketError && cause.code === 'rate_limited')) state.value = 'recovering'
                autoPending = true
                deliveryId ??= delivery
                schedule(() => void drain(), cause instanceof SocketError ? cause.retryAfter * 1000 : 0)
            }
        } finally {
            if (autoRunning === current) autoRunning = null
            if (autoPending && retry === undefined && available() && socket === current) queueMicrotask(() => void drain())
        }
    }

    function checkMail() {
        const current = socket
        if (!current || manualDisabled.value) return
        return guard.run('check', 0, async () => {
            guard.pause('check', 5000)
            clearTimeout(feedback)
            manualCheck.value = 'checking'
            try {
                if (await store.checkSocket(current, 'manual') && current === socket) {
                    guard.pause('check', 5000)
                    manualCheck.value = 'checked'
                    feedback = window.setTimeout(resetFeedback, 2000)
                } else resetFeedback()
            } catch (cause) {
                if (cause instanceof SocketError) {
                    if (cause.code === 'rate_limited') guard.pause('check', cause.retryAfter * 1000)
                    if (cause.code === 'access_revoked') state.value = 'denied'
                }
                resetFeedback()
            }
        })
    }

    function tick() {
        now.value = store.serverNow()
        if (store.expire(now.value)) contextChanged()
        else if (store.grantExpiresAt !== null && store.grantExpiresAt <= now.value) {
            if (store.mailbox?.expires_at === null) reauthorize()
            else contextChanged()
        }
    }

    function resume(event: Event) {
        online.value = navigator.onLine
        tick()
        if (!available()) {
            suspended = true
            clearRetry()
            if (!online.value) resetFeedback()
            return
        }
        const restoredDocument = event.type === 'pageshow' && (event as PageTransitionEvent).persisted
        if (!suspended && !needsBootstrap && !restoredDocument) return
        suspended = false
        if (needsBootstrap) void bootstrap()
        else if (socket) void drain()
        else if (store.socketMailboxId && echo?.connector.connectionStatus() === 'connected' && state.value === 'recovering') {
            attach(store.socketMailboxId)
        }
    }

    onMounted(() => {
        mounted = true
        suspended = !available()
        watch(configurationFailed, failed => { if (failed) state.value = 'configuration' }, { immediate: true })
        watch(() => store.socketMailboxId, (id, previous) => {
            if (id !== previous) guard.reset()
            attach(id)
        }, { immediate: true })
        watch([() => store.loading, () => store.rotating, () => store.bootstrapFailure], ([loading, rotating, failure]) => {
            if (loading || rotating || !failure || store.socketMailboxId) return
            state.value = failure
            needsBootstrap = failure !== 'denied'
            if (failure === 'recovering') schedule(bootstrap)
            else clearRetry()
        })
        watch(() => store.rotating, rotating => { if (!rotating && autoPending) void drain() })
        window.addEventListener('mailbox-session-changed', reloadContext)
        if (!store.reauthorizing) void bootstrap()
        ticker = window.setInterval(tick, 1000)
        window.addEventListener('online', resume)
        window.addEventListener('offline', resume)
        window.addEventListener('pageshow', resume)
        document.addEventListener('visibilitychange', resume)
    })

    onBeforeUnmount(() => {
        mounted = false
        detach()
        clearInterval(ticker)
        window.removeEventListener('mailbox-session-changed', reloadContext)
        window.removeEventListener('online', resume)
        window.removeEventListener('offline', resume)
        window.removeEventListener('pageshow', resume)
        document.removeEventListener('visibilitychange', resume)
        store.closeMessage()
    })
    return { now, online, guard, manualCheck, manualDisabled, refreshWarning, statusText, checkMail }
}
