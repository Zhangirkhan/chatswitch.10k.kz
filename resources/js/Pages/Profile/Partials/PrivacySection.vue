<script setup lang="ts">
import SectionHeader from './SectionHeader.vue';
import SettingRow from './SettingRow.vue';
import SettingToggle from './SettingToggle.vue';
import { useLocalSetting } from '@/composables/useLocalSetting';
import { useI18n } from '@/composables/useI18n';
import { computed } from 'vue';

const { t } = useI18n();

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

const visibilityLabel = computed(() => ({
    everyone: t('profile.privacySection.visibilityEveryone'),
    contacts: t('profile.privacySection.visibilityContacts'),
    nobody: t('profile.privacySection.visibilityNobody'),
}));

function cycleVisibility(current: 'everyone' | 'contacts' | 'nobody'): 'everyone' | 'contacts' | 'nobody' {
    return current === 'everyone' ? 'contacts' : current === 'contacts' ? 'nobody' : 'everyone';
}

const disappearingLabel = computed(() => ({
    off: t('profile.privacySection.disappearingOff'),
    '24h': t('profile.privacySection.disappearing24h'),
    '7d': t('profile.privacySection.disappearing7d'),
    '90d': t('profile.privacySection.disappearing90d'),
}));

function cycleDisappearing(current: 'off' | '24h' | '7d' | '90d'): 'off' | '24h' | '7d' | '90d' {
    const order: Array<'off' | '24h' | '7d' | '90d'> = ['off', '24h', '7d', '90d'];
    return order[(order.indexOf(current) + 1) % order.length];
}
</script>

<template>
    <div class="h-full flex flex-col">
        <SectionHeader :title="t('profile.privacySection.title')" />

        <div class="flex-1 overflow-y-auto wa-scrollbar">
            <!-- Who sees my personal info -->
            <div class="group-title">{{ t('profile.privacySection.whoSeesInfo') }}</div>

            <SettingRow
                :title="t('profile.privacySection.lastSeen')"
                :subtitle="visibilityLabel[lastSeen]"
                @click="lastSeen = cycleVisibility(lastSeen)"
            />
            <SettingRow
                :title="t('profile.privacySection.profilePhoto')"
                :subtitle="visibilityLabel[profilePhoto]"
                @click="profilePhoto = cycleVisibility(profilePhoto)"
            />
            <SettingRow
                :title="t('profile.privacySection.about')"
                :subtitle="visibilityLabel[about]"
                @click="about = cycleVisibility(about)"
            />
            <SettingRow
                :title="t('profile.privacySection.status')"
                :subtitle="visibilityLabel[status]"
                @click="status = cycleVisibility(status)"
            />

            <SettingToggle
                v-model="readReceipts"
                :title="t('profile.privacySection.readReceipts')"
                :description="t('profile.privacySection.readReceiptsDesc')"
            />

            <div class="section-divider" />

            <!-- Disappearing messages -->
            <div class="group-title">{{ t('profile.privacySection.disappearingMessages') }}</div>

            <SettingRow
                :title="t('profile.privacySection.timer')"
                :subtitle="disappearingLabel[disappearing]"
                @click="disappearing = cycleDisappearing(disappearing)"
            />
            <SettingRow
                :title="t('profile.privacySection.groups')"
                :subtitle="groupsPolicy === 'everyone' ? t('profile.privacySection.visibilityEveryone') : t('profile.privacySection.visibilityContacts')"
                @click="groupsPolicy = groupsPolicy === 'everyone' ? 'contacts' : 'everyone'"
            />
            <SettingRow
                :title="t('profile.privacySection.blocked')"
                :subtitle="String(blockedCount)"
            />
            <SettingRow
                :title="t('profile.privacySection.appLock')"
                :subtitle="t('profile.privacySection.appLockDesc')"
                @click="appLock = !appLock"
            >
                <template #trailing>
                    <span class="text-xs" :style="{ color: appLock ? 'var(--wa-accent)' : 'var(--wa-text-muted)' }">
                        {{ appLock ? t('profile.privacySection.on') : t('profile.privacySection.off') }}
                    </span>
                </template>
            </SettingRow>

            <div class="section-divider" />

            <!-- Extra -->
            <div class="group-title">{{ t('profile.privacySection.extra') }}</div>

            <SettingToggle
                v-model="blockUnknown"
                :title="t('profile.privacySection.blockUnknown')"
                :description="t('profile.privacySection.blockUnknownDesc')"
                help-link="#"
            />

            <SettingToggle
                v-model="disablePreview"
                :title="t('profile.privacySection.disableLinkPreview')"
                :description="t('profile.privacySection.disableLinkPreviewDesc')"
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
