<script setup lang="ts">
import { reactive } from 'vue'
import PanelHeading from './PanelHeading.vue'
import PanelIcon from './PanelIcon.vue'
import PanelPasswordForm from './PanelPasswordForm.vue'
import type { SettingsPage } from './types'
import { usePanelForm } from './usePanelForm'

const props = defineProps<{ page: SettingsPage }>()
const emit = defineEmits<{ domainsUpdated: [domains: SettingsPage['domains']] }>()
const panelForm = usePanelForm(() => props.page.user.is_admin)
const { state, submit, messageFor } = panelForm
const domainsPath = '/admin/settings/domains'
const issuancePath = '/admin/settings/issuance'
const notices = reactive<Record<string, string>>({})

async function save(event: SubmitEvent, method: 'POST' | 'PATCH') {
    const form = event.currentTarget as HTMLFormElement
    const action = form.getAttribute('action')!
    delete notices[action]
    const result = await submit<{ message: string, domains?: SettingsPage['domains'] }>(event, method)
    if (!result) return
    if (result.domains) emit('domainsUpdated', result.domains)
    if (method === 'POST') form.reset()
    notices[action] = result.message
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
                        <p v-if="notices[domainsPath + '/' + domain.id]" class="hint" role="status">{{ notices[domainsPath + '/' + domain.id] }}</p>
                    </form>
                </li>
            </ul>
            <form class="stack-form" method="post" :action="domainsPath" @input="delete notices[domainsPath]" @submit.prevent="save($event, 'POST')">
                <div>
                    <label for="domain-name">Новый домен</label>
                    <input id="domain-name" name="name" spellcheck="false" autocapitalize="none" placeholder="inbox.example.com" required
                        :readonly="state.pending && state.action === domainsPath"
                        :aria-invalid="state.fields.name ? true : undefined" :aria-describedby="state.fields.name ? 'name-error' : undefined">
                    <p v-if="state.fields.name" id="name-error" class="error" role="alert">{{ state.fields.name[0] }}</p>
                </div>
                <p v-if="messageFor(domainsPath)" class="error" role="alert">{{ state.message }}</p>
                <button class="button primary" type="submit" :disabled="state.pending">Добавить домен</button>
                <p v-if="notices[domainsPath]" class="notice success" role="status">{{ notices[domainsPath] }}</p>
            </form>
        </section>
        <section class="card form-card">
            <h2 class="icon-heading"><PanelIcon name="inbox" /> Временные ящики</h2>
            <p class="description">Пауза остановит выдачу новых адресов. Уже выданные ящики продолжат получать почту до конца своего срока.</p>
            <form class="stack-form" method="post" :action="issuancePath" @submit.prevent="save($event, 'PATCH')">
                <label class="toggle-field" for="guest-issuance">
                    <span>Выдавать гостевые адреса</span>
                    <input type="hidden" name="guest_issuance_enabled" value="0">
                    <input class="switch" type="checkbox" name="guest_issuance_enabled" id="guest-issuance" value="1"
                        :checked="page.guestIssuanceEnabled" :disabled="state.pending" @change="delete notices[issuancePath]"
                        :aria-describedby="state.fields.guest_issuance_enabled ? 'guest-issuance-error' : undefined">
                </label>
                <p v-if="state.fields.guest_issuance_enabled" id="guest-issuance-error" class="error" role="alert">Не удалось изменить режим выдачи.</p>
                <p v-if="messageFor(issuancePath)" class="error" role="alert">{{ state.message }}</p>
                <button class="button primary" type="submit" :disabled="state.pending">Сохранить</button>
                <p v-if="notices[issuancePath]" class="notice success" role="status">{{ notices[issuancePath] }}</p>
            </form>
        </section>
        <section class="card form-card">
            <h2 class="icon-heading"><PanelIcon name="shield-check" /> Пароль администратора</h2>
            <p class="description">{{ page.user.email }}</p>
            <PanelPasswordForm action="/admin/settings/password" :form="panelForm" />
        </section>
    </div>
</template>
