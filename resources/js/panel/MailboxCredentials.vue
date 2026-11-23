<script setup lang="ts">
import PanelIcon from './PanelIcon.vue'
import type { MailboxesPage } from './types'

defineProps<{ credentials: NonNullable<MailboxesPage['credentials']> }>()
</script>

<template>
    <section class="notice credentials" aria-labelledby="issued-title">
        <span class="tile-icon violet"><PanelIcon name="key-round" /></span>
        <div>
            <h2 id="issued-title">Данные для входа</h2>
            <p>Сохраните пароль и передайте владельцу. Пароль показывается один раз.</p>
            <dl class="detail-list">
                <div><dt>Логин</dt><dd><code>{{ credentials.email }}</code></dd></div>
                <div><dt>Пароль</dt><dd><code>{{ credentials.password }}</code></dd></div>
            </dl>
        </div>
    </section>
</template>

<style scoped>
.credentials { margin: .85rem 0 0; flex-basis: 100%; }
</style>
