<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import PanelHeading from './PanelHeading.vue'
import PanelIcon from './PanelIcon.vue'
import PanelLetters from './PanelLetters.vue'
import { formatCount } from './format'
import { PanelHttpError, panelRequest } from './http'
import type { DashboardPage } from './types'

const props = defineProps<{ page: DashboardPage }>()
const cards = [
    { key: 'received24h', label: 'Получено писем', caption: 'За последние 24 часа', icon: 'inbox', tone: 'violet' },
    { key: 'archived24h', label: 'В общий архив', caption: 'Из принятых за 24 часа', icon: 'archive', tone: 'lilac' },
    { key: 'guests', label: 'Временные ящики', caption: 'Активны сейчас', icon: 'timer', tone: 'blue' },
    { key: 'permanent', label: 'Постоянные ящики', caption: 'Активны сейчас', icon: 'infinity', tone: 'green' },
    { key: 'activeUsers', label: 'Пользователи онлайн', caption: 'С открытой видимой вкладкой', icon: 'user-round', tone: 'violet' },
] as const
const activeUsers = ref(props.page.metrics.activeUsers)
const metrics = computed(() => ({ ...props.page.metrics, activeUsers: activeUsers.value }))
watch(() => props.page.metrics, metrics => { activeUsers.value = metrics.activeUsers })
let pollTimer: ReturnType<typeof setInterval>
let request: AbortController | undefined

async function refreshActiveUsers() {
    if (document.hidden || request) return
    const controller = new AbortController()
    request = controller
    try {
        const data = await panelRequest<{ activeUsers: number | null }>('/admin/active-users', { signal: controller.signal })
        if (!controller.signal.aborted) activeUsers.value = data.activeUsers
    } catch (cause) {
        if (controller.signal.aborted) return
        activeUsers.value = null
        if (cause instanceof PanelHttpError && [401, 403].includes(cause.status)) {
            window.dispatchEvent(new Event('panel-check-session'))
        }
    } finally {
        request = undefined
    }
}

onMounted(() => {
    pollTimer = setInterval(refreshActiveUsers, 10_000)
    document.addEventListener('visibilitychange', refreshActiveUsers)
})
onBeforeUnmount(() => {
    clearInterval(pollTimer)
    document.removeEventListener('visibilitychange', refreshActiveUsers)
    request?.abort()
})
const dayMonth = new Intl.DateTimeFormat('ru-RU', { timeZone: 'UTC', day: 'numeric', month: 'long' })
const shortDay = new Intl.DateTimeFormat('ru-RU', { timeZone: 'UTC', day: '2-digit', month: '2-digit' })
const days = computed(() => props.page.days.map(day => {
    const date = new Date(day.date)
    return { ...day, label: dayMonth.format(date), shortLabel: shortDay.format(date) }
}))
const lastDay = computed(() => days.value.at(-1))
const mailTotal = computed(() => days.value.reduce((total, day) => total + day.mailbox, 0))
const archiveTotal = computed(() => days.value.reduce((total, day) => total + day.archive, 0))
const weekTotal = computed(() => mailTotal.value + archiveTotal.value)
const step = computed(() => {
    const peak = days.value.reduce((max, day) => Math.max(max, day.mailbox, day.archive), 0)
    return Math.max(1, Math.ceil(peak / 3))
})

function percent(count: number, total: number) {
    return (total ? Math.round(10000 * count / total) / 100 : 0) + '%'
}

function metricValue(count: number | null) {
    return count == null ? '—' : formatCount(count)
}
</script>

<template>
    <PanelHeading title="Обзор почты" :user="page.user" />
    <div class="toolbar">
        <span v-if="lastDay" class="date-chip"><PanelIcon name="calendar-days" />{{ lastDay.label }} {{ lastDay.date.slice(0, 4) }} · UTC</span>
        <RouterLink class="button primary" to="/admin/mailboxes#create-mailbox"><PanelIcon name="plus" />Создать ящик</RouterLink>
    </div>
    <div class="metrics" aria-label="Показатели почты">
        <div v-for="card in cards" :key="card.key" class="card metric">
            <div class="metric-title">
                <span>{{ card.label }}</span>
                <span class="tile-icon" :class="card.tone"><PanelIcon :name="card.icon" /></span>
            </div>
            <strong class="metric-value">{{ metricValue(metrics[card.key]) }}</strong>
            <small>{{ metrics[card.key] == null ? 'Данные недоступны' : card.caption }}</small>
        </div>
    </div>
    <div class="chart-grid">
        <section class="card chart-card" aria-labelledby="daily-title">
            <div class="card-heading"><h2 id="daily-title">Поток писем</h2><span class="period">7 дней · UTC</span></div>
            <div class="chart-legend">
                <span><i class="dot mailbox"></i>В ящики</span><span><i class="dot archived"></i>В общий архив</span>
            </div>
            <div class="daily-chart">
                <div class="chart-axis" aria-hidden="true">
                    <span v-for="tick in [3, 2, 1, 0]" :key="tick">{{ formatCount(tick * step) }}</span>
                </div>
                <div class="chart-gridlines" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
                <div class="chart-days">
                    <div
                        v-for="day in days" :key="day.date" class="chart-day" tabindex="0" role="img"
                        :aria-label="`${day.label}. В ящики ${day.mailbox}, в общий архив ${day.archive}.`"
                    >
                        <div class="bars" aria-hidden="true">
                            <i class="bar mailbox" :style="{ '--height': percent(day.mailbox, 3 * step) }"></i>
                            <i class="bar archived" :style="{ '--height': percent(day.archive, 3 * step) }"></i>
                        </div>
                        <span class="day-label" aria-hidden="true">{{ day.shortLabel }}</span>
                        <span class="chart-tip" aria-hidden="true"><b>{{ day.label }}</b>В ящики · {{ day.mailbox }}<br>В архив · {{ day.archive }}</span>
                    </div>
                </div>
            </div>
        </section>
        <section class="card split-card" aria-labelledby="distribution-title">
            <div class="card-heading"><h2 id="distribution-title">Распределение</h2><span class="period">7 дней</span></div>
            <div class="distribution-body">
                <div class="donut" :class="{ 'no-data': !weekTotal }" :style="{ '--percent': percent(mailTotal, weekTotal) }">
                    <div class="donut-center"><strong>{{ formatCount(weekTotal) }}</strong><span>всего писем</span></div>
                </div>
                <dl class="distribution-legend">
                    <div><dt><i class="dot mailbox"></i>В ящики</dt><dd>{{ formatCount(mailTotal) }}</dd></div>
                    <div><dt><i class="dot archived"></i>В общий архив</dt><dd>{{ formatCount(archiveTotal) }}</dd></div>
                </dl>
            </div>
        </section>
    </div>
    <section class="card mail-card" aria-labelledby="recent-title">
        <div class="card-heading">
            <h2 id="recent-title">Последние письма</h2>
            <RouterLink class="text-action" to="/admin/archive">Весь архив <PanelIcon name="arrow-right" /></RouterLink>
        </div>
        <PanelLetters
            :letters="page.recentMessages" :is-admin="page.user.is_admin" :list-query="{ from: 'overview' }"
            empty-hint="Новые письма появятся здесь после доставки."
        />
    </section>
</template>
