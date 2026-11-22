<script setup lang="ts">
import PanelIcon from './PanelIcon.vue'
import type { LoginPage } from './types'
import { usePanelForm } from './usePanelForm'
import { notifyMailboxSessionChanged } from '../mailboxSocket'

defineProps<{ page: LoginPage }>()
const emit = defineEmits<{ authenticated: [path: string] }>()
const { state, submit, blocked, edited } = usePanelForm()

async function login(event: SubmitEvent) {
    const result = await submit(event)
    if (result) {
        notifyMailboxSessionChanged()
        emit('authenticated', result.redirect ?? '/admin')
    }
}
</script>

<template>
    <div class="login-layout">
        <aside class="login-intro">
            <RouterLink to="/" class="wordmark">mailmm</RouterLink>
            <div class="login-art" aria-hidden="true">
                <div class="orbit orbit-outer"></div><div class="orbit orbit-inner"></div>
                <span class="envelope-art"><i></i></span>
                <span class="post-stilt"><i></i><i></i><i></i></span>
            </div>
        </aside>
        <section class="login-form-wrap">
            <RouterLink class="back-link" to="/"><PanelIcon name="arrow-left" />К почте</RouterLink>
            <div class="login-form-block">
                <span class="large-icon"><PanelIcon name="lock-keyhole" /></span>
                <h1>Вход в панель</h1>
                <p>Введите адрес и пароль своей учётной записи.</p>
                <form class="stack-form" method="post" action="/admin/login" @input="edited" @submit.prevent="login">
                    <div>
                        <label for="email">Электронная почта</label>
                        <input id="email" name="email" type="email" autocomplete="username" autofocus required
                            :aria-invalid="state.fields.email ? true : undefined"
                            :aria-describedby="state.fields.email ? 'login-error' : undefined">
                    </div>
                    <div>
                        <label for="password">Пароль</label>
                        <input id="password" name="password" type="password" autocomplete="current-password" required
                            :aria-invalid="state.fields.password ? true : undefined"
                            :aria-describedby="state.fields.password ? 'login-error' : undefined">
                    </div>
                    <p v-if="state.fields.email || state.fields.password" id="login-error" class="error" role="alert">Проверьте логин и пароль.</p>
                    <p v-if="state.message" class="error" role="alert">{{ state.message }}</p>
                    <button class="button primary" type="submit" :disabled="state.pending" :aria-disabled="blocked('/admin/login') || undefined">Войти <PanelIcon name="arrow-right" /></button>
                </form>
            </div>
        </section>
    </div>
</template>
