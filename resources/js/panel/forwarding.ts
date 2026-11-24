export function describeForwardError(code: string) {
    const messages: Record<string, string> = {
        original_unavailable: 'Оригинал письма недоступен.',
        archive_attachment_unavailable: 'Не удалось прочитать вложение из архива. Письмо не отправлено.',
        original_corrupt: 'Сохранённый оригинал повреждён.',
        invalid_original: 'Не удалось прочитать оригинал письма.',
        invalid_from: 'Не удалось определить адрес исходного отправителя.',
        hidden_recipient: 'Оригинал содержит скрытых получателей.',
        message_too_large: 'Размер письма превышает допустимый.',
        transport_unconfigured: 'Сервис исходящей почты пока недоступен.',
        smtp_unsupported: 'Почтовый сервер не поддерживает формат этого письма.',
        smtp_temporary: 'Почтовый сервер временно недоступен.',
        smtp_rejected: 'Почтовый сервер отклонил письмо.',
        recipient_rejected: 'Почтовый сервер отклонил адрес получателя.',
        submission_unknown: 'Соединение прервалось при передаче.',
        delivery_expired: 'Истёк срок отправки письма.',
        access_revoked: 'Доступ к письму отозван.',
        rule_changed: 'Правило пересылки изменилось.',
        rule_replaced: 'Адрес в правиле пересылки заменён.',
        password_reset: 'Пароль ящика сброшен администратором.',
        mailbox_closed: 'Ящик закрыт.',
        owner_disabled: 'Пересылка выключена владельцем.',
        destination_local: 'Для автопересылки нужен внешний адрес.',
        mode_disabled: 'Этот режим пересылки выключен.',
        permanent_bounce: 'Сервер получателя сообщил, что доставка на этот адрес невозможна.',
        complaint: 'Получена жалоба на пересылаемые письма.',
        quota_exceeded: 'Достигнут лимит отправок.',
        quota_deferred: 'Отправка отложена до следующего доступного окна.',
        invalid_recipient: 'Адрес получателя недопустим.',
        ingress_unavailable: 'Недостаточно данных для проверки входящего письма.',
        untrusted_authentication_results: 'Не удалось подтвердить сведения о подлинности письма.',
        invalid_authentication_results: 'Проверка подлинности вернула недопустимый результат.',
        scanner_unavailable: 'Сервис проверки входящей почты недоступен.',
        scanner_deferred: 'Проверка входящего письма временно отложена.',
        blocked_by_filter: 'Почтовый фильтр заблокировал пересылку письма.',
    }
    return messages[code] ?? 'Не удалось выполнить пересылку.'
}

interface PendingForward {
    to: string
    request_id: string
}

const draftPrefix = 'mailmm:pending-forward:'
const draftKey = (userId: number, messageId: number) => draftPrefix + userId + ':' + messageId

export function readPendingForward(userId: number, messageId: number): PendingForward | null {
    try {
        const raw = window.sessionStorage.getItem(draftKey(userId, messageId))
        if (raw) {
            const draft = JSON.parse(raw)
            if (typeof draft?.to === 'string' && typeof draft.request_id === 'string') return draft
        }
    } catch { /* Browser storage can be unavailable. The open form still tracks the request. */ }
    return null
}

export function savePendingForward(userId: number, messageId: number, draft: PendingForward | null) {
    try {
        const key = draftKey(userId, messageId)
        if (draft) window.sessionStorage.setItem(key, JSON.stringify(draft))
        else window.sessionStorage.removeItem(key)
    } catch { /* Keep the key in the open form when storage is unavailable. */ }
}

export function clearForwardDrafts(userId: number) {
    try {
        const storage = window.sessionStorage
        const prefix = draftPrefix + userId + ':'
        for (let index = storage.length - 1; index >= 0; index--) {
            const key = storage.key(index)
            if (key?.startsWith(prefix)) storage.removeItem(key)
        }
    } catch { /* No accessible drafts. */ }
}

export function newForwardId() {
    if (crypto.randomUUID) return crypto.randomUUID()
    // getRandomValues is also available on development hosts served over HTTP.
    const bytes = crypto.getRandomValues(new Uint8Array(16))
    bytes[6] = bytes[6] & 15 | 64
    bytes[8] = bytes[8] & 63 | 128
    const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')
    return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-')
}
