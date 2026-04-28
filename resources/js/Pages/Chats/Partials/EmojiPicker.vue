<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';

const emit = defineEmits<{
    (e: 'select', emoji: string): void;
    (e: 'close'): void;
}>();

const categories = [
    {
        id: 'recent',
        label: 'Недавние',
        icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
    },
    {
        id: 'smileys',
        label: 'Смайлы и люди',
        icon: 'M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    },
    {
        id: 'nature',
        label: 'Животные и природа',
        icon: 'M7 11l5-5m0 0l5 5m-5-5v12',
    },
    {
        id: 'food',
        label: 'Еда и напитки',
        icon: 'M3 5a2 2 0 012-2h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5z',
    },
    {
        id: 'activities',
        label: 'Активности',
        icon: 'M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 100 4m0-4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 100 4m0-4v2m0-6V4',
    },
    {
        id: 'travel',
        label: 'Путешествия',
        icon: 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    },
    {
        id: 'objects',
        label: 'Предметы',
        icon: 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z',
    },
    {
        id: 'symbols',
        label: 'Символы',
        icon: 'M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z',
    },
    {
        id: 'flags',
        label: 'Флаги',
        icon: 'M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9',
    },
];

const emojiData: Record<string, string[]> = {
    smileys: [
        '😀','😃','😄','😁','😆','😅','🤣','😂','🙂','🙃','😉','😊','😇','🥰','😍','🤩','😘','😗','😚','😙',
        '😋','😛','😜','🤪','😝','🤑','🤗','🤭','🤫','🤔','🤐','🤨','😐','😑','😶','😏','😒','🙄','😬','🤥',
        '😌','😔','😪','🤤','😴','😷','🤒','🤕','🤢','🤮','🤧','🥵','🥶','🥴','😵','🤯','🤠','🥳','😎','🤓',
        '🧐','😕','😟','🙁','☹️','😮','😯','😲','😳','🥺','😦','😧','😨','😰','😥','😢','😭','😱','😖','😣',
        '😞','😓','😩','😫','🥱','😤','😡','😠','🤬','😈','👿','💀','☠️','💩','🤡','👹','👺','👻','👽','👾',
        '🤖','😺','😸','😹','😻','😼','😽','🙀','😿','😾','❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔',
        '❣️','💕','💞','💓','💗','💖','💘','💝','💟','💌','👍','👎','👊','✊','🤛','🤜','🤞','✌️','🤟','🤘',
        '👌','🤏','👈','👉','👆','👇','☝️','✋','🤚','🖐️','🖖','👋','🤙','💪','🙏','🤲','👐','🙌','🤝','💅',
    ],
    nature: [
        '🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐨','🐯','🦁','🐮','🐷','🐽','🐸','🐵','🙈','🙉','🙊','🐒',
        '🐔','🐧','🐦','🐤','🐣','🐥','🦆','🦅','🦉','🦇','🐺','🐗','🐴','🦄','🐝','🐛','🦋','🐌','🐞','🐜',
        '🦟','🦗','🕷️','🕸️','🦂','🐢','🐍','🦎','🦖','🦕','🐙','🦑','🦐','🦞','🦀','🐡','🐠','🐟','🐬','🐳',
        '🐋','🦈','🐊','🐅','🐆','🦓','🦍','🦧','🐘','🦛','🦏','🐪','🐫','🦒','🦘','🐃','🐂','🐄','🐎','🐖',
        '🌸','💮','🏵️','🌹','🥀','🌺','🌻','🌼','🌷','🌱','🌲','🌳','🌴','🌵','🌾','🌿','☘️','🍀','🍁','🍂',
        '🍃','🍄','🌰','🌍','🌎','🌏','🌕','🌖','🌗','🌘','🌑','🌒','🌓','🌔','🌙','🌛','🌜','☀️','🌝','🌞',
        '⭐','🌟','✨','⚡','☄️','💥','🔥','🌪️','🌈','☀️','🌤️','⛅','🌥️','☁️','🌦️','🌧️','⛈️','🌩️','🌨️','❄️',
    ],
    food: [
        '🍏','🍎','🍐','🍊','🍋','🍌','🍉','🍇','🍓','🫐','🍈','🍒','🍑','🥭','🍍','🥥','🥝','🍅','🍆','🥑',
        '🥦','🥬','🥒','🌶️','🫑','🌽','🥕','🫒','🧄','🧅','🥔','🍠','🥐','🥯','🍞','🥖','🥨','🧀','🥚','🍳',
        '🧈','🥞','🧇','🥓','🥩','🍗','🍖','🦴','🌭','🍔','🍟','🍕','🥪','🥙','🧆','🌮','🌯','🫔','🥗','🥘',
        '🫕','🥫','🍝','🍜','🍲','🍛','🍣','🍱','🥟','🦪','🍤','🍙','🍚','🍘','🍥','🥠','🥮','🍢','🍡','🍧',
        '🍨','🍦','🥧','🧁','🍰','🎂','🍮','🍭','🍬','🍫','🍿','🍩','🍪','🌰','🥜','🍯','🥛','🍼','☕','🫖',
        '🍵','🧃','🥤','🧋','🍶','🍺','🍻','🥂','🍷','🥃','🍸','🍹','🧉','🍾','🧊','🥄','🍴','🍽️','🥣','🥡',
    ],
    activities: [
        '⚽','🏀','🏈','⚾','🥎','🎾','🏐','🏉','🥏','🎱','🪀','🏓','🏸','🥅','🏒','🏑','🥍','🏏','🪃','🥊',
        '🥋','⛳','⛸️','🎣','🤿','🎽','🎿','🛷','🥌','🎯','🪁','🎱','🔮','🪄','🧿','🎮','🕹️','🎰','🎲','🧩',
        '🧸','🪅','🪆','🎨','🖌️','🖍️','🧵','🪡','🧶','🪢','🎭','🎫','🎟️','🎪','🤹','🎬','🎤','🎧','🎼','🎹',
        '🥁','🎷','🎺','🎸','🪕','🎻','🪘','🎲','♟️','🎯','🎳','🎮','🎰',
    ],
    travel: [
        '🚗','🚕','🚙','🚌','🚎','🏎️','🚓','🚑','🚒','🚐','🛻','🚚','🚛','🚜','🛴','🚲','🛵','🏍️','🛺','🚔',
        '🚍','🚘','🚖','🚡','🚠','🚟','🚃','🚋','🚞','🚝','🚄','🚅','🚈','🚂','🚆','🚇','🚊','🚉','✈️','🛫',
        '🛬','🛩️','💺','🛰️','🚀','🛸','🚁','🛶','⛵','🚤','🛥️','🛳️','⛴️','🚢','⚓','⛽','🚧','🚦','🚥','🚏',
        '🗺️','🗿','🗽','🗼','🏰','🏯','🏟️','🎡','🎢','🎠','⛲','⛱️','🏖️','🏝️','🏜️','🌋','⛰️','🏔️','🗻','🏕️',
        '⛺','🏠','🏡','🏘️','🏚️','🏗️','🏭','🏢','🏬','🏣','🏤','🏥','🏦','🏨','🏪','🏫','🏩','💒','🏛️','⛪',
        '🕌','🕍','🛕','🕋','⛩️','🛤️','🛣️','🗾','🎑','🏞️','🌅','🌄','🌠','🎇','🎆','🌇','🌆','🏙️','🌃','🌌',
    ],
    objects: [
        '⌚','📱','📲','💻','⌨️','🖥️','🖨️','🖱️','🖲️','🕹️','🗜️','💽','💾','💿','📀','📼','📷','📸','📹','🎥',
        '📽️','🎞️','📞','☎️','📟','📠','📺','📻','🎙️','🎚️','🎛️','🧭','⏱️','⏲️','⏰','🕰️','⌛','⏳','📡','🔋',
        '🔌','💡','🔦','🕯️','🪔','🧯','🛢️','💸','💵','💴','💶','💷','💰','💳','💎','⚖️','🧰','🔧','🔨','⚒️',
        '🛠️','⛏️','🔩','⚙️','🧱','⛓️','🧲','🔫','💣','🧨','🪓','🔪','🗡️','⚔️','🛡️','🚬','⚰️','⚱️','🏺','🔮',
    ],
    symbols: [
        '❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝','💟','☮️',
        '✝️','☪️','🕉️','☸️','✡️','🔯','🕎','☯️','☦️','🛐','⛎','♈','♉','♊','♋','♌','♍','♎','♏','♐',
        '♑','♒','♓','🆔','⚛️','🉑','☢️','☣️','📴','📳','🈶','🈚','🈸','🈺','🈷️','✴️','🆚','💮','🉐','㊙️',
        '㊗️','🈴','🈵','🈹','🈲','🅰️','🅱️','🆎','🆑','🅾️','🆘','❌','⭕','🛑','⛔','📛','🚫','💯','💢','♨️',
        '🚷','🚯','🚳','🚱','🔞','📵','🚭','❗','❕','❓','❔','‼️','⁉️','🔅','🔆','〽️','⚠️','🚸','🔱','⚜️',
        '🔰','♻️','✅','🈯','💹','❇️','✳️','❎','🌐','💠','Ⓜ️','🌀','💤','🏧','🚾','♿','🅿️','🛗','🈁','🈂️',
    ],
    flags: [
        '🏁','🚩','🎌','🏴','🏳️','🏳️‍🌈','🏳️‍⚧️','🏴‍☠️',
        '🇷🇺','🇰🇿','🇺🇸','🇬🇧','🇩🇪','🇫🇷','🇮🇹','🇪🇸','🇵🇹','🇺🇦','🇧🇾','🇵🇱','🇨🇳','🇯🇵','🇰🇷','🇮🇳','🇹🇷','🇦🇪','🇸🇦','🇨🇦',
        '🇦🇺','🇳🇿','🇧🇷','🇲🇽','🇦🇷','🇿🇦','🇳🇬','🇪🇬','🇮🇱','🇬🇷','🇨🇭','🇸🇪','🇳🇴','🇩🇰','🇫🇮','🇳🇱','🇧🇪','🇦🇹','🇨🇿','🇭🇺',
        '🇷🇴','🇧🇬','🇮🇪','🇮🇸','🇪🇪','🇱🇻','🇱🇹','🇰🇬','🇺🇿','🇹🇯','🇹🇲','🇦🇿','🇬🇪','🇦🇲','🇲🇳','🇹🇭','🇻🇳','🇮🇩','🇲🇾','🇸🇬',
    ],
};

const RECENT_KEY = 'wa_recent_emojis_v1';
const recent = ref<string[]>([]);

function loadRecent() {
    try {
        const raw = localStorage.getItem(RECENT_KEY);
        recent.value = raw ? JSON.parse(raw) : [];
    } catch {
        recent.value = [];
    }
}

function pushRecent(emoji: string) {
    const filtered = recent.value.filter((e) => e !== emoji);
    recent.value = [emoji, ...filtered].slice(0, 32);
    try { localStorage.setItem(RECENT_KEY, JSON.stringify(recent.value)); } catch {}
}

loadRecent();

const activeCategory = ref<string>(recent.value.length ? 'recent' : 'smileys');
const search = ref('');

const flatEmojis = computed(() => {
    const all = new Set<string>();
    for (const list of Object.values(emojiData)) list.forEach((e) => all.add(e));
    return Array.from(all);
});

const displayedEmojis = computed(() => {
    if (search.value.trim()) {
        const q = search.value.trim();
        return flatEmojis.value.filter((e) => e.includes(q));
    }
    if (activeCategory.value === 'recent') {
        return recent.value.length ? recent.value : emojiData.smileys.slice(0, 32);
    }
    return emojiData[activeCategory.value] || [];
});

const activeLabel = computed(() => {
    if (search.value.trim()) return 'Результаты поиска';
    return categories.find((c) => c.id === activeCategory.value)?.label || '';
});

function pick(emoji: string) {
    pushRecent(emoji);
    emit('select', emoji);
}

function onDocClick(e: MouseEvent) {
    const target = e.target as HTMLElement;
    if (!target.closest('.emoji-picker') && !target.closest('[data-emoji-trigger]')) {
        emit('close');
    }
}

function onEscape(e: KeyboardEvent) {
    if (e.key === 'Escape') emit('close');
}

onMounted(() => {
    document.addEventListener('mousedown', onDocClick);
    document.addEventListener('keydown', onEscape);
});
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onDocClick);
    document.removeEventListener('keydown', onEscape);
});
</script>

