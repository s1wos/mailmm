import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import type { AddressChoice, EmailMessage, Mailbox, MailboxDomainOption, MessagePage, MessageSummary } from '../model/types'
import { csrfHeaders } from '../csrf.ts'
import { notifyMailboxSessionChanged, type CheckReason, type InboxSocket, SocketError } from '../mailboxSocket.ts'

const requestTimeout = 15000
const domainOptionsCacheMs = 60 * 60 * 1000

class IssuancePaused extends Error {
    constructor() { super('Выдача временных адресов приостановлена.') }
}

class IssuanceQuotaExceeded extends Error {
    constructor() { super('Лимит выдачи адресов исчерпан. Повторите позже.') }
}

class NoIssuableDomains extends Error {
    options?: MailboxDomainOption[]
    constructor(options?: MailboxDomainOption[]) {
        super('Нет доступных доменов для выдачи адреса.')
        this.options = options
    }
}

class InvalidAddressChoice extends Error {
    options?: MailboxDomainOption[]
    constructor(message: string, options?: MailboxDomainOption[]) {
        super(message)
        this.options = options
    }
}
class ServiceUnavailable extends Error {}

class MailboxChanged extends Error {
    constructor() { super('В другой вкладке уже выбран новый адрес.') }
}

class CandidatesExhausted extends Error {
    constructor() { super('Не удалось подобрать свободное имя. Попробуйте ещё раз или выберите другой формат.') }
}

async function checkResponse(response: Response) {
    if (!response.ok) {
        const body = await response.json().catch(() => null)
        if (response.status === 503) {
            if (body?.code === 'guest_issuance_paused') throw new IssuancePaused()
            if (body?.code === 'no_issuable_domains') throw new NoIssuableDomains(body.options)
            if (body?.code === 'mailbox_candidates_exhausted') throw new CandidatesExhausted()
        }
        if (response.status === 422) {
            throw new InvalidAddressChoice(body?.errors?.domain?.[0] ?? body?.errors?.format?.[0]
                ?? 'Проверьте доступные домены и форматы.', body?.options)
        }
        if (response.status === 404) throw new Error('Письмо больше недоступно.')
        if (response.status === 409) throw new MailboxChanged()
        if (response.status === 429) {
            if (body?.code === 'guest_issuance_quota_exceeded') {
                throw new IssuanceQuotaExceeded()
            }
            throw new Error('Слишком много запросов. Повторите позже.')
        }
        if (response.status === 419) throw new Error('Сессия изменилась. Перезагрузите страницу.')
        if (response.status >= 500) throw new ServiceUnavailable('Сервис временно недоступен. Попробуйте ещё раз.')
        throw new Error('Сервис временно недоступен. Попробуйте ещё раз.')
    }
    return response
}

function describe(cause: unknown) {
    return cause instanceof Error && !(cause instanceof TypeError || cause instanceof DOMException)
        ? cause.message : 'Не удалось связаться с сервером. Проверьте соединение.'
}

