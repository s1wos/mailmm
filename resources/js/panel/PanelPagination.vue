<script setup lang="ts">
import PanelIcon from './PanelIcon.vue'
import type { PageNavigation } from './types'

defineProps<{ pages: PageNavigation, label: string }>()
</script>

<template>
    <nav v-if="pages.prev_page_url || pages.next_page_url" class="pager" :aria-label="label">
        <RouterLink v-if="pages.prev_page_url" class="button secondary" :to="$router.resolve(pages.prev_page_url)"><PanelIcon name="arrow-left" />Назад</RouterLink>
        <span>Страница {{ pages.current_page }}</span>
        <RouterLink v-if="pages.next_page_url" class="button secondary" :to="$router.resolve(pages.next_page_url)">Вперёд<PanelIcon name="arrow-right" /></RouterLink>
    </nav>
</template>
