<script setup lang="ts">
import { onBeforeUnmount, onMounted, provide, ref } from 'vue'
import { connectRealtime, realtimeKey } from './bootstrap'
import { listenForMailboxSessionChange } from './mailboxSocket'
import { trackSitePresence } from './sitePresence'
import { useEmailStore } from './stores/emailStore'

const mailbox = useEmailStore()
const configurationFailed = ref(false)
const echo = connectRealtime(() => { configurationFailed.value = true })
provide(realtimeKey, { echo, configurationFailed })
let stopSessionListener: (() => void) | undefined
let stopPresence: (() => void) | undefined
onMounted(() => {
    const channel = document.querySelector<HTMLMetaElement>('meta[name="site-visitor-channel"]')?.content
    if (echo && channel) stopPresence = trackSitePresence(echo, channel)
    stopSessionListener = listenForMailboxSessionChange(() => {
        mailbox.resetSession()
        window.dispatchEvent(new Event('mailbox-session-changed'))
        window.dispatchEvent(new Event('panel-check-session'))
    })
})
onBeforeUnmount(() => {
    stopSessionListener?.()
    stopPresence?.()
    echo?.disconnect()
})
</script>

<template>
    <div id="mailbox-warnings"></div>
    <RouterView />
</template>
