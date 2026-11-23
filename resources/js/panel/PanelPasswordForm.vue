<script setup lang="ts">
import { computed, ref } from 'vue'
import { notifyMailboxSessionChanged } from '../mailboxSocket'
import type { usePanelForm } from './usePanelForm'

const props = defineProps<{ action: string, form: ReturnType<typeof usePanelForm> }>()
const { state } = props.form
const saving = computed(() => state.pending && state.action === props.action)
const notice = ref('')

function edited() {
    notice.value = ''
    props.form.edited()
}

async function save(event: SubmitEvent) {
    const element = event.currentTarget as HTMLFormElement
    notice.value = ''
    const result = await props.form.submit<{ message: string }>(event, 'PUT')
    if (!result) return
    element.reset()
    notice.value = result.message
    notifyMailboxSessionChanged()
}
</script>

<template>
    <form class="stack-form" method="post" :action="action" @input="edited" @submit.prevent="save">
        <div>
            <label for="current-password">Текущий пароль</label>
            <input id="current-password" type="password" name="current_password" autocomplete="current-password" required
                :readonly="saving" :aria-invalid="state.fields.current_password ? true : undefined"
                :aria-describedby="state.fields.current_password ? 'current-password-error' : undefined">
            <p v-if="state.fields.current_password" id="current-password-error" class="error" role="alert">Проверьте текущий пароль.</p>
        </div>
        <div>
            <label for="new-password">Новый пароль</label>
            <input id="new-password" type="password" name="password" autocomplete="new-password" minlength="12" required
                :readonly="saving" :aria-invalid="state.fields.password ? true : undefined"
                :aria-describedby="state.fields.password ? 'password-hint password-error' : 'password-hint'">
            <p class="hint" id="password-hint">Минимум 12 символов, максимум 72 байта.</p>
        </div>
        <div>
            <label for="confirm-password">Повторите новый пароль</label>
            <input id="confirm-password" type="password" name="password_confirmation" autocomplete="new-password" required
                :readonly="saving" :aria-describedby="state.fields.password ? 'password-error' : undefined">
        </div>
        <p v-if="state.fields.password" id="password-error" class="error" role="alert">{{ state.fields.password[0] }}</p>
        <p v-if="form.messageFor(action)" class="error" role="alert">{{ state.message }}</p>
        <button class="button primary" type="submit" :disabled="state.pending || form.blocked(action)">Изменить пароль</button>
        <p v-if="notice" class="notice success" role="status">{{ notice }}</p>
    </form>
</template>
