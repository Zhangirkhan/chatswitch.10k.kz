<script setup lang="ts">
import { useLandingHead } from '@/composables/useLandingHead';
import { Head } from '@inertiajs/vue3';

const props = defineProps<{
    title: string;
}>();

const { metaDescription } = useLandingHead();
</script>

<template>
    <Head :title="props.title">
        <meta head-key="description" name="description" :content="metaDescription" />
        <meta head-key="og:title" property="og:title" :content="props.title" />
        <meta head-key="og:description" property="og:description" :content="metaDescription" />
        <meta head-key="twitter:title" name="twitter:title" :content="props.title" />
        <meta head-key="twitter:description" name="twitter:description" :content="metaDescription" />
    </Head>
</template>
