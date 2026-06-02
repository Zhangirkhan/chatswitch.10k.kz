<script setup lang="ts">
import SectionHeader from './SectionHeader.vue';
import { useI18n } from '@/composables/useI18n';
import SettingToggle from './SettingToggle.vue';
import { useLocalSetting } from '@/composables/useLocalSetting';
import { computed, ref, watch } from 'vue';

const { t } = useI18n();

const notificationsEnabled = useLocalSetting('notifications.enabled', false);

/** Считываем Notification.permission заново (он не реактивный в Vue). */
const permissionTick = ref(0);

const permissionLabel = computed(() => {
    permissionTick.value;
    if (typeof window === 'undefined' || !('Notification' in window)) {
        return '';
    }
    if (Notification.permission === 'granted') return t('profile.notificationsSection.permissionGranted');
    if (Notification.permission === 'denied') return t('profile.notificationsSection.permissionDenied');
    return t('profile.notificationsSection.permissionDefault');
});

const showRequestPermissionButton = computed(() => {
    permissionTick.value;
    if (typeof window === 'undefined' || !('Notification' in window)) return false;
    return Notification.permission === 'default';
});

async function requestBrowserPermission(): Promise<void> {
    if (typeof window === 'undefined' || !('Notification' in window)) return;
    if (Notification.permission === 'denied') return;
    const result = await Notification.requestPermission();
    permissionTick.value += 1;
    if (result === 'granted' && !notificationsEnabled.value) {
        notificationsEnabled.value = true;
    }
}

watch(
    notificationsEnabled,
    async (enabled) => {
        if (!enabled || typeof window === 'undefined' || !('Notification' in window)) {
            return;
        }
        if (Notification.permission === 'denied') {
            notificationsEnabled.value = false;
            return;
        }
        if (Notification.permission === 'default') {
            const result = await Notification.requestPermission();
            if (result !== 'granted') {
                notificationsEnabled.value = false;
            }
        }
    },
);
</script>

<template>
    <div class="h-full flex flex-col">
        <SectionHeader :title="t('profile.notificationsSection.title')" />

        <div class="flex-1 overflow-y-auto wa-scrollbar py-2">
            <SettingToggle
                v-model="notificationsEnabled"
                :title="t('profile.notificationsSection.browserNotifications')"
                :description="t('profile.notificationsSection.browserNotificationsDesc')"
            />

            <div class="px-6 pt-2 text-xs text-[var(--wa-text-secondary)]">
                {{ permissionLabel }}
            </div>

            <div v-if="showRequestPermissionButton" class="px-6 pt-3">
                <button
                    type="button"
                    class="text-sm font-medium rounded-lg px-3 py-2 bg-[var(--wa-accent)] text-white hover:opacity-90"
                    @click="requestBrowserPermission"
                >
                    {{ t('profile.notificationsSection.requestPermission') }}
                </button>
            </div>

            <div class="px-6 pt-4 pb-6 text-xs text-[var(--wa-text-secondary)] leading-relaxed space-y-2">
                <p>
                    {{ t('profile.notificationsSection.hint1') }}
                </p>
                <p>
                    {{ t('profile.notificationsSection.hint2') }}
                </p>
                <p>
                    {{ t('profile.notificationsSection.hint3') }}
                </p>
            </div>
        </div>
    </div>
</template>
