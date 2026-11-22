<script setup lang="ts">
import PanelIcon from './PanelIcon.vue'
import type { PanelUser } from './types'

defineProps<{ title: string, user: PanelUser, description?: string }>()
</script>

<template>
    <header class="page-heading">
        <div><h1>{{ title }}</h1><p v-if="description">{{ description }}</p></div>
        <div class="identity" :title="user.email">
            <span class="identity-icon"><PanelIcon name="user-round" /></span>
            <span class="identity-text"><strong>{{ user.is_admin ? 'Администратор' : 'Владелец ящика' }}</strong><span>{{ user.email }}</span></span>
        </div>
    </header>
</template>
