<script setup lang="ts">
import { computed } from 'vue';
import PeriodRangePicker from '@/components/PeriodRangePicker.vue';
import DocsLink from '@/components/DocsLink.vue';
import type { PeriodRangeChange } from '@/types/app';

const props = withDefaults(defineProps<{
    title: string;
    subtitle?: string | null;
    docs?: string | null;
    period: string;
    startDate?: string | null;
    endDate?: string | null;
    loading?: boolean;
}>(), {
    subtitle: null,
    docs: null,
    startDate: null,
    endDate: null,
    loading: false,
});

const emit = defineEmits<{
    'update:period': [value: string];
    'update:startDate': [value: string | null];
    'update:endDate': [value: string | null];
    change: [payload: PeriodRangeChange];
}>();

const periodModel = computed({
    get: () => props.period,
    set: (value) => emit('update:period', value),
});

const startDateModel = computed({
    get: () => props.startDate,
    set: (value) => emit('update:startDate', value),
});

const endDateModel = computed({
    get: () => props.endDate,
    set: (value) => emit('update:endDate', value),
});
</script>

<template>
    <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ title }}</h1>
                <DocsLink v-if="docs" :page="docs" />
            </div>
            <p v-if="subtitle" class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ subtitle }}</p>
            <slot name="subtitle" />
        </div>
        <PeriodRangePicker
            v-model:period="periodModel"
            v-model:start-date="startDateModel"
            v-model:end-date="endDateModel"
            :loading="loading"
            @change="(payload) => emit('change', payload)"
        />
    </div>
</template>
