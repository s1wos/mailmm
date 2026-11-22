<script setup lang="ts">
import { computed } from 'vue'
import PanelIcon from './PanelIcon.vue'
import type { PanelUser } from './types'

const props = defineProps<{ user: PanelUser, active: string, signingOut: boolean }>()
defineEmits<{ logout: [] }>()
const items = computed(() => props.user.is_admin ? [
    ['/admin', 'layout-dashboard', 'Обзор'],
    ['/admin/archive', 'archive', 'Архив писем'],
    ['/admin/mailboxes', 'mails', 'Ящики'],
    ['/admin/temporary-mailboxes', 'timer', 'Временные ящики'],
    ['/admin/errors', 'triangle-alert', 'Ошибки'],
    ['/admin/settings', 'settings-2', 'Настройки'],
] as const : [['/admin', 'inbox', 'Моя почта']] as const)
</script>

<template>
    <nav class="nav-links" aria-label="Разделы панели">
        <RouterLink v-for="[path, icon, label] in items" :key="path" :to="path" :aria-current="path === active ? 'page' : undefined">
            <PanelIcon :name="icon" /><span>{{ label }}</span>
        </RouterLink>
    </nav>
    <div class="rail-secondary">
        <RouterLink to="/"><PanelIcon name="arrow-up-right" />Открыть почту</RouterLink>
        <RouterLink to="/help"><PanelIcon name="circle-help" />Помощь</RouterLink>
        <button type="button" :disabled="signingOut" @click="$emit('logout')"><PanelIcon name="log-out" />Выйти</button>
    </div>
</template>
