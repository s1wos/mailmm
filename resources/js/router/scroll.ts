import type { RouterScrollBehavior } from 'vue-router'

let renderedPath: string | undefined
let pending: { path: string, position: ScrollToOptions } | undefined

export function resetScrollRestoration() {
    renderedPath = undefined
    pending = undefined
}

// A history entry can point past the bottom of the loading screen.
export function finishScrollRestoration(path: string) {
    renderedPath = path
    if (pending?.path === path) {
        window.scrollTo(pending.position)
        pending = undefined
    }
}

export const scrollBehavior: RouterScrollBehavior = (to, from, saved) => {
    if (saved) {
        if (renderedPath === to.fullPath) return saved
        pending = { path: to.fullPath, position: saved }
        return false
    }
    return to.hash || to.path === from.path ? false : { top: 0 }
}
