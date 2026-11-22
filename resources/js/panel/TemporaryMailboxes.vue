<script setup lang="ts">
import PanelHeading from './PanelHeading.vue'
import PanelIcon from './PanelIcon.vue'
import PanelPagination from './PanelPagination.vue'
import { formatCount, formatTimestamp } from './format'
import type { TemporaryMailboxesPage } from './types'

defineProps<{ page: TemporaryMailboxesPage, refresh?: 'loading' | 'failed' }>()
defineEmits<{ refresh: [] }>()
</script>

<template>
    <PanelHeading title="Временные ящики" :user="page.user" />
    <section class="card boxes-card" aria-labelledby="active-mailboxes">
        <div class="card-heading">
            <div class="list-title">
                <h2 id="active-mailboxes">Активные ящики</h2>
                <span v-if="!refresh" class="count">{{ formatCount(page.mailboxes.total) }}</span>
            </div>
            <button type="button" class="button secondary" :disabled="refresh === 'loading'" @click="$emit('refresh')">
                {{ refresh === 'failed' ? 'Повторить' : 'Обновить' }}
            </button>
        </div>
        <p v-if="refresh === 'loading'" role="status">Обновляем список ящиков…</p>
        <p v-if="refresh === 'failed'" role="status">Не удалось обновить список ящиков.</p>
        <ul v-if="page.mailboxes.data.length" class="box-list">
            <li v-for="box in page.mailboxes.data" :key="box.id">
                <div class="box-address">
                    <span class="tile-icon lilac"><PanelIcon name="inbox" /></span>
                    <RouterLink :to="{ path: '/admin/archive', query: { recipient: box.email } }">{{ box.email }}</RouterLink>
                </div>
                <dl class="box-facts">
                    <div>
                        <dt>Создан · UTC</dt>
                        <dd><time v-if="box.created_at" :datetime="box.created_at">{{ formatTimestamp(box.created_at) }}</time><span v-else>—</span></dd>
                    </div>
                    <div>
                        <dt>Истекает · UTC</dt>
                        <dd><time :datetime="box.expires_at">{{ formatTimestamp(box.expires_at) }}</time></dd>
                    </div>
                    <div><dt>Писем</dt><dd>{{ formatCount(box.messages_count) }}</dd></div>
                </dl>
            </li>
        </ul>
        <div v-else-if="!refresh" class="empty-state">
            <span class="empty-icon"><PanelIcon name="timer" /></span>
            <strong>Активных временных ящиков нет</strong>
        </div>
        <PanelPagination v-if="!refresh" :pages="page.mailboxes" label="Страницы временных ящиков" />
    </section>
</template>

<style scoped>
.list-title { display: flex; align-items: center; gap: .6rem; }
.box-list > li { align-items: center; gap: .65rem 1.35rem; }
.box-address { flex: 1 1 13rem; }
.box-facts { display: flex; flex-wrap: wrap; gap: .4rem 1.35rem; }
.box-facts dt { font-size: .72rem; color: var(--muted); }
.box-facts dd { font-size: .82rem; font-variant-numeric: tabular-nums; }
</style>
