<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { Check, ChevronDown, Inbox, Mail, MailOpen, Paperclip, RefreshCw } from '@lucide/vue'
import { useMailboxSync } from '../useMailboxSync'
import { useEmailStore } from '../stores/emailStore'
import AddressCard from '../components/AddressCard.vue'
import MessageReader from '../components/MessageReader.vue'
import MailboxWarning from '../components/MailboxWarning.vue'

const store = useEmailStore()
const { now, online, guard, manualCheck, manualDisabled, refreshWarning, statusText, checkMail } = useMailboxSync(store)
const inboxTitle = ref<HTMLElement | null>(null)
const selected = computed(() => store.messages.find(m => m.id === store.selectedId))
const shortDate = new Intl.DateTimeFormat('ru', { day: 'numeric', month: 'short' })
const time = new Intl.DateTimeFormat('ru', { hour: '2-digit', minute: '2-digit' })
const today = new Intl.DateTimeFormat('ru', { weekday: 'long', day: 'numeric', month: 'long' })
const messageDate = (value: string) => {
    const received = new Date(value)
    return received.toDateString() === new Date(now.value).toDateString() ? time.format(received) : shortDate.format(received)
}
async function openMessage(id: number) {
    const action = guard.run('open:' + id, 1000, () => store.openMessage(id))
    if (!action) return
    await nextTick()
    document.getElementById('letter-subject')?.focus()
}
async function back() {
    const id = store.selectedId
    if (!guard.run('back:' + id, 300, () => { store.closeMessage(); return true })) return
    await nextTick()
    const row = document.getElementById('letter-' + id) ?? inboxTitle.value
    row?.focus()
}

async function loadMore() {
    const lastId = store.messages.at(-1)?.id
    const action = guard.run('more:' + store.nextCursor, 1000, () => store.loadMore())
    if (!action) return
    await action
    const previous = store.messages.findIndex(message => message.id === lastId)
    const firstNew = previous >= 0 ? store.messages[previous + 1] : undefined
    if (!firstNew) return
    await nextTick()
    document.getElementById('letter-' + firstNew.id)?.focus()
}

</script>

<template>
    <div class="mail-page" :class="{ 'reading-letter': selected }">
        <header class="page-heading">
            <div><h1>Моя почта</h1><p class="today">{{ today.format(now) }}</p></div>
        </header>
        <AddressCard :now="now" />
        <section class="mail-section" aria-labelledby="inbox-title">
            <div class="inbox-heading">
                <h2 id="inbox-title" ref="inboxTitle" tabindex="-1">Входящие <span v-if="store.checkedAt" class="count">{{ store.messages.length }}{{ store.nextCursor ? '+' : '' }}</span></h2>
                <button class="btn neutral-btn check-mail" type="button" :class="{ checked: manualCheck === 'checked' }"
                    :disabled="manualDisabled" :aria-disabled="guard.blocked('check') || undefined"
                    :aria-busy="manualCheck === 'checking'" @click="checkMail">
                    <Check v-if="manualCheck === 'checked'" aria-hidden="true" />
                    <RefreshCw v-else :class="{ spinning: manualCheck === 'checking' }" aria-hidden="true" />
                    <span class="check-label">
                        <span :class="{ 'sizing-label': manualCheck !== 'idle' }">Проверить почту</span>
                        <span v-if="manualCheck !== 'idle'">{{ manualCheck === 'checking' ? 'Проверяем…' : 'Проверено' }}</span>
                    </span>
                </button>
            </div>
            <MailboxWarning :message="refreshWarning" />
            <div class="mail-surface">
                <div v-if="!store.messages.length" class="empty-state">
                    <div class="tray" aria-hidden="true"><span class="mail-tile mail-tile-back"></span><span class="mail-tile"><Mail /></span></div>
                    <h3 v-if="!store.checkedAt && (store.loading || store.rotating)">Загружаем почту…</h3>
                    <h3 v-else-if="!store.checkedAt && (store.error || !online)">Почта недоступна</h3>
                    <h3 v-else>Писем пока нет</h3>
                    <p v-if="store.checkedAt">Письма, отправленные на ваш адрес,<br>появятся здесь автоматически.</p>
                </div>
                <div v-else class="mail-panes">
                    <div class="list-pane">
                        <ul class="letter-list">
                            <li v-for="message in store.messages" :key="message.id">
                                <button :id="'letter-' + message.id" type="button" :class="{ chosen: selected?.id === message.id }"
                                    :aria-disabled="guard.blocked('open:' + message.id) || undefined"
                                    :aria-current="selected?.id === message.id ? 'true' : undefined" @click="openMessage(message.id)">
                                    <span class="sender-initial" aria-hidden="true">{{ message.from?.trim().charAt(0).toLocaleUpperCase('ru') || '@' }}</span>
                                    <span class="sender">{{ message.from || 'Отправитель не указан' }}</span>
                                    <time :datetime="message.received_at">{{ messageDate(message.received_at) }}</time>
                                    <span class="subject">{{ message.subject || 'Без темы' }} <Paperclip v-if="message.has_attachments" aria-label="Есть вложения" /></span>
                                </button>
                            </li>
                        </ul>
                        <MailboxWarning :message="store.moreError" />
                        <button v-if="store.nextCursor" class="more-btn" type="button" :disabled="store.loadingMore || store.loading || store.checkingSocket" :aria-disabled="guard.blocked('more:' + store.nextCursor) || undefined" @click="loadMore">
                            <ChevronDown /> {{ store.loadingMore ? 'Загружаем…' : 'Показать ещё' }}
                        </button>
                    </div>
                    <MessageReader v-if="selected" :summary="selected" :message="store.detail" :error="store.detailError"
                        @back="back" @retry="openMessage(selected.id)" />
                    <div v-else class="reader-placeholder"><MailOpen aria-hidden="true" /><p>Выберите письмо для чтения</p></div>
                </div>
                <div class="mail-status">
                    <span><Inbox aria-hidden="true" />{{ statusText }}</span>
                    <span v-if="store.checkedAt"><span class="last-checked-label">Проверено </span>{{ time.format(store.checkedAt) }}</span>
                </div>
                <span class="visually-hidden" role="status">{{ manualCheck === 'checked' ? 'Почта проверена. ' : '' }}{{ store.checkedAt ? 'Загружено писем: ' + store.messages.length : '' }}</span>
            </div>
        </section>
    </div>
</template>

<style scoped>
.check-mail.btn:hover, .check-mail.btn:active { transform: none; }
.check-mail[aria-busy="true"] { cursor: progress; }
.check-mail.checked { color: var(--mm-primary); background: var(--mm-primary-soft); }
.check-label { display: grid; }
.check-label > span { grid-area: 1 / 1; }
.sizing-label { visibility: hidden; }
</style>
