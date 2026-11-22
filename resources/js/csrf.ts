export function csrfHeaders(): Record<string, string> {
    const cookie = globalThis.document?.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
    if (cookie) return { 'X-XSRF-TOKEN': decodeURIComponent(cookie[1]) }
    return { 'X-CSRF-TOKEN': globalThis.document?.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' }
}
