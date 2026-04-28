<script setup lang="ts">
import SectionHeader from './SectionHeader.vue';
import SettingToggle from './SettingToggle.vue';
import { useLocalSetting } from '@/composables/useLocalSetting';

const notificationsEnabled = useLocalSetting('notifications.enabled', false);
</script>

<template>
    <div class="h-full flex flex-col">
        <SectionHeader title="Уведомления" />

        <div class="flex-1 overflow-y-auto wa-scrollbar py-2">
            <SettingToggle
                v-model="notificationsEnabled"
                title="Уведомления"
                description="Получать уведомления о новых сообщениях"
            />

            <div class="px-6 pt-4 pb-6 text-xs text-[var(--wa-text-secondary)] leading-relaxed">
                Чтобы получать уведомления, убедитесь, что они разрешены в браузере и в настройках устройства.
            </div>
        </div>
    </div>
</template>
