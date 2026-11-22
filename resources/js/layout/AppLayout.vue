<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowLeft, CircleHelp, Inbox, Info, Menu, X } from '@lucide/vue'
import { useEmailStore } from '../stores/emailStore'
import { useActionGuard, useNavigationGuard } from '../useActionGuard'

const route = useRoute()
const store = useEmailStore()
const navigation = useNavigationGuard(() => store.isAdmin)
const actions = useActionGuard(() => store.isAdmin)
const menuOpen = ref(false)
const menuButton = ref<HTMLButtonElement | null>(null)
const main = ref<HTMLElement | null>(null)

watch(() => route.path, async () => {
    menuOpen.value = false
    await nextTick()
    main.value?.focus({ preventScroll: true })
})

function closeMenu() {
    if (!menuOpen.value) return
    menuOpen.value = false
    menuButton.value?.focus()
}

function toggleMenu() {
    const open = !menuOpen.value
    actions.run('menu:' + open, 300, () => { menuOpen.value = open })
}
</script>

<template>
    <a class="skip" href="#main">К содержимому</a>
    <div class="workspace-shell" @click.capture="navigation.capture">
        <header class="site-header" @keydown.esc="closeMenu">
            <RouterLink to="/" class="brand" aria-label="mailmm — на главную" @click="closeMenu">mailmm</RouterLink>
            <button ref="menuButton" class="menu-toggle icon-button" type="button" aria-controls="site-nav"
                :aria-expanded="menuOpen" :aria-label="menuOpen ? 'Закрыть меню' : 'Открыть меню'" @click="toggleMenu">
                <component :is="menuOpen ? X : Menu" aria-hidden="true" />
            </button>
            <nav id="site-nav" class="site-nav" :class="{ 'menu-open': menuOpen }" aria-label="Основная навигация">
                <RouterLink to="/" @click="closeMenu"><Inbox aria-hidden="true" /> Почта</RouterLink>
                <RouterLink to="/about" @click="closeMenu"><Info aria-hidden="true" /> О сервисе</RouterLink>
                <RouterLink to="/help" @click="closeMenu"><CircleHelp aria-hidden="true" /> Помощь</RouterLink>
                <RouterLink v-if="route.path === '/' && store.panelUrl" :to="store.panelUrl">
                    <ArrowLeft aria-hidden="true" /> Вернуться в панель
                </RouterLink>
            </nav>
        </header>
        <main id="main" ref="main" class="workspace-main" tabindex="-1">
            <RouterView v-slot="{ Component }">
                <Transition name="page" mode="out-in">
                    <component :is="Component" :key="route.path" />
                </Transition>
            </RouterView>
        </main>
    </div>
</template>
