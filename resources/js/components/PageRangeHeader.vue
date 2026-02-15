<script setup>
import { computed } from 'vue';
import PeriodRangePicker from '@/components/PeriodRangePicker.vue';

const props = defineProps({
    title: {
        type: String,
        required: true,
    },
    period: {
        type: String,
        required: true,
    },
    startDate: {
        type: String,
        default: null,
    },
    endDate: {
        type: String,
        default: null,
    },
    loading: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits([
    'update:period',
    'update:startDate',
    'update:endDate',
    'change',
]);

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
    <div class="card mb-6">
        <div class="card-header flex-col md:flex-row md:items-center gap-3">
            <h2 class="text-lg font-semibold">{{ title }}</h2>
            <PeriodRangePicker
                v-model:period="periodModel"
                v-model:start-date="startDateModel"
                v-model:end-date="endDateModel"
                :loading="loading"
                @change="(payload) => emit('change', payload)"
            />
        </div>
    </div>
</template>
