export interface Mailbox {
    id: number
    email: string
    expires_at: string | null
}

export type AddressFormat = 'random' | 'words'

export interface MailboxDomainOption {
    domain: string
    formats: AddressFormat[]
}

export interface AddressChoice {
    domain?: string
    format?: AddressFormat
}

export interface Attachment {
    filename: string
    url: string
    mime: string | null
    size: number | null
}

interface MessageHeader {
    id: number
    from: string | null
    subject: string | null
    received_at: string
    expires_at: string
}

export interface MessageSummary extends MessageHeader {
    has_attachments: boolean
}

export interface MessagePage {
    data: MessageSummary[]
    meta: { next_cursor: string | null }
}

export interface EmailMessage extends MessageHeader {
    body_text: string | null
    attachments: Attachment[]
}
