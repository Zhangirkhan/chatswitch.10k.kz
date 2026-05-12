<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps<{
    url: string;
}>();

type Preview = {
    url: string;
    title: string | null;
    description: string | null;
    image: string | null;
    site_name: string | null;
};

const loading = ref(false);
const preview = ref<Preview | null>(null);

const displayHost = computed(() => {
    try {
        const u = new URL(props.url);
        return u.host;
    } catch {
        return '';
    }
});

async function load(): Promise<void> {
    const url = (props.url || '').trim();
    if (!url) {
        preview.value = null;
        return;
    }
    loading.value = true;
    try {
        const { data } = await axios.get(route('link-preview'), { params: { url } });
        if (data && data.success && data.url) {
            preview.value = {
                url: String(data.url),
                title: data.title ? String(data.title) : null,
                description: data.description ? String(data.description) : null,
                image: data.image ? String(data.image) : null,
                site_name: data.site_name ? String(data.site_name) : null,
            };
        } else {
            preview.value = { url, title: null, description: null, image: null, site_name: displayHost.value || null };
        }
    } catch {
        preview.value = { url, title: null, description: null, image: null, site_name: displayHost.value || null };
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    load();
});

watch(
    () => props.url,
    () => {
        preview.value = null;
        load();
    },
);
</script>

<template>
    <a
        class="wa-link-preview"
        :href="props.url"
        target="_blank"
        rel="noopener noreferrer nofollow"
        @click.stop
    >
        <div v-if="loading && !preview" class="wa-link-preview-skel">
            <div class="wa-link-preview-skel-img" />
            <div class="wa-link-preview-skel-lines">
                <div class="wa-link-preview-skel-line wa-link-preview-skel-line--w1" />
                <div class="wa-link-preview-skel-line wa-link-preview-skel-line--w2" />
                <div class="wa-link-preview-skel-line wa-link-preview-skel-line--w3" />
            </div>
        </div>

        <template v-else>
            <div v-if="preview?.image" class="wa-link-preview-imgwrap">
                <img :src="preview.image" alt="" loading="lazy" decoding="async" />
            </div>
            <div class="wa-link-preview-body">
                <div class="wa-link-preview-site">
                    {{ (preview?.site_name || displayHost || '').toString() }}
                </div>
                <div v-if="preview?.title" class="wa-link-preview-title">
                    {{ preview.title }}
                </div>
                <div v-if="preview?.description" class="wa-link-preview-desc">
                    {{ preview.description }}
                </div>
                <div v-if="!preview?.title && !preview?.description" class="wa-link-preview-url">
                    {{ props.url }}
                </div>
            </div>
        </template>
    </a>
</template>

<style scoped>
.wa-link-preview {
    display: flex;
    width: 100%;
    max-width: 420px;
    border-radius: 10px;
    overflow: hidden;
    text-decoration: none;
    color: inherit;
    background: var(--wa-link-preview-surface);
    border: 1px solid var(--wa-link-preview-border);
}

.wa-link-preview:hover {
    background: var(--wa-link-preview-surface-hover);
}

.wa-link-preview-imgwrap {
    width: 154px;
    flex-shrink: 0;
    background: var(--wa-link-preview-img-bg);
}
.wa-link-preview-imgwrap img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.wa-link-preview-body {
    min-width: 0;
    padding: 10px 12px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.wa-link-preview-site {
    font-size: 11px;
    letter-spacing: 0.2px;
    text-transform: uppercase;
    opacity: 0.72;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.wa-link-preview-title {
    font-size: 14px;
    font-weight: 600;
    line-height: 18px;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}

.wa-link-preview-desc,
.wa-link-preview-url {
    font-size: 12.5px;
    line-height: 16px;
    opacity: 0.86;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    word-break: break-word;
}

.wa-link-preview-url {
    opacity: 0.8;
    text-decoration: underline;
}

/* Skeleton */
.wa-link-preview-skel {
    display: flex;
    width: 100%;
}
.wa-link-preview-skel-img {
    width: 154px;
    flex-shrink: 0;
    background: var(--wa-link-preview-skel);
}
.wa-link-preview-skel-lines {
    flex: 1;
    padding: 10px 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.wa-link-preview-skel-line {
    height: 10px;
    border-radius: 6px;
    background: var(--wa-link-preview-skel);
}
.wa-link-preview-skel-line--w1 { width: 52%; }
.wa-link-preview-skel-line--w2 { width: 88%; }
.wa-link-preview-skel-line--w3 { width: 74%; }
</style>

