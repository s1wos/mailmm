<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { describeForwardError } from './forwarding'
import { formatTimestamp } from './format'
import type { ForwardingRule } from './types'
import { usePanelForm } from './usePanelForm'

const props = defineProps<{ rule: ForwardingRule, serverTime: string }>()
const emit = defineEmits<{ updated: [rule: ForwardingRule], refresh: [] }>()
const form = usePanelForm()
const { state } = form
const path = '/admin/account/forwarding'
const editing = ref(false)
const destination = ref<HTMLInputElement>()
const code = ref<HTMLInputElement>()
const notice = ref('')
const waitingToResend = ref(false)
const problem = computed(() => props.rule.status === 'disabled'
    ? props.rule.disabled_reason ?? props.rule.last_error_code : props.rule.last_error_code)
const statusLabel = computed(() => {
    if (props.rule.status === 'disabled') return 'Выключена'
    if (!props.rule.sending_enabled) return props.rule.status === 'active' ? 'Отправка приостановлена' : 'Адрес сохранён'
    return props.rule.status === 'active' ? 'Включена' : 'Ожидает подтверждения'
})
let clockOffset = 0
let resendTimer: ReturnType<typeof setTimeout> | undefined

function updateResendTime() {
    clearTimeout(resendTimer)
    const remaining = Date.parse(props.rule.resend_at ?? '') - (Date.now() + clockOffset)
    waitingToResend.value = remaining > 0
    if (remaining > 0) resendTimer = setTimeout(updateResendTime, Math.min(remaining, 2_147_483_647))
}
onMounted(() => {
    watch(() => props.serverTime, value => {
        clockOffset = value ? Date.parse(value) - Date.now() : 0
        updateResendTime()
    }, { immediate: true })
    watch(() => props.rule.resend_at, updateResendTime)
})
onBeforeUnmount(() => clearTimeout(resendTimer))

async function edit() {
    editing.value = true
    state.fields = {}
    state.message = ''
    notice.value = ''
    await nextTick()
    destination.value?.focus()
}

async function save(event: SubmitEvent, method: string) {
    const buttonAction = (event.currentTarget as HTMLFormElement).getAttribute('action')
    notice.value = ''
    const response = await form.submit<{ forwarding: ForwardingRule, message?: string }>(event, method)
    if (!response) return
    editing.value = false
    emit('updated', response.forwarding)
    if (method === 'DELETE') notice.value = 'Ожидающие отправки отменены. Уже начатая передача может завершиться.'
    if (buttonAction === path + '/resend') notice.value = 'Код запрошен. Проверьте почту на адресе назначения.'
    if (response.forwarding.status === 'pending') {
        await nextTick()
        if (code.value) {
            code.value.value = ''
            code.value.focus()
        }
    }
}
</script>

