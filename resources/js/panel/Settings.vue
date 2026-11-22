<script setup lang="ts">
import { ref } from 'vue'
import PanelHeading from './PanelHeading.vue'
import PanelIcon from './PanelIcon.vue'
import type { SettingsPage } from './types'
import { usePanelForm } from './usePanelForm'
import { notifyMailboxSessionChanged } from '../mailboxSocket'

const props = defineProps<{ page: SettingsPage }>()
const emit = defineEmits<{ updated: [message?: string] }>()
const { state, submit, messageFor } = usePanelForm(() => props.page.user.is_admin)
const domainsPath = '/admin/settings/domains'
const issuancePath = '/admin/settings/issuance'
const passwordPath = '/admin/settings/password'
const issuanceNotice = ref('')

async function saveIssuance(event: SubmitEvent) {
    issuanceNotice.value = ''
    const result = await submit(event, 'PATCH')
    issuanceNotice.value = result?.message ?? ''
}

async function save(event: SubmitEvent, method: 'POST' | 'PATCH' | 'PUT') {
    const form = event.currentTarget as HTMLFormElement
    const result = await submit(event, method)
    if (!result) return
    if (method === 'PUT') notifyMailboxSessionChanged()
    if (method === 'POST' || method === 'PUT') form.reset()
    emit('updated', result.message)
}
</script>

<template>
    <PanelHeading title="Настройки" :user="page.user" />
    <div class="settings-grid">
        <section class="card form-card domain-settings">
            <h2>Почтовые домены</h2>
            <p class="description">Направьте MX-запись домена на <code>{{ page.mxHost }}</code> и добавьте домен. Отправьте письмо на любой его адрес и найдите полный адрес получателя в <RouterLink class="text-action" to="/admin/archive">архиве</RouterLink>. Включите выдачу, чтобы посетители могли получать новые временные адреса. Постоянный ящик можно создать в панели в обоих режимах.</p>
            <ul class="box-list">
                <li v-for="domain in page.domains" :key="domain.id">
                    <div><strong>{{ domain.name }}</strong><p class="hint">{{ domain.issuance_enabled ? 'Приём и выдача' : 'Только приём' }}</p></div>
                    <form method="post" :action="domainsPath + '/' + domain.id" @submit.prevent="save($event, 'PATCH')">
                        <input type="hidden" name="issuance_enabled" :value="domain.issuance_enabled ? '0' : '1'">
                        <button class="button secondary" type="submit" :disabled="state.pending"
                            :aria-label="(domain.issuance_enabled ? 'Остановить выдачу на ' : 'Включить выдачу на ') + domain.name">
                            {{ domain.issuance_enabled ? 'Остановить выдачу' : 'Включить выдачу' }}
                        </button>
                        <template v-if="state.action === domainsPath + '/' + domain.id">
                            <p v-if="state.fields.issuance_enabled" class="error" role="alert">Не удалось изменить режим домена.</p>
                            <p v-if="state.message" class="error" role="alert">{{ state.message }}</p>
                        </template>
                    </form>
                </li>
            </ul>
            <form class="stack-form" method="post" :action="domainsPath" @submit.prevent="save($event, 'POST')">
                <div>
                    <label for="domain-name">Новый домен</label>
                    <input id="domain-name" name="name" spellcheck="false" autocapitalize="none" placeholder="inbox.example.com" required
                        :aria-invalid="state.fields.name ? true : undefined" :aria-describedby="state.fields.name ? 'name-error' : undefined">
                    <p v-if="state.fields.name" id="name-error" class="error" role="alert">{{ state.fields.name[0] }}</p>
                </div>
                <p v-if="messageFor(domainsPath)" class="error" role="alert">{{ state.message }}</p>
                <button class="button primary" type="submit" :disabled="state.pending">Добавить домен</button>
            </form>
        </section>
        <section class="card form-card">
            <span class="large-icon"><PanelIcon name="inbox" /></span><h2>Временные ящики</h2>
            <p class="description">Пауза остановит выдачу новых адресов. Уже выданные ящики продолжат получать почту до конца своего срока.</p>
            <form class="stack-form" method="post" :action="issuancePath" @submit.prevent="saveIssuance">
                <label class="toggle-field" for="guest-issuance">
                    <span>Выдавать гостевые адреса</span>
                    <input type="hidden" name="guest_issuance_enabled" value="0">
                    <input class="switch" type="checkbox" name="guest_issuance_enabled" id="guest-issuance" value="1"
                        :checked="page.guestIssuanceEnabled" :disabled="state.pending" @change="issuanceNotice = ''"
                        :aria-describedby="state.fields.guest_issuance_enabled ? 'guest-issuance-error' : undefined">
                </label>
                <p v-if="state.fields.guest_issuance_enabled" id="guest-issuance-error" class="error" role="alert">Не удалось изменить режим выдачи.</p>
                <p v-if="messageFor(issuancePath)" class="error" role="alert">{{ state.message }}</p>
                <button class="button primary" type="submit" :disabled="state.pending">Сохранить</button>
                <p v-if="issuanceNotice" class="notice success" role="status">{{ issuanceNotice }}</p>
            </form>
        </section>
        <section class="card form-card">
            <span class="large-icon"><PanelIcon name="shield-check" /></span><h2>Пароль администратора</h2>
            <p class="description">{{ page.user.email }}</p>
            <form class="stack-form" method="post" :action="passwordPath" @submit.prevent="save($event, 'PUT')">
                <div>
                    <label for="current-password">Текущий пароль</label>
                    <input id="current-password" type="password" name="current_password" autocomplete="current-password" required
                        :aria-invalid="state.fields.current_password ? true : undefined"
                        :aria-describedby="state.fields.current_password ? 'current-password-error' : undefined">
                    <p v-if="state.fields.current_password" id="current-password-error" class="error" role="alert">Проверьте текущий пароль.</p>
                </div>
                <div>
                    <label for="new-password">Новый пароль</label>
                    <input id="new-password" type="password" name="password" autocomplete="new-password" minlength="12" required
                        :aria-invalid="state.fields.password ? true : undefined"
                        :aria-describedby="state.fields.password ? 'password-hint password-error' : 'password-hint'">
                    <p class="hint" id="password-hint">Не менее 12 символов.</p>
                </div>
                <div>
                    <label for="confirm-password">Повторите новый пароль</label>
                    <input id="confirm-password" type="password" name="password_confirmation" autocomplete="new-password" required
                        :aria-describedby="state.fields.password ? 'password-error' : undefined">
                </div>
                <p v-if="state.fields.password" id="password-error" class="error" role="alert">{{ state.fields.password[0] }}</p>
                <p v-if="messageFor(passwordPath)" class="error" role="alert">{{ state.message }}</p>
                <button class="button primary" type="submit" :disabled="state.pending">Изменить пароль</button>
            </form>
        </section>
    </div>
</template>
