<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    code?: number | string | null;
    // The call's error_type: a 2xx answer that carries one (a stream that
    // broke off, a job the provider reports as failed) is a failed call.
    error?: string | boolean | null;
}>(), {
    code: null,
    error: null,
});

const numericCode = computed(() => {
    if (props.code === null || props.code === undefined) return null;
    return Number(props.code);
});

const variant = computed(() => {
    const code = numericCode.value;
    if (!code) return 'unknown';
    if (code >= 200 && code < 300) return props.error ? 'failed' : 'success';
    if (code >= 300 && code < 400) return 'redirect';
    if (code >= 400 && code < 500) return 'client-error';
    if (code >= 500) return 'server-error';
    return 'unknown';
});

const classes = computed(() => {
    const map = {
        'success': 'bg-emerald-50 text-emerald-700 ring-emerald-600/15 dark:bg-emerald-400/10 dark:text-emerald-300 dark:ring-emerald-400/20',
        'redirect': 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/20',
        'client-error': 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/20',
        'server-error': 'bg-red-50 text-red-700 ring-red-600/15 dark:bg-red-400/10 dark:text-red-300 dark:ring-red-400/20',
        'failed': 'bg-red-50 text-red-700 ring-red-600/15 dark:bg-red-400/10 dark:text-red-300 dark:ring-red-400/20',
        'unknown': 'bg-gray-50 text-gray-600 ring-gray-500/20 dark:bg-gray-500/10 dark:text-gray-400 dark:ring-gray-500/20',
    };
    return map[variant.value];
});

const label = computed(() => {
    if (!numericCode.value) return 'N/A';
    return String(numericCode.value);
});

const hint = computed(() => (variant.value === 'failed' ? 'The provider answered HTTP ' + label.value + ' but reported an error' : ''));
</script>

<template>
    <span :class="['inline-flex items-center gap-1.5 rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset tabular-nums', classes]" v-tooltip="hint">
        <span class="h-1.5 w-1.5 rounded-full bg-current opacity-80" aria-hidden="true"></span>
        {{ label }}
    </span>
</template>
