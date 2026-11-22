<script lang="ts">
import {
    Archive, ArrowLeft, ArrowRight, ArrowUpRight, AtSign, CalendarDays, Check, CircleHelp, Download,
    Inbox, Infinity as InfinityIcon, KeyRound, LayoutDashboard, LockKeyhole, LogOut, Mail, Mails,
    Menu, Paperclip, Plus, Search, Settings2, ShieldCheck, Timer, TriangleAlert, UserRound,
} from '@lucide/vue'

const icons = {
    archive: Archive,
    'arrow-left': ArrowLeft,
    'arrow-right': ArrowRight,
    'arrow-up-right': ArrowUpRight,
    'at-sign': AtSign,
    'calendar-days': CalendarDays,
    check: Check,
    'circle-help': CircleHelp,
    download: Download,
    inbox: Inbox,
    infinity: InfinityIcon,
    'key-round': KeyRound,
    'layout-dashboard': LayoutDashboard,
    'lock-keyhole': LockKeyhole,
    'log-out': LogOut,
    mail: Mail,
    mails: Mails,
    menu: Menu,
    paperclip: Paperclip,
    plus: Plus,
    search: Search,
    'settings-2': Settings2,
    'shield-check': ShieldCheck,
    timer: Timer,
    'triangle-alert': TriangleAlert,
    'user-round': UserRound,
}
</script>

<script setup lang="ts">
defineProps<{ name: keyof typeof icons }>()
</script>

<template>
    <component :is="icons[name]" class="icon" aria-hidden="true" focusable="false" />
</template>
