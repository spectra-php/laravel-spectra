<script setup>
import Skeleton from '@/components/Skeleton.vue';

defineProps({
    label: {
        type: String,
        required: true,
    },
    gradient: {
        type: String,
        default: 'from-primary-500 to-cyan-500',
    },
    loading: {
        type: Boolean,
        default: false,
    },
    periodLabel: {
        type: String,
        default: null,
    },
    skeletonWidth: {
        type: String,
        default: '60%',
    },
});
</script>

<template>
    <div class="stat-card !p-5 relative overflow-hidden">
        <div :class="['absolute inset-x-0 top-0 h-0.5 bg-gradient-to-r', gradient]"></div>
        <div class="stat-label">{{ label }}</div>
        <template v-if="loading">
            <Skeleton variant="text" :width="skeletonWidth" height="2rem" class="mt-2" />
        </template>
        <template v-else>
            <slot />
        </template>
        <div v-if="periodLabel" class="text-xs text-gray-400 dark:text-gray-500 mt-1 uppercase tracking-wider">{{ periodLabel }}</div>
        <slot name="footer" />
    </div>
</template>
