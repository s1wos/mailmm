<script setup lang="ts">
import { Archive, ArrowRight, Clock3, KeyRound } from '@lucide/vue'
</script>

<template>
    <article class="info-page">
        <header class="page-heading"><div><h1>О сервисе</h1><p>Как устроены доступ к ящику и хранение писем в mailmm.</p></div></header>
        <div class="about-grid">
            <section class="info-card">
                <span class="info-icon"><Clock3 aria-hidden="true" /></span><h2>Временный адрес</h2>
                <p>Адрес выдаётся при первом открытии почты и действует 24 часа в том же браузере. Смена адреса сразу закрывает доступ к прежним письмам.</p>
                <RouterLink class="text-btn" to="/">Открыть почту <ArrowRight aria-hidden="true" /></RouterLink>
            </section>
            <section class="info-card">
                <span class="info-icon"><KeyRound aria-hidden="true" /></span><h2>Постоянный ящик</h2>
                <p>Администратор выдаёт бессрочный адрес. Владелец может читать письма с разных устройств.</p>
            </section>
            <section class="info-card info-wide">
                <span class="info-icon"><Archive aria-hidden="true" /></span><h2>Хранение писем</h2>
                <p>Письма и вложения хранятся на сервере 365 дней с момента получения. Этот срок действует для временных и бессрочных ящиков.</p>
            </section>
        </div>
    </article>
</template>
