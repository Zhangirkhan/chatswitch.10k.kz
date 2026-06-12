<script setup lang="ts">
import {
    type SuperAdminAccentGroup,
    useRegisterSuperAdminPageChrome,
} from '@/composables/useSuperAdminPageChrome';

export type { SuperAdminAccentGroup };

const props = defineProps<{
    eyebrow?: string;
    title: string;
    subtitle?: string;
    accentGroup?: SuperAdminAccentGroup;
}>();

useRegisterSuperAdminPageChrome(() => ({
    eyebrow: props.eyebrow,
    title: props.title,
    subtitle: props.subtitle,
    accentGroup: props.accentGroup,
}));
</script>

<template>
    <Teleport to="#sa-topbar-actions">
        <div v-if="$slots.actions" class="ui-super-admin-topbar-chrome__actions">
            <slot name="actions" />
        </div>
    </Teleport>

    <header
        class="ui-super-admin-page-header ui-super-admin-page-header--mobile lg:hidden"
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
