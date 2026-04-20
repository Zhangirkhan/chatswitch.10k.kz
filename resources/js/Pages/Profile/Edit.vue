<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps<{
    mustVerifyEmail?: boolean;
    status?: string;
}>();

const page = usePage<any>();
const user = computed(() => page.props.auth.user);

const sections = [
    { id: 'general', title: 'Общие', desc: 'Запуск и закрытие', icon: 'monitor' },
    { id: 'profile', title: 'Профиль', desc: 'Имя, фото профиля, имя пользователя', icon: 'user' },
    { id: 'account', title: 'Аккаунт', desc: 'Уведомления о безопасности, информация аккаунта', icon: 'key' },
    { id: 'privacy', title: 'Конфиденциальность', desc: 'Заблокированные контакты, исчезающие сообщения', icon: 'lock' },
    { id: 'chats', title: 'Чаты', desc: 'Тема, обои, настройки чата', icon: 'chat' },
    { id: 'media', title: 'Видео и звук', desc: 'Камера, микрофон и динамики', icon: 'camera' },
    { id: 'notifications', title: 'Уведомления', desc: 'Сообщения, группы, звуки', icon: 'bell' },
    { id: 'shortcuts', title: 'Сочетания клавиш', desc: 'Быстрые действия', icon: 'keyboard' },
    { id: 'help', title: 'Помощь и отзывы', desc: 'Справочный центр, связь с нами, политика конфиденциальности', icon: 'help' },
];

const activeId = ref<string | null>('profile');
const searchQuery = ref('');

const filteredSections = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return sections;
    return sections.filter(
        (s) => s.title.toLowerCase().includes(q) || s.desc.toLowerCase().includes(q)
    );
});

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

function logout() {
    router.post(route('logout'));
}

function initial(name?: string): string {
    return (name || '?').charAt(0).toUpperCase();
}
</script>

