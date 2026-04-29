<script setup lang="ts">
import SectionHeader from './SectionHeader.vue';
import SettingToggle from './SettingToggle.vue';
import { useLocalSetting } from '@/composables/useLocalSetting';
import { computed, ref, watch } from 'vue';

const notificationsEnabled = useLocalSetting('notifications.enabled', false);

/** Считываем Notification.permission заново (он не реактивный в Vue). */
const permissionTick = ref(0);

const permissionLabel = computed(() => {
    permissionTick.value;
    if (typeof window === 'undefined' || !('Notification' in window)) {
        return '';
    }
    if (Notification.permission === 'granted') return 'Разрешено в браузере';
    if (Notification.permission === 'denied') return 'Заблокировано в браузере — снимите запрет в настройках сайта';
    return 'Браузер ещё не спрашивал разрешение';
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
        <SectionHeader title="Уведомления" />

        <div class="flex-1 overflow-y-auto wa-scrollbar py-2">
            <SettingToggle
                v-model="notificationsEnabled"
                title="Уведомления в браузере"
                description="Новые сообщения, назначения, входящие звонки (пока вкладка не на переднем плане)"
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
                    Запросить разрешение у браузера
                </button>
            </div>

            <div class="px-6 pt-4 pb-6 text-xs text-[var(--wa-text-secondary)] leading-relaxed space-y-2">
                <p>
                    Диалог разрешения Chrome и Safari показывают только после вашего действия на сайте (переключатель выше или кнопка «Запросить…»), а не при первом открытии адреса.
                </p>
                <p>
                    Баннеры не показываются, пока вы печатаете в этой вкладке. Переключитесь на другую вкладку, сверните окно или другое приложение — тогда придёт уведомление.
                </p>
                <p>
                    Включите уведомления для этого сайта в настройках браузера и ОС, если раньше нажимали «Блокировать».
                </p>
            </div>
        </div>
    </div>
</template>
