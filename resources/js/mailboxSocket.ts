import type { MessagePage } from './model/types'

export type CheckReason = 'manual' | 'auto'
export interface SocketReply {
    requestId: string
    mailboxId: number
    serverTime: string
    grantExpiresAt: string
    snapshot: string
    unchanged: boolean
    page?: MessagePage
    continuation?: string | null
}

export interface InboxSocket {
    mailboxId: number
    active: boolean
    check(snapshot: string | null, reason: CheckReason, deliveryId?: number, delta?: boolean): Promise<SocketReply>
    page(continuation: string): Promise<SocketReply>
}

const messages: Record<string, string> = {
    access_revoked: 'Сессия завершена. Войдите снова для доступа к своему ящику.',
    rate_limited: 'Почту недавно проверяли. Повторите чуть позже.',
    timeout: 'Сервер не подтвердил проверку почты. Повторите позже.',
    unavailable: 'Не удалось проверить почту. Восстанавливаем соединение.',
    busy: 'Сервис занят. Повторите чуть позже.',
    invalid_request: 'Сервер не смог обработать проверку почты.',
}

export class SocketError extends Error {
    code: string
    retryAfter: number
    constructor(code: string, retryAfter = 0) {
        super(messages[code] ?? messages.unavailable)
        this.code = code
        this.retryAfter = retryAfter
    }
}

type Pending = {
    finish(value: SocketReply | Error): void
    chunks: string[]
    total?: number
    size: number
}

export class MailboxSocket implements InboxSocket {
    readonly mailboxId: number
    active = true
    private send: (event: string, data: Record<string, unknown>) => boolean
    private pending = new Map<string, Pending>()

    constructor(mailboxId: number, send: MailboxSocket['send']) {
        this.mailboxId = mailboxId
        this.send = send
    }

    check(snapshot: string | null, reason: CheckReason, deliveryId?: number, delta?: boolean) {
        return this.request('mailbox.check', { snapshot, reason, deliveryId, ...(delta && { delta }) })
    }

    page(continuation: string) {
        return this.request('mailbox.page', { continuation })
    }

    private request(event: string, fields: Record<string, unknown>) {
        if (!this.active) return Promise.reject(new SocketError('unavailable'))
        const requestId = Array.from(crypto.getRandomValues(new Uint8Array(16)), n => n.toString(16).padStart(2, '0')).join('')
        return new Promise<SocketReply>((resolve, reject) => {
            const timer = setTimeout(() => finish(new SocketError('timeout')), 15000)
            const finish = (value: SocketReply | Error) => {
                clearTimeout(timer)
                this.pending.delete(requestId)
                if (value instanceof Error) reject(value)
                else resolve(value)
            }
            this.pending.set(requestId, { finish, chunks: [], size: 0 })
            try {
                if (!this.send(event, { ...fields, requestId })) finish(new SocketError('unavailable'))
            } catch {
                finish(new SocketError('unavailable'))
            }
        })
    }

    receive(event: 'checked' | 'error' | 'chunk', data: Record<string, unknown>) {
        if (!data || typeof data.requestId !== 'string') return
        const pending = this.pending.get(data.requestId)
        if (!pending) return
        if (event === 'error') {
            pending.finish(new SocketError(String(data.code), Number(data.retryAfter) || 0))
            return
        }
        if (event === 'chunk') {
            const { index, total, content } = data
            if (typeof total !== 'number' || !Number.isInteger(total) || total < 1 || total > 128 ||
                index !== pending.chunks.length || (pending.total !== undefined && pending.total !== total) ||
                typeof content !== 'string' || pending.size + content.length > 2_000_000) {
                pending.finish(new SocketError('invalid_request'))
                return
            }
            pending.total = total
            pending.size += content.length
            pending.chunks.push(content)
            if (pending.chunks.length < total) return
            try {
                const result = JSON.parse(pending.chunks.join(''))
                if (result?.requestId !== data.requestId) throw new Error()
                data = result
            } catch {
                pending.finish(new SocketError('invalid_request'))
                return
            }
        }
        const reply = data as unknown as SocketReply
        if (reply.mailboxId !== this.mailboxId || typeof reply.snapshot !== 'string' ||
            !Number.isFinite(Date.parse(reply.serverTime)) || !Number.isFinite(Date.parse(reply.grantExpiresAt)) ||
            typeof reply.unchanged !== 'boolean' || (!reply.unchanged && !Array.isArray(reply.page?.data))) {
            pending.finish(new SocketError('invalid_request'))
            return
        }
        pending.finish(reply)
    }

    close() {
        this.active = false
        for (const pending of this.pending.values()) pending.finish(new SocketError('unavailable'))
    }
}

const sessionSignal = 'mailmm-session-changed'
let activeSessionChannel: BroadcastChannel | null = null

function openSessionChannel() {
    try { return new BroadcastChannel(sessionSignal) } catch { return null }
}

export function notifyMailboxSessionChanged() {
    if (typeof window === 'undefined') return
    const channel = activeSessionChannel ?? openSessionChannel()
    if (channel) {
        try {
            channel.postMessage('changed')
            return
        } catch {}
        finally { if (channel !== activeSessionChannel) channel.close() }
    }
    try { localStorage.setItem(sessionSignal, String(Date.now()) + Math.random()) } catch {}
}

export function listenForMailboxSessionChange(changed: () => void) {
    const channel = openSessionChannel()
    if (channel) {
        activeSessionChannel = channel
        channel.onmessage = event => { if (event.data === 'changed') changed() }
    }
    const onStorage = (event: StorageEvent) => { if (event.key === sessionSignal) changed() }
    window.addEventListener('storage', onStorage)
    return () => {
        window.removeEventListener('storage', onStorage)
        channel?.close()
        if (activeSessionChannel === channel) activeSessionChannel = null
    }
}
