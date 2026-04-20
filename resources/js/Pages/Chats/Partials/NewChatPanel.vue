<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';

type Contact = {
    id: number;
    whatsapp_id: string | null;
    phone_number: string | null;
    name: string | null;
    push_name: string | null;
    profile_picture_url: string | null;
};

type Session = {
    id: number;
    session_name: string;
    display_name: string | null;
    phone_number: string | null;
    status: string;
};

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const page = usePage<any>();
const currentUser = computed(() => page.props.auth.user);

const search = ref('');
const loading = ref(false);
const contacts = ref<Contact[]>([]);
const sessions = ref<Session[]>([]);
const selectedSessionId = ref<number | null>(null);
const starting = ref(false);

type Mode = 'list' | 'dial';
const mode = ref<Mode>('list');
const phoneInput = ref('');

const dialKeys: Array<{ digit: string; letters?: string }> = [
    { digit: '1' },
    { digit: '2', letters: 'ABC' },
    { digit: '3', letters: 'DEF' },
    { digit: '4', letters: 'GHI' },
    { digit: '5', letters: 'JKL' },
    { digit: '6', letters: 'MNO' },
    { digit: '7', letters: 'PQRS' },
    { digit: '8', letters: 'TUV' },
    { digit: '9', letters: 'WXYZ' },
];

function pressKey(value: string) {
    if (phoneInput.value.length >= 20) return;
    if (value === '+' && phoneInput.value.length > 0) return;
    phoneInput.value += value;
}

function backspace() {
    phoneInput.value = phoneInput.value.slice(0, -1);
}

const phoneDigits = computed(() => phoneInput.value.replace(/\D/g, ''));
const canStartPhone = computed(() => phoneDigits.value.length >= 7 && !!selectedSessionId.value);

let searchTimeout: ReturnType<typeof setTimeout>;

watch(search, (val) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => loadContacts(val), 250);
});

async function loadContacts(q = '') {
    loading.value = true;
    try {
        const { data } = await axios.get(route('chats.contacts'), {
            params: { search: q || undefined },
        });
        contacts.value = data.contacts || [];
        sessions.value = data.sessions || [];
        if (selectedSessionId.value === null && sessions.value.length > 0) {
            selectedSessionId.value = sessions.value[0].id;
        }
    } finally {
        loading.value = false;
    }
}

onMounted(() => loadContacts());

