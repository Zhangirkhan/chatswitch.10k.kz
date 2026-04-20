<script setup lang="ts">
import SectionHeader from './SectionHeader.vue';
import SettingRow from './SettingRow.vue';
import SettingToggle from './SettingToggle.vue';
import { useLocalSetting } from '@/composables/useLocalSetting';

const messagesOn = useLocalSetting('notifications.messages', false);
const groupsOn = useLocalSetting('notifications.groups', false);
const statusOn = useLocalSetting('notifications.status', false);

const showPreview = useLocalSetting('notifications.showPreview', true);
const outgoingSound = useLocalSetting('notifications.outgoingSound', false);
const backgroundSync = useLocalSetting('notifications.backgroundSync', false);
</script>

<template>
    <div class="h-full flex flex-col">
        <SectionHeader title="Уведомления" />

        <div class="flex-1 overflow-y-auto wa-scrollbar py-2">
            <SettingRow
                title="Сообщения"
                :subtitle="messagesOn ? 'Вкл.' : 'Выкл.'"
                @click="messagesOn = !messagesOn"
            >
                <template #icon>
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
                    </svg>
                </template>
            </SettingRow>

            <SettingRow
                title="Группы"
                :subtitle="groupsOn ? 'Вкл.' : 'Выкл.'"
                @click="groupsOn = !groupsOn"
            >
                <template #icon>
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </template>
            </SettingRow>

            <SettingRow
                title="Статус"
                :subtitle="statusOn ? 'Вкл.' : 'Выкл.'"
                @click="statusOn = !statusOn"
            >
                <template #icon>
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="9" stroke-dasharray="3 2" />
                        <circle cx="12" cy="12" r="4" fill="currentColor" stroke="none" />
                    </svg>
                </template>
            </SettingRow>

            <div class="h-3"></div>

            <SettingToggle
                v-model="showPreview"
                title="Предпросмотр сообщений"
                description="Просмотр текста сообщения в окне уведомления."
            />
            <SettingToggle
                v-model="outgoingSound"
                title="Воспроизводить звук для исходящих сообщений"
            />
            <SettingToggle
                v-model="backgroundSync"
                title="Фоновая синхронизация"
                description="Повысьте скорость работы с помощью фоновой синхронизации."
            />

            <div class="px-6 pt-4 pb-6 text-xs text-[var(--wa-text-secondary)] leading-relaxed">
                Чтобы получать уведомления, убедитесь, что они включены в браузере и в настройках устройства.
            </div>
        </div>
    </div>
</template>
