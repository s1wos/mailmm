<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { splitMailLinks } from './mailLinks'

const props = defineProps<{ html: string }>()
const frame = ref<HTMLIFrameElement | null>(null)
let observer: ResizeObserver | undefined

const source = computed(() => {
    const proxy = typeof window === 'undefined' ? '' : window.location.origin + '/mail-images/'
    const policy = `default-src 'none'; style-src 'unsafe-inline'; img-src data: ${proxy}; base-uri 'none'; form-action 'none'`
    return `<!doctype html><html><head><meta charset="utf-8">
        <meta http-equiv="Content-Security-Policy" content="${policy}">
        <meta name="referrer" content="no-referrer">
        <meta http-equiv="x-dns-prefetch-control" content="off">
        <style>
            html { overflow-wrap: anywhere; }
            body { margin: 0; display: flow-root; color: #202124; background: white; font: 15px/1.5 Arial, sans-serif; }
            img, table { max-width: 100%; }
            pre { white-space: pre-wrap; }
        </style></head><body>${props.html}</body></html>`
})

function linkifyText(doc: Document) {
    const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT)
    const nodes: Text[] = []
    while (walker.nextNode()) {
        const node = walker.currentNode as Text
        if (/https?:\/\//i.test(node.data) && !node.parentElement?.closest('a, style, title')) nodes.push(node)
    }
    for (const node of nodes) {
        const parts = splitMailLinks(node.data)
        if (!parts.some(part => part.href)) continue
        const fragment = doc.createDocumentFragment()
        for (const part of parts) {
            if (!part.href) {
                fragment.append(part.text)
                continue
            }
            const link = doc.createElement('a')
            link.href = part.href
            link.textContent = part.text
            link.target = '_blank'
            link.rel = 'noopener noreferrer'
            fragment.append(link)
        }
        node.replaceWith(fragment)
    }
}

function onLoad() {
    observer?.disconnect()
    const element = frame.value
    const doc = element?.contentDocument
    if (!element || !doc?.body) return
    linkifyText(doc)
    const resize = () => {
        // Measure from a stable viewport. Sender CSS can size blocks in vh or percentages.
        element.style.height = '150px'
        const height = Math.max(doc.body.scrollHeight, doc.body.getBoundingClientRect().height, 1)
        element.style.height = Math.min(12000, Math.ceil(height)) + 'px'
    }
    resize()
    observer = new ResizeObserver(resize)
    observer.observe(doc.body)
}

onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
    <div class="formatted-mail">
        <iframe ref="frame" title="Содержимое письма" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
            referrerpolicy="no-referrer" :srcdoc="source" @load="onLoad" />
    </div>
</template>

<style scoped>
.formatted-mail { min-width: 0; margin-block: 1.2rem; padding-top: 1rem; border-top: 1px solid var(--mm-line); }
iframe { display: block; width: 100%; height: 150px; border: 0; background: white; color-scheme: light; }
</style>
