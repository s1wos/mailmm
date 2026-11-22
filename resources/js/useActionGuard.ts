import { getCurrentScope, onScopeDispose, shallowReactive } from 'vue'

export function useActionGuard(isAdmin: () => boolean = () => false) {
    const pending = shallowReactive(new Map<string, symbol>())
    const pauses = shallowReactive(new Map<string, { until: number, timer: ReturnType<typeof setTimeout> }>())

    function blocked(key: string) {
        return pending.has(key) || (!isAdmin() && (pauses.get(key)?.until ?? 0) > Date.now())
    }

    function pause(key: string, ms: number) {
        if (ms <= 0 || isAdmin()) return
        const until = Date.now() + ms
        const previous = pauses.get(key)
        if (previous && previous.until >= until) return
        clearTimeout(previous?.timer)
        pauses.set(key, { until, timer: setTimeout(() => pauses.delete(key), ms) })
    }

    function run<T>(key: string, ms: number, action: () => T): T | undefined {
        if (blocked(key)) return
        const token = Symbol()
        pending.set(key, token)
        function finish(completed: boolean) {
            if (pending.get(key) !== token) return
            pending.delete(key)
            if (completed) pause(key, ms)
        }
        try {
            const result = action()
            if (result instanceof Promise) {
                return result.then(value => { finish(true); return value }, error => { finish(false); throw error }) as T
            }
            finish(true)
            return result
        } catch (error) {
            finish(false)
            throw error
        }
    }

    function reset() {
        pending.clear()
        for (const pause of pauses.values()) clearTimeout(pause.timer)
        pauses.clear()
    }

    if (getCurrentScope()) onScopeDispose(reset)
    return { blocked, run, pause, reset }
}

export function useNavigationGuard(isAdmin: () => boolean, onNavigate?: (url: URL) => void) {
    const guard = useActionGuard(isAdmin)
    const local = useActionGuard(isAdmin)
    const summaries = new WeakMap<Element, number>()
    let nextId = 0
    let lastLocal = ''
    function capture(event: MouseEvent) {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return
        const control = event.target instanceof Element ? event.target.closest('a[href],summary') : null
        if (!control) return
        let key: string
        let interval = 300
        if (control instanceof HTMLAnchorElement) {
            if (control.hasAttribute('download')) return
            key = control.href
            if (control.pathname.startsWith('/admin')) interval = 1000
        } else {
            let id = summaries.get(control)
            if (id === undefined) summaries.set(control, id = ++nextId)
            key = 'summary:' + id + ':' + (control.parentElement as HTMLDetailsElement).open
        }
        if (interval === 300 && key !== lastLocal) local.reset()
        if (interval === 300) lastLocal = key
        const active = interval === 300 ? local : guard
        if (!active.run(key, interval, () => true)) {
            event.preventDefault()
            event.stopPropagation()
        } else if (control instanceof HTMLAnchorElement && !control.target) {
            onNavigate?.(new URL(control.href))
        }
    }
    return { capture, reset() { guard.reset(); local.reset() } }
}