export const useEmailStore = defineStore('email', () => {
    const mailbox = ref<Mailbox | null>(null)
    const panelUrl = ref<string | null>(null)
    const suspendedMailbox = ref<{ address: Mailbox, panelUrl: string | null } | null>(null)
    const socketMailboxId = computed(() => mailbox.value?.id ?? suspendedMailbox.value?.address.id)
    const reauthorizing = computed(() => suspendedMailbox.value !== null)
    const isAdmin = ref(globalThis.document?.querySelector?.<HTMLMetaElement>('meta[name="mailmm-is-admin"]')?.content === '1')
    const grantExpiresAt = ref<number | null>(null)
    const messages = ref<MessageSummary[]>([])
    const nextCursor = ref<string | null>(null)
    const loadingMore = ref(false)
    const moreError = ref('')
    const earliestExpiry = computed(() => messages.value.reduce(
        (earliest, message) => Math.min(earliest, Date.parse(message.expires_at)), Infinity,
    ))
    const selectedId = ref<number | null>(null)
    const detail = ref<EmailMessage | null>(null)
    const detailError = ref('')
    const checkedAt = ref<number | null>(null)
    const loading = ref(false)
    const rotating = ref(false)
    const error = ref('')
    const rotationError = ref('')
    const issuancePaused = ref(false)
    const bootstrapFailure = ref<'recovering' | 'unavailable' | 'denied' | null>(null)
    const domainOptions = ref<MailboxDomainOption[]>([])
    const optionsLoading = ref(false)
    const optionsError = ref('')
    let optionsRequest = 0
    let optionsFreshUntil = 0
    let revision = 0
    let refreshRevision = 0
    let pendingRotation: { version: number, originalId: number } | null = null
    let pending: Promise<void> | null = null
    let pendingMore: Promise<void> | null = null
    let pendingSocket: Promise<boolean> | null = null
    let pendingOptions: Promise<void> | null = null
    const checkingSocket = ref(false)
    let refreshAgain = false
    let expanded = false
    let inboxSnapshot: string | null = null
    let detailRequest: AbortController | null = null
    let clock: { time: number, mark: number, wallTime: number } | null = null

    function serverNow() {
        return clock ? clock.time + Math.max(performance.now() - clock.mark, Date.now() - clock.wallTime) : Date.now()
    }

    function setClock(value: string) {
        const time = Date.parse(value)
        if (Number.isFinite(time)) clock = { time, mark: performance.now(), wallTime: Date.now() }
    }

    function revoke(cause = new SocketError('access_revoked')) {
        resetSession()
        bootstrapFailure.value = 'denied'
        error.value = cause.message
    }

    function suspendAccess() {
        const previous = suspendedMailbox.value ?? (mailbox.value ? { address: mailbox.value, panelUrl: panelUrl.value } : null)
        resetSession()
        suspendedMailbox.value = previous
    }

    async function request(path: string, options: RequestInit = {}) {
        const version = revision
        const box = mailbox.value?.id
        const headers = new Headers(options.headers)
        headers.set('Accept', 'application/json')
        const method = options.method ?? 'GET'
        if (method !== 'GET') {
            for (const [name, token] of Object.entries(csrfHeaders())) headers.set(name, token)
        }
        const signal = options.signal ?? AbortSignal.timeout(requestTimeout)
        const response = await fetch(path, { method, headers, signal, body: options.body, credentials: 'same-origin', cache: 'no-store' })
        if (response.status === 401) {
            const revoked = new SocketError('access_revoked')
            if (version === revision && box === mailbox.value?.id) revoke(revoked)
            throw revoked
        }
        if (version === revision && (path === '/api/email' || path === '/api/emails')) {
            panelUrl.value = response.headers.get('X-Panel-Url')
            isAdmin.value = response.headers.get('X-Mailmm-Is-Admin') === '1'
        }
        return checkResponse(response)
    }

    function loadOptions() {
        if (pendingOptions) return pendingOptions
        if (Date.now() < optionsFreshUntil) return Promise.resolve()
        const read = readOptions().finally(() => { if (pendingOptions === read) pendingOptions = null })
        pendingOptions = read
        return read
    }

    function cacheOptions(options: MailboxDomainOption[]) {
        domainOptions.value = options
        optionsFreshUntil = Date.now() + domainOptionsCacheMs
    }

    async function readOptions() {
        const sequence = ++optionsRequest
        optionsLoading.value = optionsFreshUntil === 0
        optionsError.value = ''
        try {
            const response = await request('/api/email/options')
            const { data }: { data: MailboxDomainOption[] } = await response.json()
            if (sequence === optionsRequest) cacheOptions(data)
        } catch (cause) {
            if (sequence === optionsRequest) optionsError.value = describe(cause)
        } finally {
            if (sequence === optionsRequest) optionsLoading.value = false
        }
    }

    async function readPage(box: number, cursor?: string, more = false) {
        const path = cursor ? '/api/messages?cursor=' + encodeURIComponent(cursor) : '/api/messages'
        const response = await request(path, more ? { headers: { 'X-Mailmm-Action': 'more' } } : {})
        if (response.headers.get('X-Mailbox-Id') !== String(box)) return null
        return await response.json() as MessagePage
    }

    function closeMessage() {
        detailRequest?.abort()
        detailRequest = null
        selectedId.value = null
        detail.value = null
        detailError.value = ''
    }

    async function openMessage(id: number) {
        const box = mailbox.value?.id
        if (!box || !messages.value.some(message => message.id === id)) return
        closeMessage()
        const controller = new AbortController()
        const timer = setTimeout(() => controller.abort(), requestTimeout)
        detailRequest = controller
        selectedId.value = id
        try {
            const response = await request('/api/messages/' + id, { signal: controller.signal })
            const payload: { data: EmailMessage } = await response.json()
            if (controller !== detailRequest) return
            if (response.headers.get('X-Mailbox-Id') !== String(box) || payload.data.id !== id) {
                closeMessage()
                await refresh({ afterPending: true })
                return
            }
            detail.value = payload.data
        } catch (cause) {
            if (controller === detailRequest) detailError.value = describe(cause)
        } finally {
            clearTimeout(timer)
            if (controller === detailRequest) detailRequest = null
        }
    }

    function loadMore() {
        if (pendingMore) return pendingMore
        if (loading.value || rotating.value || checkingSocket.value) return Promise.resolve()
        pendingMore = appendPage().finally(() => { pendingMore = null })
        return pendingMore
    }

    async function appendPage() {
        const cursor = nextCursor.value
        const box = mailbox.value?.id
        if (!cursor || !box || loadingMore.value) return
        loadingMore.value = true
        moreError.value = ''
        try {
            const page = await readPage(box, cursor, true)
            if (mailbox.value?.id !== box || cursor !== nextCursor.value) return
            if (!page) {
                clear()
                void refresh({ afterPending: true })
                return
            }
            expanded = true
            messages.value.push(...page.data)
            nextCursor.value = page.meta.next_cursor
        } catch (cause) {
            if (mailbox.value?.id === box) moreError.value = describe(cause)
        } finally {
            if (mailbox.value?.id === box) loadingMore.value = false
        }
    }

    function clear() {
        mailbox.value = null
        suspendedMailbox.value = null
        messages.value = []
        rotationError.value = ''
        closeMessage()
        expanded = false
        inboxSnapshot = null
        nextCursor.value = null
        loadingMore.value = false
        moreError.value = ''
        checkedAt.value = null
        grantExpiresAt.value = null
    }

    function resetSession() {
        revision++
        optionsRequest++
        pendingOptions = null
        pendingRotation = null
        rotating.value = false
        clear()
        panelUrl.value = null
        isAdmin.value = false
        issuancePaused.value = false
        bootstrapFailure.value = null
        error.value = ''
        domainOptions.value = []
        optionsFreshUntil = 0
        optionsError.value = ''
        optionsLoading.value = false
        refreshAgain = false
    }

    function expire(now: number) {
        if (earliestExpiry.value <= now) {
            messages.value = messages.value.filter(message => Date.parse(message.expires_at) > now)
            inboxSnapshot = null
            if (!messages.value.some(message => message.id === selectedId.value)) closeMessage()
        }
        if (!mailbox.value?.expires_at || Date.parse(mailbox.value.expires_at) > now) return false
        revision++
        clear()
        return true
    }

    async function applyPages(
        snapshot: string | null, first: MessagePage | null,
        nextPage: (cursor: string) => Promise<MessagePage | null>, current: () => boolean,
    ) {
        const boundaryCursor = nextCursor.value
        const fullyExpanded = expanded && !boundaryCursor
        const rows: MessageSummary[] = []
        let page = first
        let next: string | null = null
        while (page && current()) {
            const oldest = expanded ? messages.value.at(-1) : messages.value.find(row => row.id === selectedId.value)
            next = page.meta.next_cursor
            const boundaryIndex = oldest && !fullyExpanded ? page.data.findIndex(row =>
                row.received_at < oldest.received_at || (row.received_at === oldest.received_at && row.id <= oldest.id),
            ) : -1
            if (expanded && boundaryIndex >= 0) {
                const visibleCount = page.data[boundaryIndex].id === oldest?.id ? boundaryIndex + 1 : boundaryIndex
                rows.push(...page.data.slice(0, visibleCount))
                if (visibleCount < page.data.length) next = boundaryCursor
            } else {
                rows.push(...page.data)
            }
            if (!oldest || boundaryIndex >= 0 || !next) break
            page = await nextPage(next)
        }
        if (!current() || !page) return false
        messages.value = rows
        nextCursor.value = next
        inboxSnapshot = snapshot
        if (!messages.value.some(message => message.id === selectedId.value)) closeMessage()
        return true
    }

    async function load(version: number, rotation?: { from: number, choice: AddressChoice }) {
        for (let attempt = 0; attempt < 2; attempt++) {
            const response = attempt === 0 && rotation
                ? await request('/api/emails', {
                    method: 'POST',
                    headers: { 'X-Expected-Mailbox-Id': String(rotation.from), 'Content-Type': 'application/json' },
                    body: JSON.stringify(rotation.choice),
                })
                : await request('/api/email')
            if (version !== revision) return
            const { data }: { data: Mailbox } = await response.json()
            if (version !== revision) return
            setClock(response.headers.get('X-Server-Time') ?? response.headers.get('Date') ?? '')
            if (mailbox.value?.id !== data.id) clear()
            mailbox.value = data
            bootstrapFailure.value = null
            const expires = Date.parse(response.headers.get('X-Mailbox-Grant-Expires-At') ?? '')
            if (Number.isFinite(expires)) grantExpiresAt.value = expires
            issuancePaused.value = false
            const snapshot = response.headers.get('X-Inbox-Snapshot')
            if (snapshot !== null && snapshot === inboxSnapshot) {
                checkedAt.value = Date.now()
                error.value = ''
                return
            }

            const applied = await applyPages(snapshot, await readPage(data.id), cursor => readPage(data.id, cursor), () => version === revision)
            if (version !== revision) return
            if (!applied) {
                clear()
                continue
            }
            checkedAt.value = Date.now()
            error.value = ''
            return
        }
        throw new Error('Адрес изменился. Проверьте почту ещё раз.')
    }

    function checkSocket(socket: InboxSocket, reason: CheckReason, deliveryId?: number): Promise<boolean> {
        if (!socket.active || rotating.value || socketMailboxId.value !== socket.mailboxId) return Promise.resolve(false)
        if (pendingSocket) {
            return pendingSocket.catch(() => false).then(() => checkSocket(socket, reason, deliveryId))
        }
        const version = revision
        const current = () => version === revision && socket.active && socketMailboxId.value === socket.mailboxId
        const previous = [pending, pendingMore]
        checkingSocket.value = true
        pendingSocket = (async () => {
            await Promise.all(previous)
            if (!current()) return false
            try {
                let reply = await socket.check(inboxSnapshot, reason, deliveryId)
                if (!current()) return false
                const snapshot = reply.snapshot
                if (reply.mailboxId !== socket.mailboxId) throw new SocketError('invalid_request')
                if (reply.unchanged) {
                    if (inboxSnapshot !== snapshot) throw new SocketError('invalid_request')
                } else {
                    if (!reply.page) throw new SocketError('invalid_request')
                    const applied = await applyPages(snapshot, reply.page, async () => {
                        if (!reply.continuation) throw new SocketError('invalid_request')
                        reply = await socket.page(reply.continuation)
                        if (reply.mailboxId !== socket.mailboxId || reply.snapshot !== snapshot || !reply.page) {
                            throw new SocketError('invalid_request')
                        }
                        return reply.page
                    }, current)
                    if (!applied || !current()) return false
                }
                if (suspendedMailbox.value) {
                    mailbox.value = suspendedMailbox.value.address
                    panelUrl.value = suspendedMailbox.value.panelUrl
                    suspendedMailbox.value = null
                }
                setClock(reply.serverTime)
                const expires = Date.parse(reply.grantExpiresAt)
                if (Number.isFinite(expires)) grantExpiresAt.value = expires
                checkedAt.value = Date.now()
                error.value = ''
                return true
            } catch (cause) {
                if (!current()) return false
                if (cause instanceof SocketError && cause.code === 'access_revoked') revoke(cause)
                else error.value = describe(cause)
                throw cause
            }
        })().finally(() => {
            pendingSocket = null
            checkingSocket.value = false
        })
        return pendingSocket
    }

    function finishRotation(version: number) {
        if (pendingRotation?.version !== version) return
        rotating.value = false
        if (version === revision && !mailbox.value && bootstrapFailure.value === 'recovering') return
        if (version === revision && mailbox.value && mailbox.value.id !== pendingRotation.originalId) notifyMailboxSessionChanged()
        pendingRotation = null
    }

    async function loadCurrent(version: number) {
        try {
            await load(version)
        } catch (cause) {
            if (version !== revision) return
            const transient = cause instanceof TypeError || cause instanceof DOMException || cause instanceof ServiceUnavailable
            bootstrapFailure.value = transient ? 'recovering' : 'unavailable'
            issuancePaused.value = cause instanceof IssuancePaused
            if (issuancePaused.value || cause instanceof IssuanceQuotaExceeded || cause instanceof NoIssuableDomains
                || cause instanceof CandidatesExhausted) clear()
            error.value = describe(cause)
        }
        if (!rotating.value) finishRotation(version)
    }

    function refresh({ afterPending = false } = {}): Promise<void> {
        if (rotating.value) return Promise.resolve()
        if (pending) {
            if (afterPending || refreshRevision !== revision) refreshAgain = true
            return pending
        }
        loading.value = true
        pending = (async () => {
            if (pendingSocket) await pendingSocket.catch(() => {})
            if (pendingMore) await pendingMore
            do {
                refreshAgain = false
                refreshRevision = revision
                await loadCurrent(refreshRevision)
            } while (refreshAgain && !rotating.value)
        })().finally(() => {
            pending = null
            loading.value = false
        })
        return pending
    }

    async function rotate(choice: AddressChoice = {}) {
        if (rotating.value || !mailbox.value?.expires_at) return
        const originalId = mailbox.value.id
        rotating.value = true
        const version = ++revision
        pendingRotation = { version, originalId }
        closeMessage()
        rotationError.value = ''
        error.value = ''
        try {
            await Promise.all([pending, pendingMore])
            if (version !== revision) return
            await load(version, { from: originalId, choice })
        } catch (cause) {
            if (version !== revision) return
            if (mailbox.value?.id === originalId) {
                if (cause instanceof InvalidAddressChoice || cause instanceof NoIssuableDomains) {
                    optionsRequest++
                    pendingOptions = null
                    if (Array.isArray(cause.options)) {
                        cacheOptions(cause.options)
                        optionsError.value = ''
                        optionsLoading.value = false
                    } else {
                        domainOptions.value = []
                        optionsFreshUntil = 0
                        await loadOptions()
                    }
                    return cause.message
                }
                if (cause instanceof CandidatesExhausted) return cause.message
            }
            clear()
            await loadCurrent(version)
            if (version === revision && mailbox.value && (mailbox.value.id === originalId || cause instanceof MailboxChanged)) {
                rotationError.value = describe(cause)
            }
        } finally {
            finishRotation(version)
        }
    }

    return {
        mailbox, panelUrl, isAdmin, grantExpiresAt, checkSocket, checkingSocket, revoke, suspendAccess, reauthorizing, socketMailboxId,
        messages, nextCursor, loadingMore, moreError, selectedId, detail, detailError,
        checkedAt, loading, rotating, error, rotationError, issuancePaused, bootstrapFailure, serverNow, setClock, refresh, rotate, expire, loadMore, openMessage, closeMessage,
        domainOptions, optionsLoading, optionsError, loadOptions, resetSession,
    }
})
