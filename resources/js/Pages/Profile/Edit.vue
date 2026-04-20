<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SettingsSidebar from '@/Pages/Settings/Partials/SettingsSidebar.vue';
import { useTheme } from '@/composables/useTheme';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const { theme, set: setTheme } = useTheme();

defineProps<{
    mustVerifyEmail?: boolean;
    status?: string;
}>();

const page = usePage<any>();
const user = computed(() => page.props.auth.user);

const sections = [
    { id: 'profile', title: 'Профиль' },
    { id: 'account', title: 'Аккаунт' },
    { id: 'privacy', title: 'Конфиденциальность' },
    { id: 'chats', title: 'Чаты' },
    { id: 'notifications', title: 'Уведомления' },
    { id: 'shortcuts', title: 'Сочетания клавиш' },
    { id: 'help', title: 'Помощь и отзывы' },
] as const;

type SectionId = typeof sections[number]['id'];

const activeId = computed<SectionId>(() => {
    const params = new URLSearchParams(window.location.search);
    const requested = params.get('section') as SectionId | null;
    return sections.some((s) => s.id === requested) ? (requested as SectionId) : 'profile';
});

const activeTitle = computed(() => sections.find((s) => s.id === activeId.value)?.title ?? 'Настройки');

const profileForm = useForm({
    name: user.value.name,
    email: user.value.email,
});

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function saveProfile() {
    profileForm.patch(route('profile.update'), { preserveScroll: true });
}

function savePassword() {
    passwordForm.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
}

function initial(name?: string): string {
    return (name || '?').charAt(0).toUpperCase();
}
</script>

