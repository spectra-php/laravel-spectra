<script lang="ts">
export const dashboardPeriods = [
    { value: 'today', label: 'Today' },
    { value: 'week', label: 'This Week' },
    { value: 'month', label: 'This Month' },
    { value: 'year', label: 'This Year' },
];
</script>

<script setup lang="ts">
import Skeleton from '@/components/Skeleton.vue';

// The title of a shared dashboard's page, laid out like the Overview's, with the
// presets its viewers may choose.
defineProps<{
    title: string | null;
    subtitle: string;
    period?: string;
}>();

const emit = defineEmits<{ 'update:period': [period: string] }>();
</script>

<template>
    <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div class="min-w-0">
            <Skeleton v-if="title === null" variant="text" width="12rem" height="2rem" />
            <template v-else>
                <h1 class="truncate text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ title }}</h1>
                <p class="mt-1 truncate text-sm text-gray-500 dark:text-gray-400">{{ subtitle }}</p>
            </template>
        </div>
        <select
            v-if="period"
            class="form-select w-full sm:w-48"
            aria-label="Period"
            :value="period"
            @change="emit('update:period', ($event.target as HTMLSelectElement).value)"
        >
            <option v-for="option in dashboardPeriods" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
    </div>
</template>