function onKeydown(e: KeyboardEvent) {
    if (mode.value !== 'dial') return;
    if (/^[0-9]$/.test(e.key)) {
        pressKey(e.key);
    } else if (e.key === '+') {
        pressKey('+');
    } else if (e.key === 'Backspace') {
        backspace();
    } else if (e.key === 'Enter') {
        if (canStartPhone.value) startChatWithPhone();
    } else if (e.key === 'Escape') {
        backFromDialpad();
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

const groupedContacts = computed(() => {
    const groups = new Map<string, Contact[]>();
    for (const c of contacts.value) {
        const label = (c.name || c.push_name || c.phone_number || '#').trim();
        const firstChar = label.charAt(0).toUpperCase();
        const key = /[A-Z]/.test(firstChar) || /[А-ЯЁ]/.test(firstChar) ? firstChar : '#';
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key)!.push(c);
    }
    return Array.from(groups.entries()).sort(([a], [b]) => {
        if (a === '#') return 1;
        if (b === '#') return -1;
        return a.localeCompare(b, 'ru');
    });
});

function contactLabel(c: Contact): string {
    return c.name || c.push_name || c.phone_number || c.whatsapp_id || 'Без имени';
}

function contactInitial(c: Contact): string {
    return contactLabel(c).charAt(0).toUpperCase();
}

function contactSubtitle(c: Contact): string {
    if (c.name && c.phone_number) return c.phone_number;
    return '';
}

async function startChatWithContact(c: Contact) {
    if (!selectedSessionId.value) return;
    starting.value = true;
    router.post(
        route('chats.start'),
        {
            contact_id: c.id,
            whatsapp_session_id: selectedSessionId.value,
        },
        {
            onFinish: () => {
                starting.value = false;
                emit('close');
            },
        },
    );
}

async function startChatWithPhone() {
    if (!phoneInput.value.trim() || !selectedSessionId.value) return;
    starting.value = true;
    router.post(
        route('chats.start'),
        {
            phone: phoneInput.value.trim(),
            whatsapp_session_id: selectedSessionId.value,
        },
        {
            onFinish: () => {
                starting.value = false;
                emit('close');
            },
        },
    );
}

const hasPhoneInSearch = computed(() => {
    const digits = search.value.replace(/\D/g, '');
    return digits.length >= 7;
});

function startPhoneFromSearch() {
    phoneInput.value = search.value;
    startChatWithPhone();
}

function openDialpad() {
    phoneInput.value = '';
    mode.value = 'dial';
}

function backFromDialpad() {
    mode.value = 'list';
}

function onHeaderBack() {
    if (mode.value === 'dial') {
        backFromDialpad();
    } else {
        emit('close');
    }
}
</script>

<template>
    <div class="w-[400px] h-full flex flex-col bg-[var(--wa-panel)] shrink-0 relative">
        <!-- Header: list mode (green) -->
        <div
            v-if="mode === 'list'"
            class="h-[108px] px-4 pt-10 pb-4 flex items-center gap-5 shrink-0"
            :style="{ background: 'var(--wa-accent)', color: '#fff' }"
        >
            <button @click="onHeaderBack" class="w-6 h-6 text-white" title="Назад" type="button">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
            </button>
            <h1 class="text-white text-lg font-medium flex-1">Новый чат</h1>
            <button
                @click="openDialpad"
                class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-white/15 transition"
                title="Ввести номер телефона"
                type="button"
            >
                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24">
                    <circle cx="5" cy="6" r="1.6"/><circle cx="12" cy="6" r="1.6"/><circle cx="19" cy="6" r="1.6"/>
                    <circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>
                    <circle cx="5" cy="18" r="1.6"/><circle cx="12" cy="18" r="1.6"/><circle cx="19" cy="18" r="1.6"/>
                </svg>
            </button>
        </div>

        <!-- Header: dial mode (plain) -->
        <div
            v-else
            class="h-[60px] px-4 flex items-center gap-5 shrink-0"
            :style="{ color: 'var(--wa-text)' }"
        >
            <button
                @click="onHeaderBack"
                class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)] transition"
                title="Назад" type="button"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
            </button>
            <h1 class="text-[17px] font-normal">Номер телефона</h1>
        </div>

        <!-- Dial mode content -->
        <template v-if="mode === 'dial'">
            <!-- Session picker (compact, only when multiple) -->
            <div
                v-if="sessions.length > 1"
                class="px-4 py-2 border-b shrink-0"
                :style="{ borderColor: 'var(--wa-border)' }"
            >
                <label class="block text-xs mb-1" :style="{ color: 'var(--wa-text-secondary)' }">
                    Отправитель
                </label>
                <select
                    v-model="selectedSessionId"
                    class="w-full rounded-md px-2 py-1 text-sm border focus:outline-none focus:ring-0"
                    :style="{
                        background: 'var(--wa-panel)',
                        color: 'var(--wa-text)',
                        borderColor: 'var(--wa-border-strong)',
                    }"
                >
                    <option v-for="s in sessions" :key="s.id" :value="s.id">
                        {{ s.display_name || s.phone_number || s.session_name }}
                    </option>
                </select>
            </div>

            <div class="flex-1 flex flex-col items-center overflow-y-auto wa-scrollbar px-6 pt-6">
                <!-- Phone display with underline -->
                <div class="w-full max-w-[320px]">
                    <div
                        class="text-center text-[26px] tracking-wide min-h-[40px]"
                        :style="{ color: 'var(--wa-text)' }"
                    >
                        {{ phoneInput || '\u00A0' }}
                    </div>
                    <div
                        class="h-px w-full mt-1"
                        :style="{ background: 'var(--wa-border-strong)' }"
                    ></div>
                </div>

                <p
                    class="mt-5 text-[13px] text-center"
                    :style="{ color: 'var(--wa-text-secondary)' }"
                >
                    Введите номер телефона, чтобы начать чат
                </p>

                <!-- Dialpad -->
                <div class="mt-6 grid grid-cols-3 gap-x-6 gap-y-4 w-[300px]">
                    <button
                        v-for="k in dialKeys"
                        :key="k.digit"
                        @click="pressKey(k.digit)"
                        class="dial-btn"
                        type="button"
                    >
                        <span class="dial-digit">{{ k.digit }}</span>
                        <span v-if="k.letters" class="dial-letters">{{ k.letters }}</span>
                    </button>
                    <button
                        @click="pressKey('+')"
                        class="dial-btn"
                        type="button"
                    >
                        <span class="dial-digit">+</span>
                    </button>
                    <button
                        @click="pressKey('0')"
                        class="dial-btn"
                        type="button"
                    >
                        <span class="dial-digit">0</span>
                    </button>
                    <button
                        @click="backspace"
                        class="dial-btn"
                        type="button"
                        :disabled="!phoneInput"
                        :class="{ 'opacity-40 cursor-not-allowed': !phoneInput }"
                    >
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 6H9l-5 6 5 6h12a1 1 0 001-1V7a1 1 0 00-1-1zM14 10l4 4m0-4l-4 4" />
                        </svg>
                    </button>
                </div>

                <div class="h-24"></div>
            </div>

            <!-- Start FAB -->
            <transition name="fab">
                <button
                    v-if="canStartPhone"
                    @click="startChatWithPhone"
                    :disabled="starting"
                    class="absolute bottom-6 right-6 w-14 h-14 rounded-full shadow-lg flex items-center justify-center transition hover:brightness-95"
                    :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                    title="Начать чат"
                    type="button"
                >
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M1.101 21.757L23.8 12.028 1.101 2.3l.011 7.912 13.623 1.816-13.623 1.817-.011 7.912z"/>
                    </svg>
                </button>
            </transition>
        </template>

        <!-- List mode content -->
        <template v-else>
        <!-- Session picker -->
        <div
            v-if="sessions.length > 1"
            class="px-4 py-2 border-b shrink-0"
            :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel-header)' }"
        >
            <label class="block text-xs mb-1" :style="{ color: 'var(--wa-text-secondary)' }">
                Отправитель
            </label>
            <select
                v-model="selectedSessionId"
                class="w-full rounded-md px-2 py-1 text-sm border focus:outline-none focus:ring-0"
                :style="{
                    background: 'var(--wa-panel)',
                    color: 'var(--wa-text)',
                    borderColor: 'var(--wa-border-strong)',
                }"
            >
                <option v-for="s in sessions" :key="s.id" :value="s.id">
                    {{ s.display_name || s.phone_number || s.session_name }}
                    <template v-if="s.status !== 'connected'"> (не подключён)</template>
                </option>
            </select>
        </div>

        <!-- Search -->
        <div class="px-3 py-2 shrink-0">
            <div class="relative rounded-full" :style="{ background: 'var(--wa-panel-header)' }">
                <svg
                    class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--wa-icon)]"
                    fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input
                    v-model="search"
                    type="text"
                    placeholder="Поиск по имени или номеру"
                    class="w-full pl-12 pr-3 py-[9px] bg-transparent rounded-full text-sm text-[var(--wa-text)] border-0 focus:ring-0 focus:outline-none"
                />
            </div>
        </div>

        <!-- Scrollable list -->
        <div class="flex-1 overflow-y-auto wa-scrollbar">
            <!-- Start with unknown phone -->
            <button
                v-if="hasPhoneInSearch"
                @click="startPhoneFromSearch"
                class="action-row w-full"
                type="button"
                :disabled="starting || !selectedSessionId"
            >
                <div class="action-icon" :style="{ background: 'var(--wa-accent)' }">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a2 2 0 011.94 1.515l.72 2.88a2 2 0 01-.45 1.82L9 10.5a11 11 0 005.5 5.5l1.285-1.49a2 2 0 011.82-.45l2.88.72A2 2 0 0122 16.72V20a2 2 0 01-2 2A16 16 0 013 5z" />
                    </svg>
                </div>
                <div class="flex-1 min-w-0 text-left">
                    <div class="text-[15px]" :style="{ color: 'var(--wa-text)' }">
                        Написать на «{{ search }}»
                    </div>
                    <div class="text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                        Будет создан новый контакт
                    </div>
                </div>
            </button>

            <!-- Quick actions -->
            <div
                class="action-row cursor-not-allowed opacity-60"
                title="Скоро"
            >
                <div class="action-icon" :style="{ background: 'var(--wa-accent)' }">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                </div>
                <span class="text-[15px]" :style="{ color: 'var(--wa-text)' }">Новая группа</span>
            </div>

            <div
                class="action-row cursor-not-allowed opacity-60"
                title="Скоро"
            >
                <div class="action-icon" :style="{ background: 'var(--wa-accent)' }">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                    </svg>
                </div>
                <span class="text-[15px]" :style="{ color: 'var(--wa-text)' }">Новый контакт</span>
            </div>

            <!-- Self -->
            <div class="action-row">
                <div
                    class="w-[49px] h-[49px] rounded-full flex items-center justify-center shrink-0 text-white font-medium bg-[#6b7c85]"
                >
                    {{ (currentUser?.name || '?').charAt(0).toUpperCase() }}
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-[15px] truncate" :style="{ color: 'var(--wa-text)' }">
                        {{ currentUser?.name }} (Вы)
                    </div>
                    <div class="text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                        Сообщение для себя
                    </div>
                </div>
            </div>

            <!-- Loading / empty -->
            <div
                v-if="loading"
                class="p-6 text-center text-sm"
                :style="{ color: 'var(--wa-text-secondary)' }"
            >
                Загрузка контактов…
            </div>
            <div
                v-else-if="contacts.length === 0"
                class="p-6 text-center text-sm"
                :style="{ color: 'var(--wa-text-secondary)' }"
            >
                Контакты не найдены.
                <template v-if="hasPhoneInSearch">
                    Нажмите «Написать на "{{ search }}"» выше, чтобы начать чат с новым номером.
                </template>
            </div>

            <!-- Contacts grouped by letter -->
            <template v-for="[letter, group] in groupedContacts" :key="letter">
                <div
                    class="px-4 pt-3 pb-1 text-[13px]"
                    :style="{ color: 'var(--wa-accent)' }"
                >
                    {{ letter }}
                </div>
                <button
                    v-for="c in group"
                    :key="c.id"
                    @click="startChatWithContact(c)"
                    :disabled="starting || !selectedSessionId"
                    class="contact-row w-full"
                    type="button"
                >
                    <div
                        v-if="c.profile_picture_url"
                        class="w-[49px] h-[49px] rounded-full shrink-0 overflow-hidden bg-[#6b7c85]"
                    >
                        <img :src="c.profile_picture_url" class="w-full h-full object-cover" alt="" />
                    </div>
                    <div
                        v-else
                        class="w-[49px] h-[49px] rounded-full flex items-center justify-center shrink-0 text-white font-medium bg-[#6b7c85]"
                    >
                        {{ contactInitial(c) }}
                    </div>
                    <div class="flex-1 min-w-0 text-left border-b pb-3 -mb-3"
                         :style="{ borderColor: 'var(--wa-divider)' }">
                        <div class="text-[15px] truncate" :style="{ color: 'var(--wa-text)' }">
                            {{ contactLabel(c) }}
                        </div>
                        <div
                            v-if="contactSubtitle(c)"
                            class="text-xs truncate"
                            :style="{ color: 'var(--wa-text-secondary)' }"
                        >
                            {{ contactSubtitle(c) }}
                        </div>
                    </div>
                </button>
            </template>

            <div class="h-4"></div>
        </div>
        </template>
    </div>
