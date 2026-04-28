<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { useToastStore } from '@/stores/toast';
import { formatPhone } from '@/utils/phone';

const toast = useToastStore();

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

type CommunityGroup = {
    id: number;
    chat_name: string | null;
    last_message_text: string | null;
    unread_count: number;
    community_id: number | null;
};

type Community = {
    id: number;
    whatsapp_session_id: number;
    name: string;
    description: string | null;
    avatar_path: string | null;
    groups: CommunityGroup[];
};

type AvailableGroup = {
    id: number;
    chat_name: string | null;
    last_message_text: string | null;
    community_id: number | null;
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

type Mode =
    | 'list'
    | 'dial'
    | 'group-participants'
    | 'group-info'
    | 'community-info'
    | 'community-manage'
    | 'community-pick-existing';
const mode = ref<Mode>('list');
const phoneInput = ref('');

const groupSearch = ref('');
const groupSubject = ref('');
const selectedContactIds = ref<number[]>([]);
const creatingGroup = ref(false);

const DEFAULT_COMMUNITY_DESCRIPTION =
    'Приветствуем! В этом сообществе участники могут общаться в тематических группах и получать важные объявления.';
const communityName = ref('');
const communityDescription = ref(DEFAULT_COMMUNITY_DESCRIPTION);
const creatingCommunity = ref(false);
const currentCommunity = ref<Community | null>(null);
const availableGroups = ref<AvailableGroup[]>([]);
const linkingGroupId = ref<number | null>(null);
// Когда пользователь создаёт группу изнутри сообщества — сюда попадает ID сообщества.
const groupCreationCommunityId = ref<number | null>(null);

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
    return c.name || c.push_name || formatPhone(c.phone_number) || formatPhone(c.whatsapp_id) || 'Без имени';
}

function contactInitial(c: Contact): string {
    return contactLabel(c).charAt(0).toUpperCase();
}

