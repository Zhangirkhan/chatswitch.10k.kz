<script setup lang="ts">
import { useLandingHead, type LandingHeadPage } from '@/composables/useLandingHead';
import { Head } from '@inertiajs/vue3';
import { computed, toRef } from 'vue';

const props = withDefaults(defineProps<{
    title?: string;
    page?: LandingHeadPage;
}>(), {
    page: 'home',
});

const pageRef = toRef(props, 'page');
const { metaTitle, metaDescription } = useLandingHead(pageRef);

const resolvedTitle = computed(() => props.title ?? metaTitle.value);
</script>

<template>
    <Head :title="resolvedTitle">
        <meta head-key="description" name="description" :content="metaDescription" />
        <meta head-key="og:title" property="og:title" :content="resolvedTitle" />
        <meta head-key="og:description" property="og:description" :content="metaDescription" />
        <meta head-key="twitter:title" name="twitter:title" :content="resolvedTitle" />
        <meta head-key="twitter:description" name="twitter:description" :content="metaDescription" />
    </Head>
</template>