</template>

<style scoped>
.action-row {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    padding: 0.625rem 1rem;
    transition: background-color 0.15s ease;
}
.action-row:hover:not(.cursor-not-allowed) {
    background-color: var(--wa-panel-hover);
}
.action-icon {
    width: 49px;
    height: 49px;
    border-radius: 9999px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.contact-row {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    padding: 0.625rem 0.75rem 0.625rem 1rem;
    transition: background-color 0.15s ease;
}
.contact-row:hover {
    background-color: var(--wa-panel-hover);
}
.contact-row:disabled {
    cursor: not-allowed;
    opacity: 0.7;
}
.dial-btn {
    width: 72px;
    height: 64px;
    border-radius: 0.75rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--wa-text);
    transition: background-color 0.15s ease;
    user-select: none;
}
.dial-btn:hover:not(:disabled) {
    background-color: var(--wa-panel-hover);
}
.dial-btn:active:not(:disabled) {
    background-color: var(--wa-selected);
}
.dial-digit {
    font-size: 1.625rem;
    line-height: 1;
    font-weight: 400;
}
.dial-letters {
    font-size: 0.7rem;
    letter-spacing: 0.08em;
    margin-top: 2px;
    color: var(--wa-text-secondary);
}
.fab-enter-active,
.fab-leave-active {
    transition: transform 0.18s ease, opacity 0.18s ease;
}
.fab-enter-from,
.fab-leave-to {
    transform: scale(0.6) translateY(8px);
    opacity: 0;
}
</style>
