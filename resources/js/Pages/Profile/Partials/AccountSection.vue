<script setup lang="ts">
import SectionHeader from './SectionHeader.vue';
import { useI18n } from '@/composables/useI18n';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const { t } = useI18n();

const showPasswordForm = ref(false);
const showDeleteForm = ref(false);

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const deleteForm = useForm({
    password: '',
});

function savePassword() {
    passwordForm.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => {
            passwordForm.reset();
            showPasswordForm.value = false;
        },
    });
}

function confirmDelete() {
    deleteForm.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => {
            router.visit('/');
        },
    });
}
</script>

<template>
    <div class="h-full flex flex-col">
        <SectionHeader :title="t('profile.accountSection.title')" />

        <div class="flex-1 overflow-y-auto wa-scrollbar py-2">
            <!-- Security notifications (stub info) -->
            <details class="info-details group">
                <summary class="list-none cursor-pointer">
                    <div class="account-item">
                        <div class="account-icon">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                        </div>
                        <span class="text-[15px] text-[var(--wa-text)]">{{ t('profile.accountSection.securityNotifications') }}</span>
                    </div>
                </summary>
                <div class="px-16 pb-4 text-xs text-[var(--wa-text-secondary)] leading-relaxed">
                    {{ t('profile.accountSection.securityNotificationsDesc') }}
                </div>
            </details>

            <!-- Request account info (stub) -->
            <details class="info-details">
                <summary class="list-none cursor-pointer">
                    <div class="account-item">
                        <div class="account-icon">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                        <span class="text-[15px] text-[var(--wa-text)]">{{ t('profile.accountSection.requestAccountInfo') }}</span>
                    </div>
                </summary>
                <div class="px-16 pb-4 text-xs text-[var(--wa-text-secondary)] leading-relaxed">
                    {{ t('profile.accountSection.requestAccountInfoDesc') }}
                </div>
            </details>

            <!-- Change password (functional) -->
            <button type="button" class="account-item w-full" @click="showPasswordForm = !showPasswordForm">
                <div class="account-icon">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a3 3 0 11-6 0 3 3 0 016 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 14v-3" />
                    </svg>
                </div>
                <span class="text-[15px] text-[var(--wa-text)] flex-1 text-left">{{ t('profile.accountSection.changePassword') }}</span>
                <svg class="w-4 h-4 text-[var(--wa-text-muted)] transition" :class="{ 'rotate-90': showPasswordForm }" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                </svg>
            </button>
            <form v-if="showPasswordForm" @submit.prevent="savePassword" class="px-6 py-4 space-y-4 bg-[var(--wa-panel-hover)]">
                <div>
                    <label class="block text-xs text-[var(--wa-accent)] mb-1">{{ t('profile.accountSection.currentPassword') }}</label>
                    <input
                        v-model="passwordForm.current_password"
                        type="password"
                        autocomplete="current-password"
                        class="w-full bg-transparent border-0 border-b text-sm text-[var(--wa-text)] py-1.5 px-0 focus:outline-none focus:border-[var(--wa-accent)]"
                        :style="{ borderColor: 'var(--wa-control-rim)', boxShadow: 'var(--wa-control-rim-shadow)' }"
                    />
                    <p v-if="passwordForm.errors.current_password" class="mt-1 text-xs text-red-400">{{ passwordForm.errors.current_password }}</p>
                </div>
                <div>
                    <label class="block text-xs text-[var(--wa-accent)] mb-1">{{ t('profile.accountSection.newPassword') }}</label>
                    <input
                        v-model="passwordForm.password"
                        type="password"
                        autocomplete="new-password"
                        class="w-full bg-transparent border-0 border-b text-sm text-[var(--wa-text)] py-1.5 px-0 focus:outline-none focus:border-[var(--wa-accent)]"
                        :style="{ borderColor: 'var(--wa-control-rim)', boxShadow: 'var(--wa-control-rim-shadow)' }"
                    />
                    <p v-if="passwordForm.errors.password" class="mt-1 text-xs text-red-400">{{ passwordForm.errors.password }}</p>
                </div>
                <div>
                    <label class="block text-xs text-[var(--wa-accent)] mb-1">{{ t('profile.accountSection.confirmPassword') }}</label>
                    <input
                        v-model="passwordForm.password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        class="w-full bg-transparent border-0 border-b text-sm text-[var(--wa-text)] py-1.5 px-0 focus:outline-none focus:border-[var(--wa-accent)]"
                        :style="{ borderColor: 'var(--wa-control-rim)', boxShadow: 'var(--wa-control-rim-shadow)' }"
                    />
                </div>
                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        :disabled="passwordForm.processing"
                        class="px-4 py-1.5 rounded-full text-white text-sm transition disabled:opacity-50"
                        :style="{ background: 'var(--wa-accent)' }"
                    >
                        {{ t('profile.accountSection.update') }}
                    </button>
                    <span v-if="passwordForm.recentlySuccessful" class="text-xs" :style="{ color: 'var(--wa-accent)' }">{{ t('profile.accountSection.saved') }}</span>
                </div>
            </form>

            <!-- Delete account (functional) -->
            <button type="button" class="account-item w-full" @click="showDeleteForm = !showDeleteForm">
                <div class="account-icon">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <span class="text-[15px] text-[var(--wa-text)] flex-1 text-left">{{ t('profile.accountSection.deleteAccount') }}</span>
                <svg class="w-4 h-4 text-[var(--wa-text-muted)] transition" :class="{ 'rotate-90': showDeleteForm }" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                </svg>
            </button>
            <div v-if="showDeleteForm" class="px-6 py-4 bg-[var(--wa-panel-hover)] space-y-3">
                <p class="text-xs text-[var(--wa-text-secondary)] leading-relaxed">
                    {{ t('profile.accountSection.deleteAccountDesc') }}
                </p>
                <form @submit.prevent="confirmDelete" class="space-y-3">
                    <input
                        v-model="deleteForm.password"
                        type="password"
                        autocomplete="current-password"
                        :placeholder="t('profile.accountSection.passwordPlaceholder')"
                        class="w-full bg-transparent border-0 border-b text-sm text-[var(--wa-text)] py-1.5 px-0 focus:outline-none focus:border-[#f15c6d]"
                        :style="{ borderColor: 'var(--wa-control-rim)', boxShadow: 'var(--wa-control-rim-shadow)' }"
                    />
                    <p v-if="deleteForm.errors.password" class="text-xs text-red-400">{{ deleteForm.errors.password }}</p>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            @click="showDeleteForm = false; deleteForm.reset()"
                            class="px-4 py-1.5 rounded-full text-sm border"
                            :style="{ borderColor: 'var(--wa-control-rim)', boxShadow: 'var(--wa-control-rim-shadow)', color: 'var(--wa-text)' }"
                        >
                            {{ t('common.cancel') }}
                        </button>
                        <button
                            type="submit"
                            :disabled="deleteForm.processing || !deleteForm.password"
                            class="px-4 py-1.5 rounded-full text-white text-sm transition disabled:opacity-50"
                            style="background: #f15c6d"
                        >
                            {{ t('profile.accountSection.deleteAccountButton') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<style scoped>
.account-item {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    padding: 0.875rem 1.5rem;
    width: 100%;
    transition: background-color 0.15s ease;
}
.account-item:hover {
    background-color: var(--wa-panel-hover);
}
.account-icon {
    width: 2rem;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--wa-icon);
    flex-shrink: 0;
}
.info-details summary::-webkit-details-marker {
    display: none;
}
</style>
