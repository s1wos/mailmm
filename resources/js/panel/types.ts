import type { ForwardAvailability, MailboxDomainOption } from '../model/types'

export interface PanelUser {
    id: number
    email: string
    is_admin: boolean
}

export interface PanelLetterSummary {
    id: number
    from: string | null
    recipient: string
    subject: string | null
    received_at: string
    expires_at: string
}

export interface PageNavigation {
    current_page: number
    prev_page_url: string | null
    next_page_url: string | null
}

interface Paginated<T> extends PageNavigation {
    data: T[]
    last_page: number
    total: number
}

export interface LoginPage {
    screen: 'login'
    user: null
}

export interface DashboardPage {
    screen: 'dashboard'
    serverTime: string
    user: PanelUser
    metrics: Record<'received24h' | 'archived24h' | 'guests' | 'permanent', number> & { activeUsers: number | null }
    days: { date: string, mailbox: number, archive: number }[]
    recentMessages: PanelLetterSummary[]
}

export interface ArchivePage {
    screen: 'archive'
    serverTime: string
    nextExpiryAt: string | null
    user: PanelUser
    recipient: string
    messages: Paginated<PanelLetterSummary>
    listQuery: Record<string, string | number>
    forwarding?: ForwardingRule | null
}

export interface ForwardingRule {
    sending_enabled: boolean
    status: 'disabled' | 'pending' | 'active'
    version: number
    destination: string | null
    activated_at: string | null
    code_expires_at: string | null
    resend_at: string | null
    disabled_reason: string | null
    last_error_code: string | null
}

export interface MailForward {
    id: string
    mode: 'manual' | 'auto'
    request_id: string | null
    status: 'queued' | 'preparing' | 'submitting' | 'accepted' | 'failed' | 'unknown' | 'cancelled' | 'bounced'
    destination: string
    error_code: string | null
    created_at: string
}

export interface MessagePage {
    screen: 'message'
    user: PanelUser
    backUrl: string
    serverTime: string
    letter: PanelLetterSummary & {
        body_text: string | null
        html_preview: string | null
        attachments: { filename: string, url: string }[]
        forwarding?: ForwardAvailability
    }
    forwards: MailForward[]
}

export interface MailboxesPage {
    screen: 'mailboxes'
    user: PanelUser
    request_id: string
    credentials: { email: string, password: string, resetMailboxId?: number } | null
    mailboxes: Paginated<{ id: number, email: string, created_at: string }>
    domains: (MailboxDomainOption & { limit: number })[]
}

export interface SettingsPage {
    screen: 'settings'
    user: PanelUser
    domains: { id: number, name: string, issuance_enabled: boolean }[]
    guestIssuanceEnabled: boolean
    mxHost: string
}

export interface TemporaryMailboxesPage {
    screen: 'temporary-mailboxes'
    user: PanelUser
    serverTime: string
    nextExpiryAt: string | null
    mailboxes: Paginated<{
        id: number
        email: string
        created_at: string | null
        expires_at: string
        messages_count: number
    }>
}

export interface ErrorsPage {
    screen: 'errors'
    user: PanelUser
    collection: {
        online: boolean
        sources: Record<string, { available: boolean, last_success_at: string | null }>
    }
    entries: {
        data: { id: number, occurred_at: string, service: string, level: string, message: string }[]
        next_page_url: string | null
        prev_page_url: string | null
    }
}

export type PanelPageData = LoginPage | DashboardPage | ArchivePage | MessagePage | MailboxesPage | TemporaryMailboxesPage | SettingsPage | ErrorsPage
