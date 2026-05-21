<script setup lang="ts">
import Avatar from '@/Components/Avatar.vue';

withDefaults(
    defineProps<{
        name?: string | null;
        avatarUrl?: string | null;
        size?: number;
    }>(),
    {
        name: '',
        avatarUrl: null,
        size: 32,
    },
);
</script>

<template>
    <Avatar
        :name="name"
        :avatar-url="avatarUrl"
        :size="size"
        variant="staff"
        fallback-initials
    />
</template>
