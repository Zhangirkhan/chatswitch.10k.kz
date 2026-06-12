<script setup lang="ts">
import { useMinWidth } from '@/composables/useMinWidth';

export type SuperAdminAccentGroup = 'overview' | 'operations' | 'billing' | 'platform';

defineProps<{
    eyebrow?: string;
    title: string;
    subtitle?: string;
    accentGroup?: SuperAdminAccentGroup;
}>();

const isDesktop = useMinWidth('(min-width: 1024px)');
</script>

<template>
    <Teleport to="#sa-topbar-page" :disabled="!isDesktop">
        <div
            class="ui-super-admin-topbar-chrome"
            :class="accentGroup ? `ui-super-admin-topbar-chrome--${accentGroup}` : undefined"
        >
            <p v-if="eyebrow" class="ui-super-admin-topbar-chrome__eyebrow">{{ eyebrow }}</p>
            <h1 class="ui-super-admin-topbar-chrome__title">{{ title }}</h1>
            <p v-if="subtitle" class="ui-super-admin-topbar-chrome__subtitle">{{ subtitle }}</p>
        </div>
    </Teleport>

    <Teleport to="#sa-topbar-actions" :disabled="!isDesktop || !$slots.actions">
        <div v-if="$slots.actions" class="ui-super-admin-topbar-chrome__actions">
            <slot name="actions" />
        </div>
    </Teleport>

    <header
        v-if="!isDesktop"
        class="ui-super-admin-page-header ui-super-admin-page-header--mobile"
        :class="accentGroup ? `ui-super-admin-page-header--${accentGroup}` : undefined"
    >
        <div class="ui-super-admin-page-header__intro">
            <p v-if="eyebrow" class="ui-super-admin-page-header__eyebrow">{{ eyebrow }}</p>
            <h1 class="ui-super-admin-page-header__title">{{ title }}</h1>
            <p v-if="subtitle" class="ui-super-admin-page-header__subtitle">{{ subtitle }}</p>
        </div>
        <div v-if="$slots.actions" class="ui-super-admin-page-header__actions">
            <slot name="actions" />
        </div>
    </header>
</template>
