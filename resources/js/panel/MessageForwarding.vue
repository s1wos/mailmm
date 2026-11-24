<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import type { ForwardAvailability } from '../model/types'
import { describeForwardError, newForwardId, readPendingForward, savePendingForward } from './forwarding'
import { formatTimestamp } from './format'
import { PanelHttpError, panelRequest } from './http'
import type { MailForward } from './types'

const props = defineProps<{
    userId: number, messageId: number, availability: ForwardAvailability, initialForwards: MailForward[],
}>()
const route = useRoute()
const path = '/admin/messages/' + props.messageId + '/forwards'
const labels: Record<MailForward['status'], string> = {
    queued: 'В очереди', preparing: 'Подготовка письма', submitting: 'Идёт передача',
    accepted: 'Передано почтовому серверу', failed: 'Ошибка отправки', unknown: 'Результат отправки неизвестен',
    cancelled: 'Отменено', bounced: 'Письмо вернулось',
}
const forwards = ref([...props.initialForwards])
const draft = ref<ReturnType<typeof readPendingForward>>(null)
const expanded = ref(false)
const pending = ref(false)
const denied = ref(false)
const unavailable = ref(props.availability.reason)
const to = ref('')
const error = ref('')
const pollError = ref('')
const input = ref<HTMLInputElement>()
const trigger = ref<HTMLButtonElement>()
const lifetime = new AbortController()
let timer: ReturnType<typeof setTimeout> | undefined
let polling = false

function setDraft(value: typeof draft.value) {
    draft.value = value
    savePendingForward(props.userId, props.messageId, value)
}

async function open() {
    expanded.value = true
    await nextTick()
    input.value?.focus()
}

async function close() {
    expanded.value = false
    error.value = ''
    to.value = ''
    await nextTick()
    trigger.value?.focus({ preventScroll: true })
}

function accessLost(cause: unknown) {
    if (!(cause instanceof PanelHttpError) || ![401, 403, 404, 409, 419].includes(cause.status)) return false
    denied.value = true
    forwards.value = []
    error.value = cause.message
    return true
}

async function send() {
    if (pending.value || denied.value || !props.availability.available || unavailable.value) return
    const target = draft.value?.to ?? to.value.trim()
    if (!target) return
    pending.value = true
    error.value = ''
    try {
        const payload = draft.value ?? { to: target, request_id: newForwardId() }
        setDraft(payload)
        const { forward } = await panelRequest<{ forward: MailForward }>(path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Panel-User': String(props.userId) },
            body: JSON.stringify(payload),
            signal: AbortSignal.any([lifetime.signal, AbortSignal.timeout(20_000)]),
        })
        if (lifetime.signal.aborted || denied.value) return
        setDraft(null)
        forwards.value = [forward, ...forwards.value.filter(item => item.id !== forward.id)].slice(0, 10)
        await close()
        schedulePoll()
    } catch (cause) {
        if (lifetime.signal.aborted || accessLost(cause)) return
        if (cause instanceof PanelHttpError && cause.status < 500) {
            setDraft(null)
            if (cause.code === 'mode_disabled') unavailable.value = cause.code
            error.value = Object.values(cause.errors)[0]?.[0] ?? (cause.code ? describeForwardError(cause.code) : cause.message)
        } else error.value = 'Ответ сервера не получен. Повторите запрос, чтобы узнать результат отправки.'
    } finally {
        pending.value = false
    }
}

function pendingOperations() {
    return forwards.value.filter(item => ['queued', 'preparing', 'submitting'].includes(item.status))
}
function schedulePoll() {
    clearTimeout(timer)
    if (!lifetime.signal.aborted && !denied.value && !globalThis.document?.hidden && pendingOperations().length) {
        timer = setTimeout(refreshStatuses, 5_000)
    }
}
async function refreshStatuses() {
    if (polling || denied.value || globalThis.document?.hidden || lifetime.signal.aborted) return
    polling = true
    pollError.value = ''
    try {
        for (const item of pendingOperations()) {
            const { forward } = await panelRequest<{ forward: MailForward }>(path + '/' + item.id, {
                headers: { 'X-Panel-User': String(props.userId) },
                signal: AbortSignal.any([lifetime.signal, AbortSignal.timeout(15_000)]),
            })
            if (lifetime.signal.aborted || denied.value) return
            forwards.value = forwards.value.map(previous => previous.id === forward.id ? forward : previous)
        }
    } catch (cause) {
        if (!lifetime.signal.aborted && !accessLost(cause)) pollError.value = 'Не удалось обновить статус. Проверим ещё раз.'
    } finally {
        polling = false
        schedulePoll()
    }
}