<template>
    <section class="card form-card rule-card" aria-labelledby="auto-forwarding">
        <h2 id="auto-forwarding" tabindex="-1">Автопересылка</h2>
        <div role="status">
            <p class="rule-status">
                <strong>{{ statusLabel }}</strong>
                <span v-if="rule.destination"> · {{ rule.destination }}</span>
            </p>
            <p v-if="!rule.sending_enabled" class="hint">
                <template v-if="rule.status === 'disabled'">Можно заранее сохранить адрес. Когда отправка станет доступна, запросите код подтверждения.</template>
                <template v-else-if="rule.status === 'pending'">Когда отправка станет доступна, запросите код для подтверждения адреса.</template>
                <template v-else>После возобновления отправки новые письма будут пересылаться на подтверждённый адрес.</template>
            </p>
            <p v-else-if="rule.status === 'active' && rule.activated_at" class="hint">
                Действует с <time :datetime="rule.activated_at">{{ formatTimestamp(rule.activated_at) }} UTC</time>.
                У пересланных писем сохраняется исходный отправитель.
            </p>
            <template v-if="problem && problem !== 'owner_disabled'">
                <p v-if="rule.status === 'disabled' && rule.disabled_reason" class="error">{{ describeForwardError(problem) }}</p>
                <p v-else class="hint">Последнее предупреждение об отправке. {{ describeForwardError(problem) }}</p>
            </template>
            <p v-if="notice" class="hint">{{ notice }}</p>
        </div>
        <template v-if="editing">
            <form class="stack-form" :action="path" method="post" @input="form.edited" @submit.prevent="save($event, 'PUT')">
                <div>
                    <label for="forward-destination">Адрес назначения</label>
                    <input id="forward-destination" ref="destination" name="to" type="text" inputmode="email" maxlength="254" autocomplete="off" autocapitalize="none" spellcheck="false"
                        required :readonly="state.pending" :aria-invalid="!!state.fields.to" aria-describedby="forward-rule-help">
                    <p id="forward-rule-help" class="hint">Укажите внешний адрес.<template v-if="rule.sending_enabled"> На него придёт код подтверждения.</template></p>
                </div>
                <div>
                    <label for="forward-password">Текущий пароль</label>
                    <input id="forward-password" name="current_password" type="password" autocomplete="current-password" required
                        :readonly="state.pending" :aria-invalid="!!state.fields.current_password">
                </div>
                <p v-if="rule.status === 'active'" class="hint">При замене адреса прежняя пересылка выключится сразу.</p>
                <p class="hint">После подтверждения сюда будут приходить новые письма. Копии сохранятся в вашем ящике.</p>
                <div class="rule-actions">
                    <button class="button primary" :disabled="state.pending || form.blocked(path)">{{ rule.sending_enabled ? 'Получить код' : 'Сохранить адрес' }}</button>
                    <button class="button secondary" type="button" :disabled="state.pending" @click="editing = false; state.fields = {}; state.message = ''">Отменить</button>
                </div>
            </form>
        </template>
        <template v-else>
            <form v-if="rule.status === 'pending' && rule.sending_enabled && rule.code_expires_at" :key="rule.version" :action="path + '/verify'" class="stack-form" method="post"
                @input="form.edited" @submit.prevent="save($event, 'POST')">
                <input type="hidden" name="version" :value="rule.version">
                <div>
                    <label for="forward-code">Код из письма</label>
                    <input id="forward-code" ref="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6"
                        required :readonly="state.pending" :aria-invalid="!!state.fields.code">
                    <p v-if="rule.code_expires_at" class="hint">Код действует до
                        <time :datetime="rule.code_expires_at">{{ formatTimestamp(rule.code_expires_at) }} UTC</time>.
                    </p>
                </div>
                <button class="button primary" :disabled="state.pending || form.blocked(path + '/verify')">Подтвердить</button>
            </form>
            <div class="rule-actions">
                <form v-if="rule.status === 'pending' && rule.sending_enabled" :action="path + '/resend'" method="post" @submit.prevent="save($event, 'POST')">
                    <input type="hidden" name="version" :value="rule.version">
                    <button class="button secondary" :disabled="state.pending || waitingToResend || form.blocked(path + '/resend')">
                        {{ rule.code_expires_at ? 'Выслать код снова' : 'Получить код' }}
                    </button>
                    <small v-if="waitingToResend && rule.resend_at" class="hint">Доступно с {{ formatTimestamp(rule.resend_at) }} UTC</small>
                </form>
                <button type="button" class="button secondary" :disabled="state.pending" @click="edit">
                    {{ rule.status === 'disabled' ? 'Настроить' : 'Изменить адрес' }}
                </button>
                <form v-if="rule.status !== 'disabled'" :action="path" method="post" @submit.prevent="save($event, 'DELETE')">
                    <input type="hidden" name="version" :value="rule.version">
                    <button class="button secondary" :disabled="state.pending || form.blocked(path)">
                        {{ rule.status === 'pending' ? 'Отменить' : 'Выключить' }}
                    </button>
                </form>
            </div>
        </template>
        <div v-if="state.message || Object.keys(state.fields).length" class="error" role="alert">
            <p v-if="state.message">{{ state.message }}</p>
            <p v-for="(errors, field) in state.fields" :key="field">{{ errors[0] }}</p>
            <button v-if="state.fields.version" type="button" class="button secondary" @click="$emit('refresh')">Обновить правило</button>
        </div>
    </section>
</template>

<style scoped>
.rule-card { margin-bottom: 1rem; }
.rule-status { margin-top: .65rem; overflow-wrap: anywhere; }
.stack-form { max-width: 28rem; }
.rule-actions { display: flex; align-items: flex-start; flex-wrap: wrap; gap: .65rem; margin-top: .9rem; }
.stack-form .rule-actions { margin: 0; }
.rule-actions small { display: block; }
</style>
