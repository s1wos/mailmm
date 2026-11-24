<script setup lang="ts">
import { computed } from 'vue'
import { splitMailLinks } from './mailLinks'

const props = defineProps<{ text: string }>()
const parts = computed(() => splitMailLinks(props.text))
</script>

<template>
    <template v-for="(part, index) in parts" :key="index">
        <a v-if="part.href" :href="part.href" target="_blank" rel="noopener noreferrer">{{ part.text }}</a>
        <template v-else>{{ part.text }}</template>
    </template>
</template>

<style scoped>
a { color: var(--mm-primary); text-decoration: underline; }
</style>
