import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import type { InjectionKey, Ref } from 'vue'
import { csrfHeaders } from './csrf'
import { MailboxSocket } from './mailboxSocket'

export function connectRealtime(configurationError?: () => void) {
    const key = document.querySelector<HTMLMetaElement>('meta[name="reverb-key"]')?.content
    if (!key) return null

    const tls = location.protocol === 'https:'
    const port = Number(location.port || (tls ? 443 : 80))
    const echo = new Echo({
        broadcaster: 'reverb',
        key,
        Pusher,
        wsHost: location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: tls,
        enabledTransports: ['ws', 'wss'],
        channelAuthorization: {
            endpoint: '/api/broadcasting/auth',
            transport: 'ajax',
            headersProvider: csrfHeaders,
        },
        disableStats: true,
        withoutInterceptors: true,
    })
    echo.connector.pusher.connection.bind('error', (failure: { error?: { data?: { code?: number } } }) => {
        const code = failure?.error?.data?.code
        if (code === 4001 || code === 4003 || code === 4009) configurationError?.()
    })
    return echo
}

type EchoConnection = NonNullable<ReturnType<typeof connectRealtime>>

export const realtimeKey: InjectionKey<{ echo: EchoConnection | null, configurationFailed: Ref<boolean> }> = Symbol('realtime')

export function subscribeMailbox(echo: EchoConnection, id: number, handlers: {
    ready(socket: MailboxSocket): void
    disconnected(): void
    denied(status: number): void
    delivery(id: number): void
    revoked(): void
}) {
    const name = 'mailbox.' + id
    const channel = echo.private(name)
    let socket: MailboxSocket | null = null
    let closed = false
    const disconnect = () => {
        socket?.close()
        socket = null
        if (!closed) handlers.disconnected()
    }
    const stop = echo.connector.onConnectionChange(status => {
        if (status !== 'connected') disconnect()
    })
    channel.subscribed(() => {
        if (closed) return
        socket?.close()
        socket = new MailboxSocket(id, (event, data) => echo.connector.pusher.send_event(event, data, channel.name))
        handlers.ready(socket)
    }).error((error: { status?: number }) => {
        if (closed) return
        disconnect()
        handlers.denied(error?.status ?? 0)
    }).listen('.message.received', (event: { id: number }) => {
        if (socket && Number.isSafeInteger(event?.id)) handlers.delivery(event.id)
    }).listen('.mailbox.access-revoked', () => {
        if (socket) handlers.revoked()
    })
    for (const event of ['checked', 'chunk', 'error'] as const) {
        channel.listen('.mailbox.' + event, (data: Record<string, unknown>) => socket?.receive(event, data))
    }
    return () => {
        closed = true
        stop()
        disconnect()
        echo.leave(name)
    }
}
