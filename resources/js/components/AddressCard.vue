<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { AtSign, Check, Clock3, Copy, Infinity as InfinityIcon, Repeat2 } from '@lucide/vue'
import { useEmailStore } from '../stores/emailStore'
import { useActionGuard } from '../useActionGuard'
import type { AddressFormat } from '../model/types'
import AddressPlane from './AddressPlane.vue'
import MailboxWarning from './MailboxWarning.vue'

const props = defineProps<{ now: number }>()
const store = useEmailStore()
const guard = useActionGuard(() => store.isAdmin)
const dialog = ref<HTMLDialogElement | null>(null)
const domainSelect = ref<HTMLSelectElement | null>(null)
const cancelButton = ref<HTMLButtonElement | null>(null)
const preferredDomain = ref('')
const domain = computed({
    get: () => store.domainOptions.some(option => option.domain === preferredDomain.value) ? preferredDomain.value : '',
    set: (value: string) => { preferredDomain.value = value },
})
const format = ref<AddressFormat>('random')
const choiceError = ref('')
const formats = computed(() => [...new Set(store.domainOptions
    .filter(option => !domain.value || option.domain === domain.value)
    .flatMap(option => option.formats))])
const canConfirm = computed(() => !!store.mailbox?.expires_at && !store.optionsLoading && !store.rotating
    && formats.value.includes(format.value))
const addressText = ref<HTMLElement | null>(null)
const copyState = ref<'idle' | 'copied' | 'manual'>('idle')
const copyKey = computed(() => 'copy:' + store.mailbox?.id)
const copyBlocked = computed(() => guard.blocked(copyKey.value) || (!store.isAdmin && copyState.value !== 'idle'))
const copyFocus = ref<HTMLButtonElement | null>(null)
const flight = ref(0)
let lastMailboxId = store.mailbox?.id
let feedbackTimer: number | undefined
let copyVersion = 0

const permanent = computed(() => store.mailbox?.expires_at === null)
const rotationWarning = computed(() => store.rotationError && store.mailbox
    ? store.rotationError + ' Текущий адрес доступен.'
    : store.rotationError)
