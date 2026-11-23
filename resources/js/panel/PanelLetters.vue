<script setup lang="ts">
import { RouterLink } from 'vue-router'
import PanelIcon from './PanelIcon.vue'
import { formatTimestamp } from './format'
import type { PanelLetterSummary } from './types'

defineProps<{
    letters: PanelLetterSummary[]
    isAdmin: boolean
    listQuery: Record<string, string | number>
    emptyLabel?: string
    emptyHint: string
}>()
</script>

<template>
    <div v-if="!letters.length" class="empty-state">
        <span class="empty-icon"><PanelIcon name="inbox" /></span>
        <strong>{{ emptyLabel ?? 'Писем пока нет' }}</strong>
        <p>{{ emptyHint }}</p>
    </div>
    <div v-else class="letter-table">
        <div class="table-head mail-grid" :class="{ 'owner-grid': !isAdmin }" aria-hidden="true">
            <span>Письмо</span><span v-if="isAdmin">Получатель</span><span>Получено · UTC</span>
        </div>
        <ul class="table-list">
            <li v-for="letter in letters" :key="letter.id">
                <RouterLink
                    class="mail-row mail-grid"
                    :class="{ 'owner-grid': !isAdmin }"
                    :to="{ path: `/admin/messages/${letter.id}`, query: listQuery, state: { panelLetterList: $route.fullPath } }"
                >
                    <span class="mail-cell">
                        <span class="mail-icon"><PanelIcon name="mail" /></span>
                        <span class="cell-lines">
                            <strong>{{ letter.subject || 'Без темы' }}</strong>
                            <small>{{ letter.from || 'Отправитель не указан' }}</small>
                        </span>
                    </span>
                    <span v-if="isAdmin" class="recipient-cell" :title="letter.recipient">{{ letter.recipient }}</span>
                    <time :datetime="letter.received_at">{{ formatTimestamp(letter.received_at) }}</time>
                </RouterLink>
            </li>
        </ul>
    </div>
</template>
