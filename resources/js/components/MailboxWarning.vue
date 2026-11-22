<script setup lang="ts">
import { onMounted, ref, watch, watchEffect } from 'vue'
import { TriangleAlert, X } from '@lucide/vue'
import { useActionGuard } from '../useActionGuard'
import { useEmailStore } from '../stores/emailStore'

const props = defineProps<{ message: string }>()
const emit = defineEmits<{ dismiss: [] }>()
const store = useEmailStore()
const actions = useActionGuard(() => store.isAdmin)
const dismissed = ref(false)
watch(() => props.message, () => { actions.reset(); dismissed.value = false })
function dismiss() {
    actions.run('dismiss', 300, () => {
        dismissed.value = true
        emit('dismiss')
    })
}

onMounted(() => {
    watchEffect(onCleanup => {
        if (!props.message || dismissed.value) return
        const timer = window.setTimeout(dismiss, 5000)
        onCleanup(() => window.clearTimeout(timer))
    })
})
</script>

<template>
    <Teleport v-if="message && !dismissed" to="#mailbox-warnings">
        <div class="mailbox-warning">
            <span class="warning-symbol" aria-hidden="true"><TriangleAlert /></span>
            <p role="alert">{{ message }}</p>
            <button type="button" class="warning-close" aria-label="Закрыть предупреждение" @click="dismiss">
                <X aria-hidden="true" />
            </button>
        </div>
    </Teleport>
</template>

<style scoped>
.mailbox-warning {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: start;
    gap: 10px;
    padding: 14px;
    border: 1px solid #edb5b8;
    border-radius: 15px;
    background: #fff5f5;
    color: #8d232e;
    box-shadow: 0 5px 16px #60212a1c;
    pointer-events: auto;
}
.mailbox-warning p { align-self: center; font-size: 14px; line-height: 1.5; overflow-wrap: anywhere; }
.warning-symbol, .warning-close { display: grid; place-items: center; width: 32px; height: 32px; border-radius: 9px; }
.warning-symbol { background: #fce0e1; color: #b52634; }
.warning-close { border: 0; background: transparent; color: inherit; }
.warning-close:hover, .warning-close:focus-visible { background: #fce0e1; }
.warning-close svg { width: 16px; height: 16px; }
</style>
