<script setup lang="ts">
import SectionHeader from './SectionHeader.vue';
import SettingRow from './SettingRow.vue';
import SettingToggle from './SettingToggle.vue';
import { useLocalSetting } from '@/composables/useLocalSetting';

const lastSeen = useLocalSetting<'everyone' | 'contacts' | 'nobody'>('privacy.lastSeen', 'everyone');
const profilePhoto = useLocalSetting<'everyone' | 'contacts' | 'nobody'>('privacy.profilePhoto', 'everyone');
const about = useLocalSetting<'everyone' | 'contacts' | 'nobody'>('privacy.about', 'everyone');
const status = useLocalSetting<'everyone' | 'contacts' | 'nobody'>('privacy.status', 'contacts');

const readReceipts = useLocalSetting('privacy.readReceipts', true);
const disappearing = useLocalSetting<'off' | '24h' | '7d' | '90d'>('privacy.disappearing', 'off');
const groupsPolicy = useLocalSetting<'everyone' | 'contacts'>('privacy.groups', 'everyone');
const blockedCount = useLocalSetting('privacy.blockedCount', 1);
const appLock = useLocalSetting('privacy.appLock', false);

const blockUnknown = useLocalSetting('privacy.blockUnknown', false);
const disablePreview = useLocalSetting('privacy.disablePreview', false);

const visibilityLabel: Record<'everyone' | 'contacts' | 'nobody', string> = {
    everyone: 'Все',
    contacts: 'Мои контакты',
    nobody: 'Никто',
};

function cycleVisibility(current: 'everyone' | 'contacts' | 'nobody'): 'everyone' | 'contacts' | 'nobody' {
    return current === 'everyone' ? 'contacts' : current === 'contacts' ? 'nobody' : 'everyone';
}

const disappearingLabel: Record<'off' | '24h' | '7d' | '90d', string> = {
    off: 'Выкл.',
    '24h': '24 часа',
    '7d': '7 дней',
    '90d': '90 дней',
};

function cycleDisappearing(current: 'off' | '24h' | '7d' | '90d'): 'off' | '24h' | '7d' | '90d' {
    const order: Array<'off' | '24h' | '7d' | '90d'> = ['off', '24h', '7d', '90d'];
    return order[(order.indexOf(current) + 1) % order.length];
}
</script>

<template>
    <div class="h-full flex flex-col">
        <SectionHeader title="Конфиденциальность" />

        <div class="flex-1 overflow-y-auto wa-scrollbar">
            <!-- Who sees my personal info -->
            <div class="group-title">Кто видит мою личную информацию</div>

            <SettingRow
                title="Время последнего посещения и статус &quot;в сети&quot;"
                :subtitle="visibilityLabel[lastSeen]"
                @click="lastSeen = cycleVisibility(lastSeen)"
            />
            <SettingRow
                title="Фото профиля"
                :subtitle="visibilityLabel[profilePhoto]"
                @click="profilePhoto = cycleVisibility(profilePhoto)"
            />
            <SettingRow
                title="Информация"
                :subtitle="visibilityLabel[about]"
                @click="about = cycleVisibility(about)"
            />
            <SettingRow
                title="Статус"
                :subtitle="visibilityLabel[status]"
                @click="status = cycleVisibility(status)"
            />

            <SettingToggle
                v-model="readReceipts"
                title="Отчёты о прочтении"
                description="Если вы отключите отчёты о прочтении, то не сможете отправлять и получать эти отчёты. Данные уведомления нельзя отключить для групповых чатов."
            />

            <div class="section-divider" />

            <!-- Disappearing messages -->
            <div class="group-title">Исчезающие сообщения</div>

            <SettingRow
                title="Таймер"
                :subtitle="disappearingLabel[disappearing]"
                @click="disappearing = cycleDisappearing(disappearing)"
            />
            <SettingRow
                title="Группы"
                :subtitle="groupsPolicy === 'everyone' ? 'Все' : 'Мои контакты'"
                @click="groupsPolicy = groupsPolicy === 'everyone' ? 'contacts' : 'everyone'"
            />
            <SettingRow
                title="Заблокированные"
                :subtitle="String(blockedCount)"
            />
            <SettingRow
                title="Блокировка приложения"
                subtitle="Для разблокировки Accel требуется ввести пароль"
                @click="appLock = !appLock"
            >
                <template #trailing>
                    <span class="text-xs" :style="{ color: appLock ? 'var(--wa-accent)' : 'var(--wa-text-muted)' }">
                        {{ appLock ? 'Вкл.' : 'Выкл.' }}
                    </span>
                </template>
            </SettingRow>

            <div class="section-divider" />

            <!-- Extra -->
            <div class="group-title">Дополнительно</div>

            <SettingToggle
                v-model="blockUnknown"
                title="Блокировать сообщения от неизвестных аккаунтов"
                description="Для защиты вашего аккаунта и улучшения работы устройства Accel будет блокировать сообщения от неизвестных аккаунтов, если их количество превысит определённый порог."
                help-link="#"
            />

            <SettingToggle
                v-model="disablePreview"
                title="Отключить предпросмотр ссылок"
                description="Чтобы ваш IP-адрес не смогли вычислить сторонние сайты, мы отключили функцию предпросмотра ссылок, которыми вы делитесь в чатах."
                help-link="#"
            />
        </div>
    </div>
</template>

<style scoped>
.group-title {
    padding: 1rem 1.5rem 0.5rem;
    font-size: 0.875rem;
    color: var(--wa-text-secondary);
}
.section-divider {
    height: 10px;
    background-color: var(--wa-bg);
}
</style>
