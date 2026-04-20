<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

type NavItem = {
    label: string;
    routeName: string;
    description: string;
    icon: string;
};

const items: NavItem[] = [
    {
        label: 'Подключения WhatsApp',
        routeName: 'settings.connections',
        description: 'Номера и QR-коды',
        icon: 'connection',
    },
    {
        label: 'Отделы',
        routeName: 'settings.departments',
        description: 'Структура компании',
        icon: 'departments',
    },
    {
        label: 'Пользователи',
        routeName: 'settings.users',
        description: 'Операторы и права',
        icon: 'users',
    },
    {
        label: 'Система',
        routeName: 'settings.system',
        description: 'Общие параметры',
        icon: 'system',
    },
];
</script>

<template>
    <div class="w-[360px] h-full flex flex-col bg-[var(--wa-panel)] shrink-0">
        <!-- Header -->
        <div class="h-[60px] px-4 flex items-center shrink-0">
            <h1 class="text-[var(--wa-text)] text-xl font-normal">Настройки</h1>
        </div>

        <!-- Nav list -->
        <div class="flex-1 overflow-y-auto wa-scrollbar">
            <Link
                v-for="item in items"
                :key="item.routeName"
                :href="route(item.routeName)"
                class="settings-nav-item"
                :class="{ 'settings-nav-item-active': route().current(item.routeName + '*') }"
            >
                <div class="settings-nav-icon">
                    <svg v-if="item.icon === 'connection'" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 16l-4-4m0 0l4-4m-4 4h16m-4 4l4-4m0 0l-4-4" />
                    </svg>
                    <svg v-else-if="item.icon === 'departments'" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <svg v-else-if="item.icon === 'users'" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                    <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-[15px] text-[var(--wa-text)] truncate">{{ item.label }}</div>
                    <div class="text-xs text-[var(--wa-text-secondary)] truncate">{{ item.description }}</div>
                </div>
            </Link>
        </div>
    </div>
</template>

<style scoped>
.settings-nav-item {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    padding: 0.75rem 1rem;
    cursor: pointer;
    transition: background-color 0.15s ease;
    border-left: 3px solid transparent;
}
.settings-nav-item:hover {
    background-color: var(--wa-panel-hover);
}
.settings-nav-item-active {
    background-color: var(--wa-selected);
    border-left-color: var(--wa-accent);
}
.settings-nav-icon {
    width: 42px;
    height: 42px;
    border-radius: 9999px;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: var(--wa-panel-header);
    color: var(--wa-accent);
    flex-shrink: 0;
}
</style>
