<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';
import { router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

export interface CompanyModuleItem {
    key: string;
    label: string;
    description: string;
    enabled: boolean;
}

const props = defineProps<{
    companyId: number;
    modules: CompanyModuleItem[];
}>();

const { t } = useI18n();

const form = useForm({
    modules: Object.fromEntries(props.modules.map((m) => [m.key, m.enabled])) as Record<string, boolean>,
});

const enabledCount = computed(() => Object.values(form.modules).filter(Boolean).length);

function toggle(key: string): void {
    form.modules[key] = !form.modules[key];
}

function save(): void {
    form.put(`/companies/${props.companyId}/modules`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <section class="ui-settings-section max-w-3xl">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold">{{ t('superAdmin.companies.modules.title') }}</h2>
                <p class="mt-1 text-sm text-ui-text-secondary">
                    {{ t('superAdmin.companies.modules.summary', { enabled: enabledCount, total: modules.length }) }}
                </p>
            </div>
            <button type="button" class="ui-btn ui-btn--primary ui-btn--sm" :disabled="form.processing" @click="save">
                {{ form.processing ? t('superAdmin.common.saving') : t('superAdmin.companies.modules.save') }}
            </button>
        </div>

        <div class="space-y-2">
            <div
                v-for="mod in modules"
                :key="mod.key"
                class="flex items-center justify-between gap-4 rounded-xl border px-4 py-3 transition-colors"
                :class="form.modules[mod.key] ? 'border-ui-accent-border bg-ui-accent-soft/30' : 'border-ui-border bg-ui-surface'"
            >
                <div class="min-w-0">
                    <div class="font-medium text-ui-text">{{ mod.label }}</div>
                    <div class="mt-0.5 text-sm text-ui-text-secondary">{{ mod.description }}</div>
                </div>
                <button
                    type="button"
                    class="group inline-flex shrink-0 items-center"
                    :aria-pressed="form.modules[mod.key]"
                    :title="form.modules[mod.key] ? t('superAdmin.companies.modules.toggleOff') : t('superAdmin.companies.modules.toggleOn')"
                    @click="toggle(mod.key)"
                >
                    <span
                        class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors"
                        :class="form.modules[mod.key] ? 'bg-ui-accent group-hover:opacity-90' : 'bg-ui-surface-muted group-hover:bg-ui-surface-hover'"
                    >
                        <span
                            class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                            :class="form.modules[mod.key] ? 'translate-x-4' : 'translate-x-1'"
                        ></span>
                    </span>
                </button>
            </div>
        </div>
    </section>
</template>
