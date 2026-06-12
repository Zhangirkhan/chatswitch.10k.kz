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
    <Teleport defer to="#sa-topbar-actions">
        <div v-if="$slots.actions" class="ui-super-admin-topbar-chrome__actions">
            <slot name="actions" />
        </div>
    </Teleport>

    <div
        v-if="eyebrow"
        class="ui-super-admin-page-meta hidden lg:flex"
        :class="accentGroup ? `ui-super-admin-page-meta--${accentGroup}` : undefined"
    >
        <span class="ui-super-admin-page-meta__chip">{{ eyebrow }}</span>
    </div>

    <header
        class="ui-super-admin-page-header ui-super-admin-page-header--mobile lg:hidden"
        :class="accentGroup ? `ui-super-admin-page-header--${accentGroup}` : undefined"
    >
        <div class="ui-super-admin-page-header__intro">
            <h1 class="ui-super-admin-page-header__title">{{ title }}</h1>
            <p v-if="subtitle" class="ui-super-admin-page-header__subtitle">{{ subtitle }}</p>
            <div
                v-if="eyebrow"
                class="ui-super-admin-page-meta ui-super-admin-page-meta--inline"
                :class="accentGroup ? `ui-super-admin-page-meta--${accentGroup}` : undefined"
            >
                <span class="ui-super-admin-page-meta__chip">{{ eyebrow }}</span>
            </div>
        </div>
        <div v-if="$slots.actions" class="ui-super-admin-page-header__actions">
            <slot name="actions" />
        </div>
    </header>
</template>