<template>
    <Head title="Настройки" />
    <AuthenticatedLayout>
        <div class="flex h-full w-full bg-[var(--wa-bg)]">
            <SettingsSidebar :active-section="activeId" />

            <!-- Main content area -->
            <div class="flex-1 flex flex-col min-w-0 border-l border-[var(--wa-border)]">
                <!-- Header -->
                <div class="h-[60px] px-6 flex items-center bg-[var(--wa-panel-header)] shrink-0 border-b" :style="{ borderColor: 'var(--wa-border)' }">
                    <h2 class="text-[var(--wa-text)] text-base font-normal">{{ activeTitle }}</h2>
                </div>

                <!-- Profile section -->
                <div v-if="activeId === 'profile'" class="flex-1 overflow-y-auto wa-scrollbar">
                    <div class="max-w-xl mx-auto px-6 py-8 space-y-10">
                        <div class="flex flex-col items-center">
                            <div class="w-[200px] h-[200px] rounded-full bg-[#6b7c85] flex items-center justify-center text-white text-7xl font-medium shadow-lg">
                                {{ initial(user?.name) }}
                            </div>
                        </div>

                        <div v-if="status === 'profile-updated'" class="text-sm text-[var(--wa-accent)] text-center">
                            Профиль обновлён
                        </div>

                        <form @submit.prevent="saveProfile" class="space-y-6">
                            <div>
                                <label class="block text-sm text-[var(--wa-accent)] mb-2">Ваше имя</label>
                                <input
                                    v-model="profileForm.name"
                                    type="text"
                                    required
                                    class="w-full bg-transparent border-0 border-b text-[var(--wa-text)] py-2 px-0 focus:outline-none focus:border-[var(--wa-accent)]"
                                    :style="{ borderColor: 'var(--wa-border-strong)' }"
                                />
                                <p v-if="profileForm.errors.name" class="mt-1 text-xs text-red-400">{{ profileForm.errors.name }}</p>
                                <p class="mt-2 text-xs text-[var(--wa-text-secondary)]">
                                    Это имя будет видно вашим контактам.
                                </p>
                            </div>

                            <div>
                                <label class="block text-sm text-[var(--wa-accent)] mb-2">Email</label>
                                <input
                                    v-model="profileForm.email"
                                    type="email"
                                    required
                                    class="w-full bg-transparent border-0 border-b text-[var(--wa-text)] py-2 px-0 focus:outline-none focus:border-[var(--wa-accent)]"
                                    :style="{ borderColor: 'var(--wa-border-strong)' }"
                                />
                                <p v-if="profileForm.errors.email" class="mt-1 text-xs text-red-400">{{ profileForm.errors.email }}</p>
                            </div>

                            <div class="flex items-center gap-3">
                                <button
                                    type="submit"
                                    :disabled="profileForm.processing"
                                    class="px-5 py-2 rounded-lg text-white text-sm font-medium transition disabled:opacity-50"
                                    :style="{ background: 'var(--wa-accent)' }"
                                >
                                    Сохранить
                                </button>
                                <span v-if="profileForm.recentlySuccessful" class="text-sm text-[var(--wa-accent)]">Сохранено</span>
                            </div>
                        </form>

                        <div class="pt-6 border-t" :style="{ borderColor: 'var(--wa-border)' }">
                            <h3 class="text-[15px] text-[var(--wa-text)] mb-1">Пароль</h3>
                            <p class="text-xs text-[var(--wa-text-secondary)] mb-4">
                                Используйте длинный надёжный пароль, чтобы защитить аккаунт.
                            </p>
                            <form @submit.prevent="savePassword" class="space-y-6">
                                <div>
                                    <label class="block text-sm text-[var(--wa-accent)] mb-2">Текущий пароль</label>
                                    <input
                                        v-model="passwordForm.current_password"
                                        type="password"
                                        autocomplete="current-password"
                                        class="w-full bg-transparent border-0 border-b text-[var(--wa-text)] py-2 px-0 focus:outline-none focus:border-[var(--wa-accent)]"
                                        :style="{ borderColor: 'var(--wa-border-strong)' }"
                                    />
                                    <p v-if="passwordForm.errors.current_password" class="mt-1 text-xs text-red-400">{{ passwordForm.errors.current_password }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm text-[var(--wa-accent)] mb-2">Новый пароль</label>
                                    <input
                                        v-model="passwordForm.password"
                                        type="password"
                                        autocomplete="new-password"
                                        class="w-full bg-transparent border-0 border-b text-[var(--wa-text)] py-2 px-0 focus:outline-none focus:border-[var(--wa-accent)]"
                                        :style="{ borderColor: 'var(--wa-border-strong)' }"
                                    />
                                    <p v-if="passwordForm.errors.password" class="mt-1 text-xs text-red-400">{{ passwordForm.errors.password }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm text-[var(--wa-accent)] mb-2">Повторите новый пароль</label>
                                    <input
                                        v-model="passwordForm.password_confirmation"
                                        type="password"
                                        autocomplete="new-password"
                                        class="w-full bg-transparent border-0 border-b text-[var(--wa-text)] py-2 px-0 focus:outline-none focus:border-[var(--wa-accent)]"
                                        :style="{ borderColor: 'var(--wa-border-strong)' }"
                                    />
                                    <p v-if="passwordForm.errors.password_confirmation" class="mt-1 text-xs text-red-400">{{ passwordForm.errors.password_confirmation }}</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <button
                                        type="submit"
                                        :disabled="passwordForm.processing"
                                        class="px-5 py-2 rounded-lg text-white text-sm font-medium transition disabled:opacity-50"
                                        :style="{ background: 'var(--wa-accent)' }"
                                    >
                                        Обновить пароль
                                    </button>
                                    <span v-if="passwordForm.recentlySuccessful" class="text-sm text-[var(--wa-accent)]">Сохранено</span>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Chats section (theme picker) -->
                <div v-else-if="activeId === 'chats'" class="flex-1 overflow-y-auto wa-scrollbar">
                    <div class="max-w-xl mx-auto px-6 py-8 space-y-8">
                        <div>
                            <h3 class="text-[15px] text-[var(--wa-text)] mb-1">Тема</h3>
                            <p class="text-xs text-[var(--wa-text-secondary)] mb-4">
                                Выберите оформление приложения.
                            </p>
                            <div class="grid grid-cols-2 gap-4">
                                <button
                                    type="button"
                                    @click="setTheme('light')"
                                    class="theme-card"
                                    :class="{ 'theme-card-active': theme === 'light' }"
                                >
                                    <div class="theme-card-preview theme-card-preview-light">
                                        <div class="theme-card-bubble-in"></div>
                                        <div class="theme-card-bubble-out"></div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m12.728 0l-.707-.707M6.343 6.343l-.707-.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                                        </svg>
                                        <span class="text-sm">Светлая</span>
                                    </div>
                                </button>
                                <button
                                    type="button"
                                    @click="setTheme('dark')"
                                    class="theme-card"
                                    :class="{ 'theme-card-active': theme === 'dark' }"
                                >
                                    <div class="theme-card-preview theme-card-preview-dark">
                                        <div class="theme-card-bubble-in"></div>
                                        <div class="theme-card-bubble-out"></div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                                        </svg>
                                        <span class="text-sm">Тёмная</span>
                                    </div>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Placeholder for not-implemented sections -->
                <div v-else class="flex-1 flex items-center justify-center">
                    <div class="text-center max-w-sm px-6">
                        <div class="w-16 h-16 rounded-full bg-[var(--wa-panel-header)] flex items-center justify-center mx-auto mb-4">
                            <svg class="w-8 h-8 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <h3 class="text-[17px] text-[var(--wa-text)] mb-2">{{ activeTitle }}</h3>
                        <p class="text-sm text-[var(--wa-text-secondary)]">
                            Этот раздел находится в разработке.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.theme-card {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    padding: 0.75rem;
    border-radius: 0.75rem;
    border: 2px solid var(--wa-border-strong);
    background: var(--wa-panel);
    color: var(--wa-text);
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.theme-card:hover {
    background-color: var(--wa-panel-hover);
}
.theme-card-active {
    border-color: var(--wa-accent);
}
.theme-card-preview {
    height: 72px;
    border-radius: 0.5rem;
    position: relative;
    overflow: hidden;
    padding: 10px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    justify-content: flex-end;
}
.theme-card-preview-light {
    background: #efeae2;
}
.theme-card-preview-dark {
    background: #0b141a;
}
.theme-card-bubble-in {
    align-self: flex-start;
    width: 55%;
    height: 12px;
    border-radius: 8px;
    background: var(--preview-in);
}
.theme-card-bubble-out {
    align-self: flex-end;
    width: 45%;
    height: 12px;
    border-radius: 8px;
    background: var(--preview-out);
}
.theme-card-preview-light {
    --preview-in: #ffffff;
    --preview-out: #d9fdd3;
}
.theme-card-preview-dark {
    --preview-in: #202c33;
    --preview-out: #005c4b;
}
</style>