function contactSubtitle(c: Contact): string {
    if (c.name && c.phone_number) return formatPhone(c.phone_number);
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

function openGroupParticipants() {
    selectedContactIds.value = [];
    groupSearch.value = '';
    groupSubject.value = '';
    mode.value = 'group-participants';
}

function backFromGroup() {
    mode.value = 'list';
}

function backFromGroupInfo() {
    mode.value = 'group-participants';
}

function toggleParticipant(id: number) {
    const idx = selectedContactIds.value.indexOf(id);
    if (idx >= 0) {
        selectedContactIds.value.splice(idx, 1);
    } else {
        selectedContactIds.value.push(id);
    }
}

function isParticipantSelected(id: number): boolean {
    return selectedContactIds.value.includes(id);
}

const selectedContacts = computed<Contact[]>(() => {
    const map = new Map<number, Contact>();
    for (const c of contacts.value) map.set(c.id, c);
    return selectedContactIds.value
        .map((id) => map.get(id))
        .filter((c): c is Contact => !!c);
});

const filteredGroupContacts = computed<Contact[]>(() => {
    const q = groupSearch.value.trim().toLowerCase();
    if (!q) return contacts.value;
    return contacts.value.filter((c) => {
        const label = (c.name || c.push_name || c.phone_number || '').toLowerCase();
        const digits = (c.phone_number || c.whatsapp_id || '').toLowerCase();
        return label.includes(q) || digits.includes(q);
    });
});

const groupedGroupContacts = computed(() => {
    const groups = new Map<string, Contact[]>();
    for (const c of filteredGroupContacts.value) {
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

function goToGroupInfo() {
    if (selectedContactIds.value.length === 0) return;
    mode.value = 'group-info';
}

async function createGroup() {
    const subject = groupSubject.value.trim();
    if (!subject || selectedContactIds.value.length === 0 || !selectedSessionId.value) return;
    if (creatingGroup.value) return;

    creatingGroup.value = true;
    const loadingToastId = toast.show({
        message: `Создаём группу «${subject}»…`,
        duration: 60_000,
    });

    const communityIdContext = groupCreationCommunityId.value;

    try {
        const { data } = await axios.post(route('chats.create-group'), {
            subject,
            contact_ids: selectedContactIds.value,
            whatsapp_session_id: selectedSessionId.value,
            community_id: communityIdContext,
        });

        toast.dismiss(loadingToastId);
        toast.show({
            message: `Группа «${subject}» создана`,
            duration: 4000,
        });

        // Группу создавали изнутри сообщества — возвращаемся на экран управления.
        if (communityIdContext && currentCommunity.value?.id === communityIdContext) {
            groupCreationCommunityId.value = null;
            selectedContactIds.value = [];
            groupSubject.value = '';
            await reloadCurrentCommunity();
            mode.value = 'community-manage';
            return;
        }

        if (data.chat_id) {
            router.visit(route('chats.show', data.chat_id));
        } else {
            emit('close');
        }
    } catch (err: any) {
        toast.dismiss(loadingToastId);
        const raw = String(err?.response?.data?.error || err?.message || '');
        const readable = raw.toLowerCase().includes('client not ready')
            ? 'WhatsApp-номер ещё не подключён. Дождитесь статуса «Онлайн» и попробуйте снова.'
            : raw || 'Не удалось создать группу';
        toast.show({
            message: readable,
            duration: 6000,
        });
    } finally {
        creatingGroup.value = false;
    }
}

function onHeaderBack() {
    if (mode.value === 'dial') {
        backFromDialpad();
    } else if (mode.value === 'group-participants') {
        // Если пришли в выбор участников из сообщества — возвращаемся туда.
        if (groupCreationCommunityId.value && currentCommunity.value) {
            groupCreationCommunityId.value = null;
            mode.value = 'community-manage';
        } else {
            backFromGroup();
        }
    } else if (mode.value === 'group-info') {
        backFromGroupInfo();
    } else if (mode.value === 'community-info') {
        mode.value = 'list';
    } else if (mode.value === 'community-manage') {
        // Сообщество уже создано — закрываем панель, пользователь вернётся к списку чатов.
        emit('close');
    } else if (mode.value === 'community-pick-existing') {
        mode.value = 'community-manage';
    } else {
        emit('close');
    }
}

// ---------------- Сообщества ----------------

function openNewCommunity() {
    if (!selectedSessionId.value && sessions.value.length > 0) {
        selectedSessionId.value = sessions.value[0].id;
    }
    communityName.value = '';
    communityDescription.value = DEFAULT_COMMUNITY_DESCRIPTION;
    mode.value = 'community-info';
}

async function createCommunity() {
    const name = communityName.value.trim();
    if (!name || !selectedSessionId.value || creatingCommunity.value) return;

    creatingCommunity.value = true;
    const toastId = toast.show({
        message: `Создаём сообщество «${name}»…`,
        duration: 60_000,
    });

    try {
        const { data } = await axios.post(route('communities.store'), {
            name,
            description: communityDescription.value.trim() || null,
            whatsapp_session_id: selectedSessionId.value,
        });

        currentCommunity.value = data.community as Community;
        toast.dismiss(toastId);
        toast.show({
            message: `Сообщество «${name}» создано`,
            duration: 4000,
        });
        mode.value = 'community-manage';
    } catch (err: any) {
        toast.dismiss(toastId);
        const raw = String(
            err?.response?.data?.message
            || err?.response?.data?.error
            || err?.message
            || '',
        );
        toast.show({
            message: raw || 'Не удалось создать сообщество',
            duration: 5000,
        });
    } finally {
        creatingCommunity.value = false;
    }
}

async function reloadCurrentCommunity(): Promise<void> {
    const id = currentCommunity.value?.id;
    if (!id) return;
    try {
        const { data } = await axios.get(route('communities.show', id));
        currentCommunity.value = data.community as Community;
    } catch {
        // тихо игнорируем — сеть уже упала, пользователь увидит ошибку в следующем действии
    }
}

function startNewGroupInCommunity() {
    if (!currentCommunity.value) return;
    groupCreationCommunityId.value = currentCommunity.value.id;
    selectedSessionId.value = currentCommunity.value.whatsapp_session_id;
    selectedContactIds.value = [];
    groupSearch.value = '';
    groupSubject.value = '';
    mode.value = 'group-participants';
}

async function openPickExistingGroup() {
    if (!currentCommunity.value) return;
    try {
        const { data } = await axios.get(
            route('communities.available-groups', currentCommunity.value.id),
        );
        availableGroups.value = (data.groups || []) as AvailableGroup[];
        mode.value = 'community-pick-existing';
    } catch {
        toast.show({
            message: 'Не удалось загрузить список групп',
            duration: 4000,
        });
    }
}

async function linkExistingGroup(chatId: number) {
    if (!currentCommunity.value || linkingGroupId.value) return;
    linkingGroupId.value = chatId;
    try {
        await axios.post(
            route('communities.link-group', currentCommunity.value.id),
            { chat_id: chatId },
        );
        toast.show({
            message: 'Группа добавлена в сообщество',
            duration: 3500,
        });
        await reloadCurrentCommunity();
        mode.value = 'community-manage';
    } catch (err: any) {
        toast.show({
            message: err?.response?.data?.error || 'Не удалось добавить группу',
            duration: 4000,
        });
    } finally {
        linkingGroupId.value = null;
    }
}

async function unlinkGroup(chatId: number) {
    if (!currentCommunity.value) return;
    try {
        await axios.delete(
            route('communities.unlink-group', [currentCommunity.value.id, chatId]),
        );
        await reloadCurrentCommunity();
        toast.show({ message: 'Группа убрана из сообщества', duration: 3000 });
    } catch {
        toast.show({ message: 'Не удалось убрать группу', duration: 4000 });
    }
}
</script>

<template>
    <div class="w-[400px] h-full flex flex-col bg-[var(--wa-panel)] shrink-0 relative">
        <!-- Header: list mode -->
        <div
            v-if="mode === 'list'"
            class="h-[60px] px-4 flex items-center gap-5 shrink-0"
            :style="{ color: 'var(--wa-text)' }"
        >
            <button
                @click="onHeaderBack"
                class="wa-back-btn"
                title="Назад"
                type="button"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
            </button>
            <h1 class="text-[17px] font-normal flex-1 m-0">Новый чат</h1>
            <button
                @click="openDialpad"
                class="wa-back-btn"
                title="Ввести номер телефона"
                type="button"
            >
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                    <circle cx="5" cy="6" r="1.6"/><circle cx="12" cy="6" r="1.6"/><circle cx="19" cy="6" r="1.6"/>
                    <circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>
                    <circle cx="5" cy="18" r="1.6"/><circle cx="12" cy="18" r="1.6"/><circle cx="19" cy="18" r="1.6"/>
                </svg>
            </button>
        </div>

        <!-- Header: other modes (dial / group-participants / group-info) -->
        <div
            v-else
            class="h-[60px] px-4 flex items-center gap-5 shrink-0"
            :style="{ color: 'var(--wa-text)' }"
        >
            <button
                @click="onHeaderBack"
                class="wa-back-btn"
                title="Назад" type="button"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
            </button>
            <h1 class="text-[17px] font-normal m-0 truncate">
                <template v-if="mode === 'dial'">Номер телефона</template>
                <template v-else-if="mode === 'group-participants'">
                    <template v-if="groupCreationCommunityId">Добавить участников группы</template>
                    <template v-else>Добав. участников группы</template>
                </template>
                <template v-else-if="mode === 'group-info'">Новая группа</template>
                <template v-else-if="mode === 'community-info'">Новое сообщество</template>
                <template v-else-if="mode === 'community-manage'">
                    {{ currentCommunity?.name || 'Сообщество' }}
                </template>
                <template v-else-if="mode === 'community-pick-existing'">Управление группами</template>
            </h1>
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
                        {{ s.display_name || formatPhone(s.phone_number) || s.session_name }}<template v-if="s.status !== 'connected'"> (не подключён)</template>
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
        <template v-if="mode === 'list'">
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
                    {{ s.display_name || formatPhone(s.phone_number) || s.session_name }}
                    <template v-if="s.status !== 'connected'"> (не подключён)</template>
                </option>
            </select>
        </div>

        <!-- Search -->
        <div class="px-3 py-2 shrink-0">
            <div class="relative rounded-full search-pill">
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

        <!-- Group: choose participants -->
        <template v-if="mode === 'group-participants'">
            <!-- Selected chips -->
            <div
                v-if="selectedContacts.length > 0"
                class="px-3 pt-1 pb-2 flex flex-wrap gap-2 shrink-0"
            >
                <span
                    v-for="c in selectedContacts"
                    :key="c.id"
                    class="chip-selected"
                >
                    <span class="chip-avatar" v-if="c.profile_picture_url">
                        <img :src="c.profile_picture_url" class="w-full h-full object-cover" alt="" />
                    </span>
                    <span class="chip-avatar chip-avatar-initial" v-else>
                        {{ contactInitial(c) }}
                    </span>
                    <span class="truncate max-w-[120px]">{{ contactLabel(c) }}</span>
                    <button
                        type="button"
                        class="chip-remove"
                        @click="toggleParticipant(c.id)"
                        title="Убрать"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </span>
            </div>

            <!-- Search -->
            <div class="px-4 py-2 shrink-0">
                <input
                    v-model="groupSearch"
                    type="text"
                    placeholder="Поиск по имени или номеру"
                    class="w-full bg-transparent text-sm text-[var(--wa-text)] border-0 focus:ring-0 focus:outline-none py-2 group-search-input"
                />
            </div>

            <!-- Contact list with checkboxes -->
            <div class="flex-1 overflow-y-auto wa-scrollbar">
                <div
                    v-if="loading"
                    class="p-6 text-center text-sm"
                    :style="{ color: 'var(--wa-text-secondary)' }"
                >
                    Загрузка контактов…
                </div>

                <template v-for="[letter, group] in groupedGroupContacts" :key="letter">
                    <div
                        class="px-4 pt-3 pb-1 text-[13px]"
                        :style="{ color: 'var(--wa-accent)' }"
                    >
                        {{ letter }}
                    </div>
                    <button
                        v-for="c in group"
                        :key="c.id"
                        @click="toggleParticipant(c.id)"
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
                        <div class="flex-1 min-w-0 text-left">
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
                        <span
                            class="check-circle shrink-0"
                            :class="{ 'check-circle-active': isParticipantSelected(c.id) }"
                        >
                            <svg
                                v-if="isParticipantSelected(c.id)"
                                class="w-3.5 h-3.5 text-white"
                                fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </span>
                    </button>
                </template>

                <div class="h-24"></div>
            </div>

            <!-- FAB: next -->
            <transition name="fab">
                <button
                    v-if="selectedContactIds.length > 0"
                    @click="goToGroupInfo"
                    class="absolute bottom-6 right-6 w-14 h-14 rounded-full shadow-lg flex items-center justify-center transition hover:brightness-95"
                    :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                    title="Далее"
                    type="button"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
            </transition>
        </template>

        <!-- Group: set subject -->
        <template v-if="mode === 'group-info'">
            <div class="flex-1 overflow-y-auto wa-scrollbar">
                <!-- Group avatar + subject input -->
                <div class="px-4 pt-6 pb-4 flex items-center gap-4">
                    <div
                        class="w-[75px] h-[75px] rounded-full flex items-center justify-center shrink-0"
                        :style="{ background: 'var(--wa-panel-header)' }"
                    >
                        <svg class="w-8 h-8 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h2l2-2h6l2 2h2a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                            <circle cx="12" cy="13" r="3.5" />
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <label class="block text-[13px] mb-1" :style="{ color: 'var(--wa-accent)' }">
                            Название группы
                        </label>
                        <input
                            v-model="groupSubject"
                            type="text"
                            maxlength="100"
                            placeholder="Укажите название группы"
                            class="w-full bg-transparent text-[15px] text-[var(--wa-text)] border-0 focus:ring-0 focus:outline-none py-1 subject-input"
                        />
                    </div>
                </div>

                <div
                    class="px-4 pt-6 pb-2 text-[13px]"
                    :style="{ color: 'var(--wa-text-secondary)' }"
                >
                    Участники: {{ selectedContacts.length }}
                </div>

                <div class="px-4 pb-2 flex flex-wrap gap-2">
                    <span
                        v-for="c in selectedContacts"
                        :key="c.id"
                        class="chip-selected"
                    >
                        <span class="chip-avatar" v-if="c.profile_picture_url">
                            <img :src="c.profile_picture_url" class="w-full h-full object-cover" alt="" />
                        </span>
                        <span class="chip-avatar chip-avatar-initial" v-else>
                            {{ contactInitial(c) }}
                        </span>
                        <span class="truncate max-w-[120px]">{{ contactLabel(c) }}</span>
                        <button
                            type="button"
                            class="chip-remove"
                            @click="toggleParticipant(c.id)"
                            title="Убрать"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </span>
                </div>

                <div class="h-24"></div>
            </div>

            <!-- FAB: create (постоянно виден, пока выбран хотя бы 1 участник) -->
            <transition name="fab">
                <button
                    v-if="selectedContacts.length > 0"
                    @click="createGroup"
                    :disabled="creatingGroup || groupSubject.trim().length === 0"
                    class="absolute bottom-6 left-1/2 -translate-x-1/2 w-14 h-14 rounded-full shadow-lg flex items-center justify-center transition hover:brightness-95 disabled:opacity-50 disabled:cursor-not-allowed"
                    :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                    :title="groupSubject.trim().length === 0 ? 'Введите название группы' : 'Создать группу'"
                    type="button"
                >
                    <svg
                        v-if="!creatingGroup"
                        class="w-6 h-6"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.5"
                        viewBox="0 0 24 24"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    <svg
                        v-else
                        class="w-6 h-6 animate-spin"
                        viewBox="0 0 24 24"
                        fill="none"
                        aria-hidden="true"
                    >
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.3" stroke-width="2.5" />
                        <path
                            d="M21 12a9 9 0 0 0-9-9"
                            stroke="currentColor"
                            stroke-width="2.5"
                            stroke-linecap="round"
                        />
                    </svg>
                </button>
            </transition>
        </template>

        <!-- Community: info (name + description) -->
        <template v-if="mode === 'community-info'">
            <!-- Hint banner -->
            <div
                class="mx-3 mt-3 px-4 py-3 rounded-md flex items-start gap-3 text-[13px] shrink-0"
                :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text-secondary)' }"
            >
                <svg class="w-4 h-4 mt-0.5 shrink-0" :style="{ color: 'var(--wa-accent)' }" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 2a10 10 0 100 20 10 10 0 000-20zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z" />
                </svg>
                <span>
                    Сообщество объединяет ваши WhatsApp-группы в одном месте.
                    Сначала задайте имя, затем добавьте группы.
                </span>
            </div>

            <!-- Session picker (if multiple) -->
            <div
                v-if="sessions.length > 1"
                class="px-4 py-2 shrink-0"
                :style="{ background: 'var(--wa-panel-header)' }"
            >
                <label class="block text-xs mb-1" :style="{ color: 'var(--wa-text-secondary)' }">
                    WhatsApp-номер
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
                        {{ s.display_name || formatPhone(s.phone_number) || s.session_name }}<template v-if="s.status !== 'connected'"> (не подключён)</template>
                    </option>
                </select>
            </div>

            <!-- Form -->
            <div class="flex-1 overflow-y-auto wa-scrollbar">
                <div class="px-6 pt-8 pb-4 flex flex-col items-center">
                    <div
                        class="w-[112px] h-[112px] rounded-full flex items-center justify-center shrink-0"
                        :style="{ background: 'var(--wa-panel-header)' }"
                    >
                        <svg class="w-10 h-10 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h2l2-2h6l2 2h2a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                            <circle cx="12" cy="13" r="3.5" />
                        </svg>
                    </div>
                </div>

                <div class="px-6">
                    <label class="block text-[13px] mb-1" :style="{ color: 'var(--wa-accent)' }">
                        Название сообщества
                    </label>
                    <input
                        v-model="communityName"
                        type="text"
                        maxlength="100"
                        placeholder="Укажите название"
                        class="w-full bg-transparent text-[15px] text-[var(--wa-text)] border-0 focus:ring-0 focus:outline-none py-1 subject-input"
                    />
                </div>

                <div class="px-6 pt-6">
                    <label class="block text-[13px] mb-1" :style="{ color: 'var(--wa-text-secondary)' }">
                        Описание сообщества
                    </label>
                    <textarea
                        v-model="communityDescription"
                        rows="4"
                        maxlength="2048"
                        class="w-full rounded-md px-3 py-2 text-[14px] border focus:outline-none focus:ring-0"
                        :style="{
                            background: 'var(--wa-panel-header)',
                            color: 'var(--wa-text)',
                            borderColor: 'transparent',
                        }"
                    ></textarea>
                </div>

                <div class="h-24"></div>
            </div>

            <!-- FAB: create -->
            <transition name="fab">
                <button
                    v-if="communityName.trim().length > 0 || creatingCommunity"
                    @click="createCommunity"
                    :disabled="creatingCommunity || communityName.trim().length === 0 || !selectedSessionId"
                    class="absolute bottom-6 left-1/2 -translate-x-1/2 w-14 h-14 rounded-full shadow-lg flex items-center justify-center transition hover:brightness-95 disabled:opacity-50 disabled:cursor-not-allowed"
                    :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                    :title="creatingCommunity ? 'Создание…' : 'Создать сообщество'"
                    type="button"
                >
                    <svg
                        v-if="!creatingCommunity"
                        class="w-6 h-6"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.5"
                        viewBox="0 0 24 24"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                    <svg
                        v-else
                        class="w-6 h-6 animate-spin"
                        viewBox="0 0 24 24"
                        fill="none"
                        aria-hidden="true"
                    >
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.3" stroke-width="2.5" />
                        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                    </svg>
                </button>
            </transition>
        </template>

        <!-- Community: manage groups -->
        <template v-if="mode === 'community-manage' && currentCommunity">
            <div class="flex-1 overflow-y-auto wa-scrollbar">
                <div class="px-4 pt-4 pb-3 flex items-center gap-4">
                    <div
                        class="w-[56px] h-[56px] rounded-full flex items-center justify-center shrink-0"
                        :style="{ background: 'var(--wa-panel-header)' }"
                    >
                        <svg class="w-6 h-6 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h2l2-2h6l2 2h2a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                            <circle cx="12" cy="13" r="3.5" />
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-[16px] truncate" :style="{ color: 'var(--wa-text)' }">
                            {{ currentCommunity.name }}
                        </div>
                        <div
                            v-if="currentCommunity.description"
                            class="text-[13px] truncate"
                            :style="{ color: 'var(--wa-text-secondary)' }"
                        >
                            {{ currentCommunity.description }}
                        </div>
                    </div>
                </div>

                <div
                    class="px-4 pt-4 pb-2 text-[13px]"
                    :style="{ color: 'var(--wa-text-secondary)' }"
                >
                    Управление группами
                </div>

                <button
                    type="button"
                    class="action-row w-full"
                    @click="startNewGroupInCommunity"
                >
                    <div class="action-icon" :style="{ background: 'var(--wa-accent)' }">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </div>
                    <span class="text-[15px] text-left flex-1" :style="{ color: 'var(--wa-text)' }">Создать новую группу</span>
                </button>

                <button
                    type="button"
                    class="action-row w-full"
                    @click="openPickExistingGroup"
                >
                    <div class="action-icon" :style="{ background: 'var(--wa-accent)' }">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                    </div>
                    <span class="text-[15px] text-left flex-1" :style="{ color: 'var(--wa-text)' }">Добавить существующую группу</span>
                </button>

                <div
                    v-if="currentCommunity.groups?.length"
                    class="px-4 pt-5 pb-1 text-[13px]"
                    :style="{ color: 'var(--wa-text-secondary)' }"
                >
                    Группы в этом сообществе
                </div>

                <button
                    v-for="g in (currentCommunity.groups || [])"
                    :key="g.id"
                    class="contact-row w-full"
                    type="button"
                    @click="router.visit(route('chats.show', g.id))"
                >
                    <div
                        class="w-[49px] h-[49px] rounded-full flex items-center justify-center shrink-0"
                        :style="{ background: 'var(--wa-avatar-bg)' }"
                    >
                        <svg class="w-6 h-6" :style="{ color: 'var(--wa-avatar-icon)' }" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm6 3.6c-1.4 0-2.6.5-3.6 1.3-1 .8-2.2 1.1-3.4 1.1s-2.4-.3-3.4-1.1c-1-.8-2.2-1.3-3.6-1.3C2 15.6 0 17.6 0 20v.6h24V20c0-2.4-2-4.4-6-4.4z"/>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0 text-left">
                        <div class="text-[15px] truncate" :style="{ color: 'var(--wa-text)' }">
                            {{ g.chat_name || 'Группа' }}
                        </div>
                        <div
                            v-if="g.last_message_text"
                            class="text-xs truncate"
                            :style="{ color: 'var(--wa-text-secondary)' }"
                        >
                            {{ g.last_message_text }}
                        </div>
                    </div>
                    <button
                        type="button"
                        class="wa-back-btn shrink-0"
                        title="Убрать из сообщества"
                        @click.stop="unlinkGroup(g.id)"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </button>

                <div class="h-8"></div>
            </div>
        </template>

        <!-- Community: pick existing group -->
        <template v-if="mode === 'community-pick-existing'">
            <div class="flex-1 overflow-y-auto wa-scrollbar">
                <div
                    v-if="availableGroups.length === 0"
                    class="p-6 text-center text-sm"
                    :style="{ color: 'var(--wa-text-secondary)' }"
                >
                    Нет доступных групп для добавления.
                    <br />
                    Сначала создайте новую группу.
                </div>

                <button
                    v-for="g in availableGroups"
                    :key="g.id"
                    class="contact-row w-full"
                    type="button"
                    :disabled="linkingGroupId !== null"
                    @click="linkExistingGroup(g.id)"
                >
                    <div
                        class="w-[49px] h-[49px] rounded-full flex items-center justify-center shrink-0"
                        :style="{ background: 'var(--wa-avatar-bg)' }"
                    >
                        <svg class="w-6 h-6" :style="{ color: 'var(--wa-avatar-icon)' }" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm6 3.6c-1.4 0-2.6.5-3.6 1.3-1 .8-2.2 1.1-3.4 1.1s-2.4-.3-3.4-1.1c-1-.8-2.2-1.3-3.6-1.3C2 15.6 0 17.6 0 20v.6h24V20c0-2.4-2-4.4-6-4.4z"/>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0 text-left">
                        <div class="text-[15px] truncate" :style="{ color: 'var(--wa-text)' }">
                            {{ g.chat_name || 'Группа' }}
                        </div>
                        <div
                            v-if="g.community_id"
                            class="text-xs truncate"
                            :style="{ color: 'var(--wa-text-secondary)' }"
                        >
                            Состоит в другом сообществе — будет перенесена
                        </div>
                    </div>
                    <svg
                        v-if="linkingGroupId === g.id"
                        class="w-5 h-5 animate-spin"
                        :style="{ color: 'var(--wa-accent)' }"
                        viewBox="0 0 24 24"
                        fill="none"
                    >
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.3" stroke-width="2.5" />
                        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                    </svg>
                </button>
            </div>
        </template>
    </div>
</template>

<style scoped>
.wa-back-btn {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 9999px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--wa-icon);
    transition: background-color 0.15s ease;
}
.wa-back-btn:hover {
    background-color: var(--wa-panel-hover);
}
.search-pill {
    background: var(--wa-panel-header);
    border: 1.5px solid transparent;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.search-pill:focus-within {
    border-color: var(--wa-accent);
    background: var(--wa-panel);
}
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
.chip-selected {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 6px 3px 3px;
    background: var(--wa-panel-header);
    border-radius: 9999px;
    font-size: 13px;
    color: var(--wa-text);
    max-width: 100%;
}
.chip-avatar {
    width: 24px;
    height: 24px;
    border-radius: 9999px;
    overflow: hidden;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #6b7c85;
    color: #fff;
    font-size: 11px;
    font-weight: 500;
    flex-shrink: 0;
}
.chip-remove {
    width: 18px;
    height: 18px;
    border-radius: 9999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--wa-icon);
    transition: background-color 0.15s ease;
}
.chip-remove:hover {
    background-color: var(--wa-panel-hover);
}
.check-circle {
    width: 22px;
    height: 22px;
    border-radius: 9999px;
    border: 1.8px solid var(--wa-icon);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.15s ease, border-color 0.15s ease;
    margin-right: 6px;
}
.check-circle-active {
    background: var(--wa-accent);
    border-color: var(--wa-accent);
}
.group-search-input {
    border-bottom: 1px solid var(--wa-border-strong) !important;
    border-radius: 0;
    padding-left: 0;
    padding-right: 0;
}
.group-search-input:focus {
    border-bottom-color: var(--wa-accent) !important;
}
.subject-input {
    border-bottom: 1px solid var(--wa-border-strong) !important;
    border-radius: 0;
}
.subject-input:focus {
    border-bottom-color: var(--wa-accent) !important;
}
</style>
