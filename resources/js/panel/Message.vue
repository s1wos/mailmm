<script setup lang="ts">
import { RouterLink, useRouter } from 'vue-router'
import PanelIcon from './PanelIcon.vue'
import { formatTimestamp } from './format'
import type { MessagePage } from './types'
import { useActionGuard } from '../useActionGuard'

const props = defineProps<{ page: MessagePage }>()
const actions = useActionGuard(() => props.page.user.is_admin)
const router = useRouter()

function returnToList(event: MouseEvent) {
    const { back, panelLetterList } = router.options.history.state
    if (typeof back === 'string' && back === panelLetterList) {
        event.preventDefault()
        router.back()
    }
}
</script>

<template>
    <RouterLink class="back-link" :to="page.backUrl" @click.left.exact.capture="returnToList">
        <PanelIcon name="arrow-left" />{{ page.user.is_admin && page.backUrl === '/admin' ? 'К обзору' : 'К списку писем' }}
    </RouterLink>
    <article class="card message-card">
        <h1 class="icon-heading"><PanelIcon name="mail" /> {{ page.letter.subject || 'Без темы' }}</h1>
        <dl class="detail-list">
            <div><dt>Отправитель</dt><dd>{{ page.letter.from || 'Не указан' }}</dd></div>
            <div><dt>Получатель</dt><dd>{{ page.letter.recipient }}</dd></div>
            <div>
                <dt>Получено</dt>
                <dd><time :datetime="page.letter.received_at">{{ formatTimestamp(page.letter.received_at) }} UTC</time></dd>
            </div>
        </dl>
        <div class="message-body">{{ page.letter.body_text || 'Текст письма отсутствует.' }}</div>
        <template v-if="page.letter.attachments.length">
            <h2>Вложения <span class="count">{{ page.letter.attachments.length }}</span></h2>
            <ul class="attachments">
                <li v-for="attachment in page.letter.attachments" :key="attachment.url">
                    <a :href="attachment.url" download :aria-disabled="actions.blocked(attachment.url) || undefined"
                        @click="actions.run(attachment.url, 1000, () => true) || $event.preventDefault()">
                        <PanelIcon name="paperclip" /><span>{{ attachment.filename }}</span><PanelIcon name="download" />
                    </a>
                </li>
            </ul>
        </template>
    </article>
</template>
