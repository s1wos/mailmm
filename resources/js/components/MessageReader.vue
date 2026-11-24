<script setup lang="ts">
import { ArrowLeft, Download, Forward, Paperclip } from '@lucide/vue'
import { RouterLink } from 'vue-router'
import type { EmailMessage, MessageSummary } from '../model/types'
import MailboxWarning from './MailboxWarning.vue'
import { useEmailStore } from '../stores/emailStore'
import { useActionGuard } from '../useActionGuard'

defineProps<{ summary: MessageSummary, message: EmailMessage | null, error: string }>()
defineEmits<{ back: [], retry: [] }>()
const mailbox = useEmailStore()
const actions = useActionGuard(() => mailbox.isAdmin)
const date = new Intl.DateTimeFormat('ru', { dateStyle: 'long', timeStyle: 'short' })
const decimals = new Intl.NumberFormat('ru', { maximumFractionDigits: 1 })

function fileSize(size: number) {
    if (size < 1024) return size + ' Б'
    if (size < 1024 * 1024) return decimals.format(size / 1024) + ' КБ'
    return decimals.format(size / (1024 * 1024)) + ' МБ'
}
</script>

<template>
    <article class="reader" aria-labelledby="letter-subject">
        <button type="button" class="text-btn reader-back" @click="$emit('back')"><ArrowLeft /> К входящим</button>
        <h3 id="letter-subject" tabindex="-1">{{ (message?.subject ?? summary.subject) || 'Без темы' }}</h3>
        <dl class="letter-meta">
            <dt>От кого</dt><dd>{{ (message?.from ?? summary.from) || 'Отправитель не указан' }}</dd>
            <dt>Получено</dt><dd><time :datetime="summary.received_at">{{ date.format(new Date(summary.received_at)) }}</time></dd>
        </dl>
        <MailboxWarning :message="error" />
        <button v-if="error" type="button" class="text-btn" @click="$emit('retry')">Повторить загрузку письма</button>
        <p v-else-if="!message" role="status" class="letter-body">Загружаем письмо…</p>
        <p v-else class="letter-body">{{ message.body_text?.trim() || 'В письме нет текста.' }}</p>
        <div v-if="message?.attachments.length" class="attachments">
            <h4><Paperclip /> Вложения · {{ message.attachments.length }}</h4>
            <ul>
                <li v-for="file in message.attachments" :key="file.url">
                    <a :href="file.url" :download="file.filename" :aria-disabled="actions.blocked(file.url) || undefined"
                        @click="actions.run(file.url, 1000, () => true) || $event.preventDefault()">
                        <span>{{ file.filename || 'Вложение' }}</span>
                        <small v-if="file.size !== null">{{ fileSize(file.size) }}</small>
                        <Download aria-label="Скачать" />
                    </a>
                </li>
            </ul>
        </div>
        <RouterLink v-if="message?.forwarding?.available" class="text-btn forward-link" :to="'/admin/messages/' + message.id + '#forward'">
            <Forward aria-hidden="true" /> Переслать
        </RouterLink>
        <p v-else-if="message?.forwarding?.reason === 'mode_disabled'" class="forward-hint">Ручная пересылка выключена.</p>
    </article>
</template>

<style scoped>
.forward-link { margin-top: 1rem; min-height: 44px; }
.forward-hint { margin-top: 1rem; color: var(--mm-muted); font-size: .875rem; }
</style>
