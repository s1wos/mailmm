<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import MailboxCredentials from './MailboxCredentials.vue'
import PanelHeading from './PanelHeading.vue'
import PanelIcon from './PanelIcon.vue'
import PanelPagination from './PanelPagination.vue'
import type { MailboxesPage } from './types'
import { usePanelForm } from './usePanelForm'

type Credentials = NonNullable<MailboxesPage['credentials']>

const props = defineProps<{ page: MailboxesPage, awaitingPage?: boolean }>()
const emit = defineEmits<{ navigate: [path: string], credentialsIssued: [credentials: Credentials] }>()
const createPath = '/admin/mailboxes'
const options = ref(props.page.domains)
const domain = ref(options.value[0]?.domain ?? '')
const { state, submit, messageFor } = usePanelForm(() => props.page.user.is_admin, error => {
    if (error.domains) options.value = error.domains
})
const localPart = ref('')
const format = ref('random')
const createButton = ref<HTMLButtonElement>()
const receiptAbove = ref(false)
const createdCredentials = computed(() => props.page.credentials?.resetMailboxId ? null : props.page.credentials)
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

watch(createdCredentials, async credentials => {
    const button = createButton.value
    if (!button || (!credentials && !props.awaitingPage)) return
    const { top, bottom } = button.getBoundingClientRect()
    if (bottom <= 0 || top >= window.innerHeight) return
    if (credentials) receiptAbove.value = top > window.innerHeight / 2
    // Removing the previous receipt and growing the list on mobile can both move the button.
    await nextTick()
    if (button.isConnected) window.scrollBy(0, button.getBoundingClientRect().top - top)
})

function passwordPath(id: number) {
    return '/admin/mailboxes/' + id + '/password'
}

async function issue(event: SubmitEvent, resetMailboxId?: number) {
    const result = await submit<{ credentials?: Credentials, redirect?: string }>(event)
    if (result?.credentials) emit('credentialsIssued', { ...result.credentials, resetMailboxId })
    else if (result?.redirect) {
        localPart.value = ''
        emit('navigate', result.redirect)
    }
}
</script>

<template>
    <PanelHeading title="Ящики" :user="page.user" />
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
                        <form method="post" :action="passwordPath(box.id)" @submit.prevent="issue($event, box.id)">
                            <input type="hidden" name="return_credentials" value="1">
                            <p>После перевыпуска прежний пароль владельца перестанет действовать.</p>
                            <p v-if="messageFor(passwordPath(box.id))" class="error" role="alert">{{ state.message }}</p>
                            <button type="submit" class="button secondary" :disabled="state.pending || awaitingPage">Перевыпустить пароль</button>
                        </form>
                    </details>
                    <MailboxCredentials v-if="page.credentials?.resetMailboxId === box.id" :credentials="page.credentials" />
                </li>
                <li v-if="!page.mailboxes.data.length" class="empty-state">
                    <strong class="icon-heading"><PanelIcon name="mails" /> Пока нет постоянных ящиков</strong>
                </li>
            </ul>
            <PanelPagination :pages="page.mailboxes" label="Страницы ящиков" />
        </section>
        <section id="create-mailbox" class="card form-card">
            <h2 class="icon-heading"><PanelIcon name="plus" /> Создать ящик</h2>
            <p v-if="options.length" class="description">Владелец получит постоянный адрес и пароль для входа.</p>
            <p v-else class="description">Добавьте почтовый домен в <RouterLink to="/admin/settings">настройках</RouterLink>, чтобы создать ящик.</p>
            <form v-if="options.length || createdCredentials" class="stack-form" method="post" :action="createPath" @submit.prevent="issue">
                <template v-if="options.length">
                    <input type="hidden" name="request_id" :value="page.request_id">
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
                    <button ref="createButton" type="submit" class="button primary create-submit" :disabled="state.pending || awaitingPage"><PanelIcon name="plus" />Создать ящик</button>
                </template>
                <MailboxCredentials v-if="createdCredentials" :class="{ 'after-submit': !receiptAbove }" :credentials="createdCredentials" />
            </form>
        </section>
    </div>
</template>

<style scoped>
.create-submit { order: 1; }
.after-submit { order: 2; }
</style>
