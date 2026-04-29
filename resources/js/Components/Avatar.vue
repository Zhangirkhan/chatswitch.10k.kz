<script setup lang="ts">
import { computed, ref, watch } from 'vue';

/**
 * Avatar — renders a rounded profile picture if `avatarUrl` is provided and
 * loads successfully, otherwise falls back to a neutral WhatsApp-style silhouette.
 *
 * Note: `name` is accepted for accessibility (alt text / title) but intentionally
 * NEVER used to render initials.
 */
const props = withDefaults(
    defineProps<{
        avatarUrl?: string | null;
        name?: string | null;
        isGroup?: boolean;
        size?: number;
    }>(),
    {
        avatarUrl: null,
        name: '',
        isGroup: false,
        size: 49,
    },
);

const failed = ref(false);

watch(
    () => props.avatarUrl,
    () => {
        failed.value = false;
    },
);

const showImage = computed(() => !!props.avatarUrl && !failed.value);

const style = computed(() => ({
    width: `${props.size}px`,
    height: `${props.size}px`,
}));

function onError() {
    failed.value = true;
}
</script>

<template>
    <div class="avatar" :style="style" :title="name || undefined">
        <img
            v-if="showImage"
            :src="avatarUrl!"
            :alt="name || ''"
            class="avatar__img"
            draggable="false"
            @error="onError"
        />
        <svg
            v-else
            class="avatar__icon"
            :class="props.isGroup ? 'avatar__icon--group' : 'avatar__icon--user'"
            :viewBox="props.isGroup ? '0 0 24 24' : '0 0 212 212'"
            aria-hidden="true"
            focusable="false"
        >
            <template v-if="props.isGroup">
                <path
                    fill="currentColor"
                    d="M16 11c1.66 0 3-1.34 3-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3Zm-8 0c1.66 0 3-1.34 3-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3Zm0 2c-2.33 0-7 1.17-7 3.5V20h14v-1.5C15 14.17 10.33 13 8 13Zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 2.02 1.97 3.45V20h7v-1.5c0-2.33-4.67-3.5-7-3.5Z"
                />
            </template>
            <template v-else>
                <path
                    d="M106.251.5C164.653.5 212 47.846 212 106.25S164.653 212 106.25 212C47.846 212 .5 164.654.5 106.25S47.846.5 106.251.5z"
                    fill="currentColor"
                    opacity="0"
                />
                <path
                    d="M173.561 171.615a62.767 62.767 0 0 0-2.065-2.955 67.7 67.7 0 0 0-2.608-3.299 70.112 70.112 0 0 0-3.184-3.527 71.094 71.094 0 0 0-5.924-5.47 72.458 72.458 0 0 0-10.204-7.026 75.2 75.2 0 0 0-5.98-3.055c-.062-.028-.118-.059-.18-.087-9.792-4.44-22.106-7.529-37.416-7.529s-27.624 3.089-37.416 7.529c-.338.153-.653.318-.985.474a75.37 75.37 0 0 0-6.229 3.298 72.589 72.589 0 0 0-9.15 6.395 71.243 71.243 0 0 0-5.924 5.47 70.064 70.064 0 0 0-3.184 3.527 67.142 67.142 0 0 0-2.609 3.299 63.292 63.292 0 0 0-2.065 2.955 56.33 56.33 0 0 0-1.447 2.324c-.033.056-.073.119-.104.174a47.92 47.92 0 0 0-1.07 1.926c-.559 1.068-.818 1.678-.818 1.678v.398c18.285 17.927 43.322 28.985 70.945 28.985 27.678 0 52.761-11.103 71.055-29.095v-.289s-.619-1.45-1.992-3.778a58.346 58.346 0 0 0-1.446-2.322zM106.002 125.5c2.645 0 5.212-.253 7.68-.737a38.272 38.272 0 0 0 3.624-.896 37.124 37.124 0 0 0 5.12-1.958 36.307 36.307 0 0 0 6.15-3.67 35.923 35.923 0 0 0 9.489-10.48 36.558 36.558 0 0 0 2.422-4.84 37.051 37.051 0 0 0 1.716-5.25c.299-1.208.542-2.443.725-3.701.275-1.887.417-3.827.417-5.811s-.142-3.925-.417-5.811a38.734 38.734 0 0 0-1.215-5.494 36.68 36.68 0 0 0-3.648-8.298 35.923 35.923 0 0 0-9.489-10.48 36.347 36.347 0 0 0-6.15-3.67 37.124 37.124 0 0 0-5.12-1.958 37.67 37.67 0 0 0-3.624-.896 39.875 39.875 0 0 0-7.68-.737c-21.162 0-37.345 16.183-37.345 37.345 0 21.159 16.183 37.342 37.345 37.342z"
                    fill="currentColor"
                />
            </template>
        </svg>
    </div>
</template>

<style scoped>
.avatar {
    position: relative;
    flex-shrink: 0;
    border-radius: 50%;
    overflow: hidden;
    background: var(--wa-avatar-bg);
    color: var(--wa-avatar-icon);
    display: flex;
    align-items: center;
    justify-content: center;
}

.avatar__img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    user-select: none;
}

.avatar__icon {
    width: 100%;
    height: 100%;
    display: block;
}

.avatar__icon--group {
    width: 72%;
    height: 72%;
}

.avatar__icon--user {
    width: 100%;
    height: 100%;
}
</style>