<template>
    <Head title="Настройки профиля" />
    <AuthenticatedLayout>
        <div class="flex h-full w-full bg-[var(--wa-bg)]">
            <!-- Settings sidebar -->
            <aside class="w-[400px] h-full flex flex-col bg-[var(--wa-panel)] shrink-0">
                <!-- Header with user name -->
                <div class="h-[60px] px-6 flex items-center shrink-0">
                    <h1 class="text-[var(--wa-text)] text-xl font-normal truncate">
                        {{ user?.name }}
                    </h1>
                </div>

                <!-- Search -->
                <div class="px-3 pb-2 shrink-0">
                    <div class="relative bg-[var(--wa-panel-header)] rounded-lg">
                        <svg
                            class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--wa-icon)]"
                            fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Поиск"
                            class="w-full pl-12 pr-3 py-[7px] bg-transparent rounded-lg text-sm text-[var(--wa-text)] border-0 focus:ring-0 focus:outline-none"
                        />
                    </div>
                </div>

                <!-- Items list -->
                <div class="flex-1 overflow-y-auto wa-scrollbar">
                    <button
                        v-for="item in filteredSections"
                        :key="item.id"
                        @click="activeId = item.id"
                        class="settings-item w-full flex items-center gap-4 px-6 py-[14px] text-left transition"
                        :class="{ 'is-active': activeId === item.id }"
                    >
                        <div class="shrink-0 w-6 flex items-center justify-center text-[var(--wa-icon)]">
                            <svg v-if="item.icon === 'monitor'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" /></svg>
                            <svg v-else-if="item.icon === 'user'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                            <svg v-else-if="item.icon === 'key'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" /></svg>
                            <svg v-else-if="item.icon === 'lock'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                            <svg v-else-if="item.icon === 'chat'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
                            <svg v-else-if="item.icon === 'camera'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" /></svg>
                            <svg v-else-if="item.icon === 'bell'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" /></svg>
                            <svg v-else-if="item.icon === 'keyboard'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 10.5h.008v.008H6V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 3h.008v.008H6v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM9 10.5h.008v.008H9V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM9 13.5h.008v.008H9v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm1.125-3h.008v.008H10.5V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 3h.008v.008h-.008v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm1.125-3h.008v.008H12V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM12 13.5h.008v.008H12v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm1.125-3h.008v.008h-.008V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 3h.008v.008h-.008v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm1.125-3h.008v.008H15V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 3h.008v.008H15v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm1.125-3h.008v.008h-.008V10.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 3h.008v.008h-.008v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM6 16.5h12M3.75 7.5h16.5c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125H3.75c-.621 0-1.125-.504-1.125-1.125v-9.75C2.625 8.004 3.129 7.5 3.75 7.5z" /></svg>
                            <svg v-else-if="item.icon === 'help'" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" /></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-[15px] text-[var(--wa-text)] truncate">{{ item.title }}</div>
                            <div class="text-xs text-[var(--wa-text-secondary)] truncate">{{ item.desc }}</div>
                        </div>
                    </button>
                </div>

                <!-- Logout at bottom -->
                <button
                    @click="logout"
                    class="settings-item w-full flex items-center gap-4 px-6 py-[14px] text-left shrink-0 logout-item"
                >
                    <div class="shrink-0 w-6 flex items-center justify-center text-[#f15c6d]">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                        </svg>
                    </div>
                    <div class="text-[15px] text-[#f15c6d]">Выход</div>
                </button>
            </aside>

            <!-- Main content area -->
            <div class="flex-1 flex flex-col min-w-0 border-l border-[var(--wa-border)]">
                <!-- Profile section -->
                <div v-if="activeId === 'profile'" class="flex-1 overflow-y-auto wa-scrollbar">
                    <div class="h-[60px] px-6 flex items-center bg-[var(--wa-panel-header)] shrink-0">
                        <h2 class="text-[var(--wa-text)] text-base font-normal">Профиль</h2>
                    </div>

                    <div class="max-w-xl mx-auto px-6 py-8 space-y-10">
                        <!-- Avatar -->
                        <div class="flex flex-col items-center">
                            <div class="w-[200px] h-[200px] rounded-full bg-[#6b7c85] flex items-center justify-center text-white text-7xl font-medium shadow-lg">
                                {{ initial(user?.name) }}
                            </div>
                        </div>

                        <!-- Status message -->
                        <div v-if="status === 'profile-updated'" class="text-sm text-[var(--wa-accent)] text-center">
                            Профиль обновлён
                        </div>

                        <!-- Profile info form -->
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

                        <!-- Password -->
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

                <!-- Empty state / placeholder sections -->
                <div
                    v-else
                    class="flex-1 flex items-center justify-center chat-bg"
                >
                    <div class="text-center">
                        <div class="flex items-center justify-center gap-8">
                            <div class="flex flex-col items-center gap-2">
                                <div class="w-20 h-20 rounded-2xl bg-[var(--wa-panel-header)] flex items-center justify-center">
                                    <svg class="w-10 h-10 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                </div>
                                <div class="text-sm text-[var(--wa-text-secondary)]">Отправить документ</div>
                            </div>
                            <div class="flex flex-col items-center gap-2">
                                <div class="w-20 h-20 rounded-2xl bg-[var(--wa-panel-header)] flex items-center justify-center">
                                    <svg class="w-10 h-10 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                                    </svg>
                                </div>
                                <div class="text-sm text-[var(--wa-text-secondary)]">Добавить контакт</div>
                            </div>
                        </div>
                        <p class="mt-8 text-xs text-[var(--wa-text-secondary)]">
                            Раздел «{{ sections.find(s => s.id === activeId)?.title }}» находится в разработке
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.settings-item {
    transition: background-color 0.15s ease;
}
.settings-item:hover {
    background-color: var(--wa-panel-hover);
}
.settings-item.is-active {
    background-color: var(--wa-selected);
}
.logout-item {
    border-top: 1px solid var(--wa-border);
}
.logout-item:hover {
    background-color: var(--wa-panel-hover);
}
</style>
