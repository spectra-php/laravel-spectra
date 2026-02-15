<script setup>
import { computed } from 'vue';

const props = defineProps({
    code: {
        type: [Number, String, null],
        default: null,
    },
});

const numericCode = computed(() => {
    if (props.code === null || props.code === undefined) return null;
    return Number(props.code);
});

const variant = computed(() => {
    const code = numericCode.value;
    if (!code) return 'unknown';
    if (code >= 200 && code < 300) return 'success';
    if (code >= 300 && code < 400) return 'redirect';
    if (code >= 400 && code < 500) return 'client-error';
    if (code >= 500) return 'server-error';
    return 'unknown';
});

const classes = computed(() => {
    const map = {
        'success': 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/20',
        'redirect': 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/20',
        'client-error': 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/20',
        'server-error': 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/20',
        'unknown': 'bg-gray-50 text-gray-600 ring-gray-500/20 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20',
    };
    return map[variant.value];
});

const label = computed(() => {
    if (!numericCode.value) return 'N/A';
    return String(numericCode.value);
});
</script>

<template>
    <span :class="['inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset tabular-nums', classes]">
        {{ label }}
    </span>
</template>
