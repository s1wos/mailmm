<script setup lang="ts">
import { computed } from 'vue'
import PanelHeading from './PanelHeading.vue'
import PanelIcon from './PanelIcon.vue'
import type { ErrorsPage } from './types'

const props = defineProps<{ page: ErrorsPage, refreshFailed: boolean }>()
const unavailable = computed(() => Object.entries(props.page.collection.sources)
    .filter(([, source]) => !source.available).map(([name]) => name))
const complete = computed(() => props.page.collection.online && !unavailable.value.length && !props.refreshFailed)

const timestamp = new Intl.DateTimeFormat('ru-RU', {
    timeZone: 'UTC', dateStyle: 'short', timeStyle: 'medium',
})
</script>

<template>
    <PanelHeading title="Ошибки" :user="page.user" />
    <section class="card mail-card" aria-label="Журнал ошибок">
        <p v-if="refreshFailed" class="notice" role="status">Не удалось обновить журнал. Повторим через 10 секунд.</p>
        <p v-if="!page.collection.online" class="notice" role="status">Сборщик недоступен. Новые ошибки могут отсутствовать в журнале.</p>
        <p v-else-if="unavailable.length" class="notice" role="status">Нет данных от источников: {{ unavailable.join(', ') }}.</p>
        <details v-if="Object.keys(page.collection.sources).length" class="collection-health">
            <summary>Последняя успешная проверка источников</summary>
            <ul>
                <li v-for="(source, name) in page.collection.sources" :key="name">
                    {{ name }} ·
                    <time v-if="source.last_success_at" :datetime="source.last_success_at">{{ timestamp.format(new Date(source.last_success_at)) }} UTC</time>
                    <span v-else>Успешных проверок ещё не было</span>
                </li>
            </ul>
        </details>
        <div v-if="!page.entries.data.length && complete" class="empty-state">
            <span class="empty-icon"><PanelIcon name="check" /></span>
            <strong>Ошибок пока нет</strong>
        </div>
        <ul v-else-if="page.entries.data.length" class="table-list error-entries">
            <li v-for="entry in page.entries.data" :key="entry.id">
                <div class="error-meta">
                    <strong>{{ entry.level }}</strong>
                    <span>{{ entry.service }}</span>
                    <time :datetime="entry.occurred_at">{{ timestamp.format(new Date(entry.occurred_at)) }} UTC</time>
                </div>
                <pre>{{ entry.message }}</pre>
            </li>
        </ul>
        <nav v-if="$route.query.cursor || page.entries.next_page_url" class="pager" aria-label="Страницы журнала">
            <RouterLink v-if="$route.query.cursor" class="text-action" to="/admin/errors">К последним записям</RouterLink>
            <RouterLink v-if="page.entries.prev_page_url" class="button secondary" :to="page.entries.prev_page_url"><PanelIcon name="arrow-left" />Новее</RouterLink>
            <RouterLink v-if="page.entries.next_page_url" class="button secondary" :to="page.entries.next_page_url">Старее<PanelIcon name="arrow-right" /></RouterLink>
        </nav>
    </section>
</template>

<style scoped>
.collection-health { margin-bottom: 1rem; font-size: .875rem; }
.error-entries li { padding: .9rem 0; }
.error-entries li:first-child { padding-top: 0; }
.error-entries li:last-child { padding-bottom: 0; }
.error-meta { display: flex; flex-wrap: wrap; align-items: baseline; gap: .35rem .75rem; color: var(--muted); font-size: .78rem; }
.error-meta strong { color: #953521; text-transform: uppercase; }
.error-meta time { margin-inline-start: auto; font-variant-numeric: tabular-nums; }
.error-entries pre { margin: .45rem 0 0; font: .86rem/1.5 ui-monospace, monospace; white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
