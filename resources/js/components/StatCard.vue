<script setup lang="ts">
import Skeleton from '@/components/Skeleton.vue';

withDefaults(defineProps<{
    label: string;
    // Kept for backwards compatibility with older screens; no longer rendered.
    gradient?: string | null;
    // Optional accent dot color (any CSS color).
    accent?: string | null;
    loading?: boolean;
    periodLabel?: string | null;
    skeletonWidth?: string;
}>(), {
    gradient: null,
    accent: null,
    loading: false,
    periodLabel: null,
    skeletonWidth: '60%',
});
</script>

<template>
    <div class="stat-card flex flex-col">
        <div class="flex items-center gap-2">
            <span v-if="accent" class="h-2 w-2 rounded-full" :style="{ backgroundColor: accent }" aria-hidden="true"></span>
            <div class="stat-label">{{ label }}</div>
            <div class="ml-auto"><slot name="action" /></div>
        </div>
        <template v-if="loading">
            <Skeleton variant="text" :width="skeletonWidth" height="2rem" class="mt-2" />
        </template>
        <template v-else>
            <slot />
        </template>
        <div v-if="periodLabel" class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ periodLabel }}</div>
        <slot name="footer" />
    </div>
</template>