<template>
    <div
        class="emoji-picker absolute bottom-full mb-2 w-[360px] h-[360px] rounded-lg shadow-2xl border flex flex-col overflow-hidden"
        :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border-strong)' }"
    >
        <!-- Header / search -->
        <div class="px-3 pt-3 pb-2">
            <div class="text-xs font-medium mb-2" :style="{ color: 'var(--wa-text-secondary)' }">
                {{ activeLabel }}
            </div>
            <div class="relative">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Искать"
                    class="w-full pl-8 pr-3 py-1.5 rounded-md text-sm border-0 focus:outline-none focus:ring-0"
                    :style="{ background: 'var(--wa-panel-input)', color: 'var(--wa-text)' }"
                />
                <svg class="w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2" :style="{ color: 'var(--wa-text-secondary)' }" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
            </div>
        </div>

        <!-- Emoji grid -->
        <div class="flex-1 overflow-y-auto px-2 wa-scrollbar">
            <div class="grid grid-cols-8 gap-1">
                <button
                    v-for="e in displayedEmojis"
                    :key="e"
                    @click="pick(e)"
                    type="button"
                    class="w-10 h-10 flex items-center justify-center text-[22px] rounded hover:bg-[var(--wa-panel-hover)] transition leading-none"
                >
                    {{ e }}
                </button>
            </div>
            <div v-if="!displayedEmojis.length" class="py-10 text-center text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                Ничего не найдено
            </div>
        </div>

        <!-- Category tabs -->
        <div class="flex items-center border-t px-1" :style="{ borderColor: 'var(--wa-border)' }">
            <button
                v-for="cat in categories"
                :key="cat.id"
                @click="activeCategory = cat.id; search = ''"
                type="button"
                :title="cat.label"
                class="flex-1 py-2 flex items-center justify-center cat-tab"
                :class="{ 'cat-tab-active': activeCategory === cat.id && !search.trim() }"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="cat.icon" />
                </svg>
            </button>
        </div>
    </div>
</template>

<style scoped>
.emoji-picker {
    animation: picker-pop 0.14s ease-out;
    z-index: 50;
}
@keyframes picker-pop {
    from { opacity: 0; transform: translateY(6px) scale(0.98); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
.cat-tab {
    color: var(--wa-text-secondary);
    border-bottom: 3px solid transparent;
    transition: color 0.12s ease, border-color 0.12s ease;
}
.cat-tab:hover { color: var(--wa-text); }
.cat-tab-active {
    color: var(--wa-accent);
    border-bottom-color: var(--wa-accent);
}
</style>