watch(() => route.hash, hash => {
    if (hash === '#forward' && props.availability.available && !unavailable.value && !denied.value) void open()
})
onMounted(() => {
    const saved = readPendingForward(props.userId, props.messageId)
    if (saved && forwards.value.some(item => item.request_id === saved.request_id)) setDraft(null)
    else if (saved) {
        draft.value = saved
        to.value = saved.to
        error.value = 'Ответ на предыдущий запрос не получен. Повторите его, чтобы узнать результат.'
    }
    if (props.availability.available && (draft.value || route.hash === '#forward')) void open()
    schedulePoll()
    globalThis.document?.addEventListener('visibilitychange', schedulePoll)
})
onBeforeUnmount(() => {
    clearTimeout(timer)
    lifetime.abort()
    globalThis.document?.removeEventListener('visibilitychange', schedulePoll)
})
</script>

<template>
    <section class="message-forward" aria-label="Пересылка письма">
        <template v-if="!denied">
            <template v-if="availability.available && !unavailable">
                <button v-if="!expanded" id="forward" ref="trigger" class="button secondary" type="button" aria-expanded="false" @click="open">Переслать</button>
                <form v-else class="stack-form" method="post" :action="path" @submit.prevent="send">
                    <div>
                        <label for="forward">Кому</label>
                        <input id="forward" ref="input" v-model="to" name="to" type="text" inputmode="email" required maxlength="254" autocomplete="off" autocapitalize="none" spellcheck="false"
                            :readonly="pending || !!draft" aria-describedby="forward-contents">
                        <p id="forward-contents" class="hint">
                            В поле «От кого» получатель увидит адрес исходного отправителя.
                            <template v-if="availability.content === 'archive'">Если его адрес не сохранился, отправителем будет указан MailMM. Перешлём текст письма и сохранённые вложения. Оформление сохраним, если в архиве есть HTML-версия.</template>
                            <template v-else>Перешлём письмо с его оформлением, изображениями и вложениями.</template>
                        </p>
                    </div>
                    <div class="forward-actions">
                        <button class="button primary" type="submit" :disabled="pending">
                            {{ pending ? 'Отправляем…' : draft ? 'Повторить запрос' : 'Отправить' }}
                        </button>
                        <button v-if="!draft" class="button secondary" type="button" :disabled="pending" @click="close">Отменить</button>
                    </div>
                </form>
            </template>
            <p v-else class="hint">Ручная пересылка выключена.</p>
        </template>
        <p v-if="error" class="error" role="alert">{{ error }}</p>
        <div role="status" aria-live="polite">
            <ul v-if="forwards.length" class="forward-history" aria-label="Последние пересылки">
                <li v-for="item in forwards" :key="item.id">
                    <strong>{{ labels[item.status] }}</strong>
                    <span>{{ item.mode === 'auto' ? 'Автопересылка' : 'Ручная пересылка' }} · {{ item.destination }}</span>
                    <time :datetime="item.created_at">{{ formatTimestamp(item.created_at) }} UTC</time>
                    <span v-if="item.error_code">{{ describeForwardError(item.error_code) }}</span>
                    <span v-if="item.status === 'unknown'">Письмо могло дойти. Уточните у получателя перед повторной отправкой.</span>
                </li>
            </ul>
            <p v-if="pollError" class="hint">{{ pollError }}</p>
        </div>
    </section>
</template>

<style scoped>
.message-forward { border-top: 1px solid var(--line); margin-top: 1.5rem; padding-top: 1rem; }
.stack-form { max-width: 30rem; margin-top: 0; }
.forward-actions { display: flex; flex-wrap: wrap; gap: .65rem; }
.forward-history { padding: 0; list-style: none; margin-top: 1rem; }
.forward-history li { display: grid; gap: .2rem; margin-top: .7rem; font-size: .85rem; overflow-wrap: anywhere; }
.forward-history time { font-size: .76rem; color: var(--muted); }
</style>
