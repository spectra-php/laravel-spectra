<script setup>
import { computed } from 'vue';

const props = defineProps({
    page: {
        type: String,
        required: true,
    },
});

const href = computed(() => `https://spectraphp.com/${props.page}`);
</script>

<template>
    <a
        :href="href"
        target="_blank"
        rel="noopener noreferrer"
        aria-label="Open the documentation"
        v-tooltip="'Read the docs'"
        class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-white text-xs font-semibold text-gray-500 shadow-sm transition-colors duration-150 hover:border-primary-300 hover:bg-primary-50 hover:text-primary-600 dark:border-gray-700/80 dark:bg-gray-900 dark:text-gray-400 dark:hover:border-primary-500/50 dark:hover:bg-primary-500/10 dark:hover:text-primary-400"
    >?</a>
</template>
