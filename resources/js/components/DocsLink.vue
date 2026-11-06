<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    page: string;
    // What the link is about, as its tooltip says it.
    label?: string;
}>(), {
    label: 'Read the docs',
});

const href = computed(() => `https://spectraphp.com/${props.page}`);
</script>

<template>
    <a
        :href="href"
        target="_blank"
        rel="noopener noreferrer"
        :aria-label="label"
        v-tooltip="label"
        class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-white text-xs font-semibold text-gray-500 shadow-xs transition-colors duration-150 hover:border-primary-300 hover:bg-primary-50 hover:text-primary-600 dark:border-gray-700/80 dark:bg-gray-900 dark:text-gray-400 dark:hover:border-primary-500/50 dark:hover:bg-primary-500/10 dark:hover:text-primary-400"
    >?</a>
</template>
