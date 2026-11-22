import type { connectRealtime } from './bootstrap'
import { csrfHeaders } from './csrf.ts'

type Connection = NonNullable<ReturnType<typeof connectRealtime>>

export function trackSitePresence(echo: Connection, channel: string) {
    let subscription: AbortController | undefined
    let retry: ReturnType<typeof setTimeout> | undefined
    let announced: string | undefined
    let peers: BroadcastChannel | undefined
    try { peers = new BroadcastChannel('mailmm-site-presence') } catch {}

    if (peers) peers.onmessage = ({ data }) => {
        // Concurrent first visits can both subscribe before the browser settles on one cookie.
        if (!subscription || typeof data !== 'string' || data === channel || !/^site-visitor\.[a-f0-9]{32}$/.test(data)) return
        leave()
        sync()
    }

    function leave() {
        clearTimeout(retry)
        if (!subscription) return
        subscription.abort()
        subscription = undefined
        echo.leave(channel)
    }

    function sync() {
        if (document.hidden) {
            leave()
        } else if (!subscription) {
            const attempt = new AbortController()
            subscription = attempt
            echo.private(channel).subscribed(() => {
                if (!attempt.signal.aborted && announced !== channel) {
                    announced = channel
                    peers?.postMessage(channel)
                }
            }).error(async ({ status }: { status?: number }) => {
                if (attempt.signal.aborted) return
                if (status === 403) {
                    // Pusher reports the status only. A concurrent first visit can replace this tab's visitor cookie.
                    const response = await fetch('/api/broadcasting/auth', {
                        method: 'POST', signal: attempt.signal,
                        headers: { Accept: 'application/json', ...csrfHeaders() },
                        body: new URLSearchParams({ socket_id: echo.socketId() ?? '', channel_name: 'private-' + channel }),
                    }).catch(() => null)
                    const data = response?.status === 403 ? await response.json().catch(() => null) : null
                    if (attempt.signal.aborted) return
                    if (/^site-visitor\.[a-f0-9]{32}$/.test(data?.visitorChannel) && data.visitorChannel !== channel) {
                        leave()
                        channel = data.visitorChannel
                        sync()
                        return
                    }
                }
                leave()
                retry = setTimeout(sync, 10_000)
            })
        }
    }

    document.addEventListener('visibilitychange', sync)
    window.addEventListener('pageshow', sync)
    window.addEventListener('pagehide', leave)
    sync()
    return () => {
        document.removeEventListener('visibilitychange', sync)
        window.removeEventListener('pageshow', sync)
        window.removeEventListener('pagehide', leave)
        peers?.close()
        leave()
    }
}
