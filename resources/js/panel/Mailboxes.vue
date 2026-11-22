<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import PanelHeading from './PanelHeading.vue'
import PanelIcon from './PanelIcon.vue'
import PanelPagination from './PanelPagination.vue'
import type { MailboxesPage } from './types'
import { usePanelForm } from './usePanelForm'

const props = defineProps<{ page: MailboxesPage }>()
const emit = defineEmits<{ navigate: [path: string] }>()
const createPath = '/admin/mailboxes'
const requestId = props.page.request_id
const options = ref(props.page.domains)
const domain = ref(options.value[0]?.domain ?? '')
const { state, submit, messageFor } = usePanelForm(() => props.page.user.is_admin, error => {
    if (error.domains) options.value = error.domains
})
const localPart = ref('')
const format = ref('random')
const selectedDomain = computed(() => options.value.find(option => option.domain === domain.value))
const anyWords = computed(() => options.value.some(option => option.formats.includes('words')))
const supportsWords = computed(() => selectedDomain.value?.formats.includes('words'))

watch(() => props.page.domains, domains => { options.value = domains })
watch(options, domains => {
    if (!domains.some(option => option.domain === domain.value)) domain.value = ''
})
watch(supportsWords, supported => {
    if (!supported) format.value = 'random'
})

function passwordPath(id: number) {
    return '/admin/mailboxes/' + id + '/password'
}

async function issue(event: SubmitEvent) {
    const result = await submit(event)
    if (result?.redirect) emit('navigate', result.redirect)
}
</script>

<template>
    <PanelHeading title="Ящики" :user="page.user" />
    <section v-if="page.credentials" class="card notice credentials" aria-labelledby="issued-title">
        <span class="tile-icon violet"><PanelIcon name="key-round" /></span>
        <div>
            <h2 id="issued-title">Данные для входа</h2>
            <p>Сохраните пароль и передайте владельцу. Пароль показывается один раз.</p>
            <dl class="detail-list">
                <div><dt>Логин</dt><dd><code>{{ page.credentials.email }}</code></dd></div>
                <div><dt>Пароль</dt><dd><code>{{ page.credentials.password }}</code></dd></div>
            </dl>
        </div>
    </section>
    <div class="management-grid">
        <section class="card boxes-card">
            <div class="card-heading"><h2>Постоянные ящики</h2></div>
            <ul class="box-list">
                <li v-for="box in page.mailboxes.data" :key="box.id">
                    <div class="box-address">
                        <span class="tile-icon lilac"><PanelIcon name="inbox" /></span>
                        <RouterLink :to="{ path: '/admin/archive', query: { recipient: box.email } }">{{ box.email }}</RouterLink>
                    </div>
                    <details class="key-options">
                        <summary><PanelIcon name="key-round" />Новый пароль</summary>
                        <form method="post" :action="passwordPath(box.id)" @submit.prevent="issue">
                            <p>После перевыпуска прежний пароль владельца перестанет действовать.</p>
                            <p v-if="messageFor(passwordPath(box.id))" class="error" role="alert">{{ state.message }}</p>
                            <button type="submit" class="button secondary" :disabled="state.pending">Перевыпустить пароль</button>
                        </form>
                    </details>
                </li>
                <li v-if="!page.mailboxes.data.length" class="empty-state">
                    <span class="empty-icon"><PanelIcon name="mails" /></span><strong>Пока нет постоянных ящиков</strong>
                </li>
            </ul>
            <PanelPagination :pages="page.mailboxes" label="Страницы ящиков" />
        </section>
        <section id="create-mailbox" class="card form-card">
            <span class="large-icon"><PanelIcon name="plus" /></span><h2>Создать ящик</h2>
            <template v-if="options.length">
                <p class="description">Владелец получит постоянный адрес и пароль для входа.</p>
                <form class="stack-form" method="post" :action="createPath" @submit.prevent="issue">
                    <input type="hidden" name="request_id" :value="requestId">
                    <div>
                        <label for="mailbox-domain">Домен</label>
                        <select id="mailbox-domain" v-model="domain" name="domain" required
                            :aria-invalid="state.fields.domain ? true : undefined" :aria-describedby="state.fields.domain ? 'domain-error' : undefined">
                            <option v-if="!domain" disabled value="">Выберите домен</option>
                            <option v-for="option in options" :key="option.domain" :value="option.domain">{{ option.domain }}</option>
                        </select>
                        <p v-if="state.fields.domain" id="domain-error" class="error" role="alert">{{ state.fields.domain[0] }}</p>
                    </div>
                    <div>
                        <label for="local_part">Имя до @</label>
                        <input id="local_part" v-model="localPart" name="local_part" :maxlength="selectedDomain?.limit"
                            pattern="[a-z0-9]+([._\-][a-z0-9]+)*" autocapitalize="none" spellcheck="false" placeholder="Например, team"
                            :aria-invalid="state.fields.local_part ? true : undefined"
                            :aria-describedby="state.fields.local_part ? 'address-hint address-error' : 'address-hint'">
                        <p class="hint" id="address-hint">Введите имя или оставьте поле пустым, чтобы подобрать его автоматически.</p>
                        <p v-if="state.fields.local_part" id="address-error" class="error" role="alert">{{ state.fields.local_part[0] }}</p>
                    </div>
                    <div>
                        <label for="mailbox-format">Формат автоматического имени</label>
                        <select id="mailbox-format" v-model="format" name="format" :disabled="localPart.length > 0"
                            :aria-invalid="state.fields.format ? true : undefined" :aria-describedby="state.fields.format ? 'format-error' : undefined">
                            <option value="random">Буквы и цифры</option>
                            <option v-if="anyWords" value="words" :disabled="!supportsWords">Два слова через точку</option>
                        </select>
                        <p v-if="state.fields.format" id="format-error" class="error" role="alert">{{ state.fields.format[0] }}</p>
                    </div>
                    <p v-if="messageFor(createPath)" class="error" role="alert">{{ state.message }}</p>
                    <p v-if="state.fields.request_id" class="error" role="alert">{{ state.fields.request_id[0] }}</p>
                    <button type="submit" class="button primary" :disabled="state.pending"><PanelIcon name="plus" />Создать ящик</button>
                </form>
            </template>
            <p v-else class="description">Добавьте почтовый домен в <RouterLink to="/admin/settings">настройках</RouterLink>, чтобы создать ящик.</p>
        </section>
    </div>
</template>
