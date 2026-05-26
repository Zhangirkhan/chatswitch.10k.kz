<template>
  <i :class="['material-icons', sizeClass, iconClass]">{{ materialIconName }}</i>
</template>

<script setup lang="ts">
const props = withDefaults(defineProps<{
  name: string
  size?: 'sm' | 'md' | 'lg' | 'xl'
}>(), {
  size: 'md'
})

// Маппинг иконок на Material Icons
const iconMap: Record<string, string> = {
  'wave': 'waving_hand',
  'mailbox': 'mail',
  'distribution': 'hub',
  'phone': 'smartphone',
  'chat': 'chat_bubble',
  'team': 'group',
  'target': 'target',
  'pin': 'bookmark',
  'eye': 'visibility',
  'book': 'menu_book',
  'online': 'circle',
  'check': 'check_circle'
}

const materialIconName = iconMap[props.name] || props.name

const sizeClass = {
  sm: 'text-sm',
  md: 'text-base',
  lg: 'text-2xl',
  xl: 'text-4xl'
}[props.size]

const iconClass = 'select-none'
</script>

<style scoped>
@import url('https://fonts.googleapis.com/icon?family=Material+Icons');

i.material-icons {
  font-family: 'Material Icons';
  font-weight: normal;
  font-style: normal;
  font-size: 24px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  text-transform: none;
  letter-spacing: normal;
  word-wrap: normal;
  white-space: nowrap;
  direction: ltr;
}
</style>
