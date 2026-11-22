<script setup lang="ts">
import { ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import PanelHeading from './PanelHeading.vue'
import PanelIcon from './PanelIcon.vue'
import PanelLetters from './PanelLetters.vue'
import PanelPagination from './PanelPagination.vue'
import { formatCount } from './format'
import type { ArchivePage } from './types'

const props = defineProps<{ page: ArchivePage, refresh?: 'loading' | 'failed' }>()
defineEmits<{ retry: [] }>()
const router = useRouter()
const recipient = ref(props.page.recipient)
watch(() => props.page.recipient, value => { recipient.value = value })

function search() {
    const address = recipient.value.trim()
    return router.push({ path: '/admin/archive', query: address ? { recipient: address } : {} })
}
</script>

<template>
    <PanelHeading
        :title="page.user.is_admin ? 'Архив писем' : 'Моя почта'"
        :user="page.user"
        :description="page.user.is_admin ? undefined : page.user.email"
    />
    <form v-if="page.user.is_admin" class="card search-form" method="get" action="/admin/archive" @submit.prevent="search">
        <label for="recipient">Поиск по получателю</label>
        <div class="search-row">
            <span class="input-icon">
                <PanelIcon name="search" />
                <input id="recipient" v-model="recipient" type="search" name="recipient" placeholder="Адрес целиком" maxlength="254">
            </span>
            <button class="button primary" type="submit">Найти</button>
            <RouterLink v-if="page.recipient" class="text-action" to="/admin/archive">Сбросить</RouterLink>
        </div>
    </form>
    <section class="card mail-card">
        <div class="card-heading"><h2>Входящие</h2><span v-if="!refresh" class="count">{{ formatCount(page.messages.total) }}</span></div>
        <p v-if="refresh === 'loading'" role="status">Обновляем список писем…</p>
        <p v-if="refresh === 'failed'" role="status">
            Не удалось обновить список писем.
            <button type="button" class="button secondary" @click="$emit('retry')">Повторить</button>
        </p>
        <PanelLetters
            v-if="!refresh || page.messages.data.length"
            :letters="page.messages.data"
            :is-admin="page.user.is_admin"
            :list-query="page.listQuery"
            :empty-label="page.recipient ? 'Для этого адреса писем пока нет' : 'Писем пока нет'"
            :empty-hint="page.recipient ? 'Проверьте адрес получателя или сбросьте поиск.' : 'Здесь появится входящая почта.'"
        />
        <PanelPagination v-if="!refresh" :pages="page.messages" label="Страницы писем" />
    </section>
</template>