const addressParts = computed(() => {
    const address = store.mailbox?.email ?? ''
    const separator = address.lastIndexOf('@')
    return [address.slice(0, separator), address.slice(separator)]
})
const remaining = computed(() => {
    if (!store.mailbox?.expires_at) return ''
    const minutes = Math.max(0, Math.ceil((Date.parse(store.mailbox.expires_at) - props.now) / 60000))
    if (minutes < 60) return minutes + ' мин'
    return Math.floor(minutes / 60) + ' ч ' + String(minutes % 60).padStart(2, '0') + ' мин'
})
const expiryDate = new Intl.DateTimeFormat('ru', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
const fraction = computed(() => Math.min(1, Math.max(0, (Date.parse(store.mailbox?.expires_at ?? '') - props.now) / 86400000)))
const secondHandAngle = computed(() => (Math.floor(props.now / 1000) % 60) * 6 + 'deg')
watch(formats, available => {
    if (!available.includes(format.value)) format.value = 'random'
})
watch(() => store.mailbox?.id, id => {
    copyVersion++
    clearTimeout(feedbackTimer)
    copyState.value = 'idle'
    dialog.value?.close()
    if (id === undefined) return
    if (lastMailboxId !== undefined && lastMailboxId !== id) flight.value++
    lastMailboxId = id
})
onBeforeUnmount(() => {
    copyVersion++
    clearTimeout(feedbackTimer)
})

function copyAddress() {
    if (copyBlocked.value) return
    return guard.run(copyKey.value, 0, writeAddress)
}

async function writeAddress() {
    const address = store.mailbox?.email
    if (!address) return
    const version = ++copyVersion
    clearTimeout(feedbackTimer)
    try {
        await navigator.clipboard.writeText(address)
        if (version !== copyVersion || address !== store.mailbox?.email) return
        copyState.value = 'copied'
    } catch {
        if (version !== copyVersion || address !== store.mailbox?.email || !addressText.value) return
        const range = document.createRange()
        range.selectNodeContents(addressText.value)
        const selection = window.getSelection()
        selection?.removeAllRanges()
        selection?.addRange(range)
        copyState.value = 'manual'
        try {
            if (document.execCommand('copy')) copyState.value = 'copied'
        } catch {}
    }
    feedbackTimer = window.setTimeout(() => { copyState.value = 'idle' }, 2000)
}

function loadOptions() {
    return guard.run('options', 1000, () => store.loadOptions())
}

function openRotation() {
    if (dialog.value?.open || !store.mailbox || store.rotating) return
    domain.value = addressParts.value[1].slice(1)
    format.value = 'random'
    choiceError.value = ''
    store.rotationError = ''
    dialog.value?.showModal()
    void loadOptions()
}

async function confirmRotation() {
    if (!canConfirm.value) return
    choiceError.value = ''
    const correction = await store.rotate({ domain: domain.value || undefined, format: format.value })
    if (correction) {
        choiceError.value = correction
        await nextTick()
        if (dialog.value?.open) (canConfirm.value ? domainSelect.value : cancelButton.value)?.focus()
        return
    }
    dialog.value?.close()
    await nextTick()
    if (store.mailbox) copyFocus.value?.focus({ preventScroll: true })
}
</script>

<template>
    <div class="address-grid">
        <section class="address-card" aria-labelledby="address-label">
            <AddressPlane :key="flight" :flying="flight > 0" />
            <h2 id="address-label"><AtSign aria-hidden="true" /> {{ permanent ? 'Ваш постоянный адрес' : 'Ваш временный адрес' }}</h2>
            <button v-if="store.mailbox" type="button" class="address-value address-copy" dir="ltr"
                :aria-label="`Скопировать адрес ${store.mailbox.email}`" :aria-disabled="copyBlocked || undefined" @click="copyAddress">
                <span ref="addressText">{{ addressParts[0] }}<wbr>{{ addressParts[1] }}</span>
            </button>
            <p v-else class="address-value address-status">{{ store.rotating ? 'Меняем адрес…' : store.issuancePaused ? 'Выдача приостановлена' : store.error ? 'Адрес недоступен' : 'Получаем адрес…' }}</p>
            <div class="address-actions">
                <button ref="copyFocus" type="button" class="btn copy-btn" :disabled="!store.mailbox" :aria-disabled="copyBlocked || undefined" @click="copyAddress">
                    <component :is="copyState === 'copied' ? Check : Copy" aria-hidden="true" />
                    {{ copyState === 'copied' ? 'Скопировано' : 'Скопировать' }}
                </button>
                <button v-if="!permanent" type="button" class="btn change-btn" :disabled="!store.mailbox || store.rotating" @click="openRotation">
                    <Repeat2 aria-hidden="true" /> Сменить адрес
                </button>
            </div>
            <span class="visually-hidden" role="status">{{ copyState === 'copied' ? 'Адрес скопирован' : '' }}</span>
            <MailboxWarning :message="copyState === 'manual' ? 'Адрес выделен. Скопируйте его через меню браузера.' : ''" />
            <MailboxWarning :message="rotationWarning" @dismiss="store.rotationError = ''" />
        </section>
        <section class="expiry-card" aria-labelledby="expiry-label">
            <h2 id="expiry-label">{{ permanent ? 'Тип ящика' : 'Срок действия' }}</h2>
            <div class="expiry-details">
                <div class="expiry-dial" aria-hidden="true" :style="{ '--time-left': (permanent ? 1 : fraction || 0) + 'turn' }">
                    <InfinityIcon v-if="permanent" />
                    <svg v-else-if="store.mailbox" class="expiry-clock" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10" stroke-width="1.6" />
                        <path d="M8.6 9.5 12 12l4.7-3.6" stroke-width="1.8" />
                        <path class="expiry-second-hand" d="M12 14v-9.7" stroke-width="1.1"
                            :style="{ '--second-angle': secondHandAngle }" />
                        <circle cx="12" cy="12" r="1.2" fill="currentColor" stroke="none" />
                    </svg>
                    <Clock3 v-else />
                </div>
                <div class="expiry-value">
                    <strong>{{ permanent ? 'Бессрочный' : store.mailbox ? remaining : '—' }}</strong>
                    <span v-if="store.mailbox?.expires_at">До {{ expiryDate.format(new Date(store.mailbox.expires_at)) }}</span>
                    <span v-else-if="permanent">Вход по паролю</span>
                </div>
            </div>
        </section>
    </div>
    <dialog v-if="!permanent" ref="dialog" class="modal" aria-labelledby="reset-title" aria-describedby="reset-description"
        @cancel="store.rotating && $event.preventDefault()">
        <div class="modal-symbol"><Repeat2 aria-hidden="true" /></div>
        <h2 id="reset-title">Сменить адрес?</h2>
        <p id="reset-description">После смены адреса вы потеряете доступ к письмам и вложениям текущего ящика.</p>
        <form @submit.prevent="guard.run('rotate', 1000, confirmRotation)">
            <div class="address-field">
                <div>
                    <label for="address-domain">Домен</label>
                    <span class="domain-loading" :class="{ 'sizing-label': !store.optionsLoading }" role="status">Загружаем домены…</span>
                </div>
                <select id="address-domain" ref="domainSelect" v-model="domain" :disabled="store.optionsLoading || store.rotating || !store.domainOptions.length">
                    <option value="">Случайный домен</option>
                    <option v-for="option in store.domainOptions" :key="option.domain" :value="option.domain">{{ option.domain }}</option>
                </select>
            </div>
            <div class="address-field">
                <label for="address-format">Формат имени</label>
                <select id="address-format" v-model="format" :disabled="store.optionsLoading || store.rotating || !formats.length">
                    <option v-for="item in formats" :key="item" :value="item">
                        {{ item === 'words' ? 'Два английских слова' : 'Случайные буквы и цифры' }}
                    </option>
                </select>
            </div>
            <p v-if="format === 'words'" class="address-example">Пример адреса — <bdi>mint.fox@inbox.test</bdi></p>
            <p v-if="choiceError" role="alert">{{ choiceError }}</p>
            <p v-if="store.optionsError" role="alert">{{ store.optionsError }}
                <button type="button" class="text-btn" :aria-disabled="guard.blocked('options') || undefined" @click="loadOptions">Повторить</button>
            </p>
            <p v-else-if="!store.optionsLoading && !store.domainOptions.length && !choiceError" role="status">Нет доступных доменов для выдачи адреса.</p>
            <div class="modal-actions">
                <button ref="cancelButton" autofocus type="button" class="btn neutral-btn" :disabled="store.rotating" @click="dialog?.close()">Отмена</button>
                <button type="submit" class="btn solid-btn rotate-submit" :disabled="!canConfirm" :aria-disabled="guard.blocked('rotate') || undefined">
                    <span :class="{ 'sizing-label': store.rotating }">Сменить адрес</span>
                    <span :class="{ 'sizing-label': !store.rotating }">Меняем адрес…</span>
                </button>
            </div>
        </form>
    </dialog>
</template>

<style scoped>
.modal { --action-spacing: 20px; padding-bottom: var(--action-spacing); }
.address-field { display: grid; gap: 6px; margin-bottom: 14px; font-size: 14px; font-weight: 600; }
.domain-loading { margin-inline-start: .5em; color: var(--mm-muted); font-weight: 400; }
.address-field select {
    width: 100%; min-width: 0; padding: 9px 10px; font: inherit; font-weight: 400; color: var(--mm-ink);
    background: var(--mm-surface); border: 1px solid var(--mm-line); border-radius: 9px;
}
.address-example { overflow-wrap: anywhere; }
.modal-actions { margin-top: var(--action-spacing); }
.modal-actions .btn:is(:hover, :active) { transform: none; }
.rotate-submit { display: inline-grid; }
.rotate-submit > span { grid-area: 1 / 1; }
.sizing-label { visibility: hidden; }
@container (width > 550px) {
    .address-value { max-width: 71%; }
}
.expiry-dial .expiry-clock {
    width: min(62%, 32px);
    height: auto;
}
.expiry-second-hand {
    stroke: #9063b9;
    transform-origin: 12px 12px;
    transform: rotate(180deg);
}
@media (prefers-reduced-motion: no-preference) {
    .expiry-second-hand { transform: rotate(var(--second-angle)); }
}
</style>
