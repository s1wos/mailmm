import { csrfHeaders } from '../csrf.ts'
import type { MailboxesPage } from './types.ts'

let panelUserId: number | null = null

export function setPanelUser(id: number | null) {
    panelUserId = id
}

export class PanelHttpError extends Error {
    status: number
    errors: Record<string, string[]>
    domains?: MailboxesPage['domains']

    constructor(status: number, message: string, errors: Record<string, string[]> = {}, domains?: MailboxesPage['domains']) {
        super(message)
        this.status = status
        this.errors = errors
        this.domains = domains
    }
}

const messages: Record<number, string> = {
    401: 'Сессия завершена. Войдите снова.',
    403: 'У вас нет доступа к этому разделу.',
    404: 'Страница или письмо больше недоступны.',
    409: 'В другой вкладке изменилась учётная запись.',
    419: 'Сессия изменилась. Повторите вход.',
    422: 'Проверьте введённые данные.',
    429: 'Слишком много запросов. Повторите позже.',
}

export async function panelRequest<T>(path: string, options: RequestInit = {}): Promise<T> {
    const headers = new Headers(options.headers)
    headers.set('Accept', 'application/json')
    const method = options.method?.toUpperCase() ?? 'GET'
    if (method !== 'GET') {
        for (const [key, value] of Object.entries(csrfHeaders())) headers.set(key, value)
        if (panelUserId !== null) headers.set('X-Panel-User', String(panelUserId))
    }
    const response = await fetch(path, { ...options, headers, credentials: 'same-origin', cache: 'no-store' })
    if (response.ok) return await response.json() as T

    const data = await response.json().catch(() => null)
    if (method !== 'GET' && !options.signal?.aborted) {
        if (response.status === 401 || response.status === 419) {
            globalThis.window?.dispatchEvent(new Event('panel-session-expired'))
        } else if (response.status === 403 || response.status === 404 || data?.code === 'panel_user_changed') {
            globalThis.window?.dispatchEvent(new Event('panel-check-session'))
        }
    }
    throw new PanelHttpError(response.status, messages[response.status] ?? 'Не удалось выполнить запрос. Повторите позже.', data?.errors, data?.domains)
}
