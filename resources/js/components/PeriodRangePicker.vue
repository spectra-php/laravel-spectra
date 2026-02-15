<script setup>
import { computed, ref, watch } from 'vue';
import { VueDatePicker } from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';

const props = defineProps({
    period: {
        type: String,
        default: 'month',
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
    label: {
        type: String,
        default: '',
    },
});

const emit = defineEmits([
    'update:period',
    'update:startDate',
    'update:endDate',
    'change',
]);

const rangeModel = ref(null);

const periodModel = computed({
    get: () => props.period,
    set: (value) => emit('update:period', value),
});

const selectedRangeLabel = computed(() => {
    if (props.startDate && props.endDate) {
        return `${props.startDate} to ${props.endDate}`;
    }

    return 'Select date range';
});

const parseDate = (value) => {
    if (!value) return null;
    const parsed = new Date(`${value}T00:00:00`);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
};

const formatDate = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
};

watch(
    () => [props.startDate, props.endDate],
    ([startDate, endDate]) => {
        const start = parseDate(startDate);
        const end = parseDate(endDate);
        rangeModel.value = start && end ? [start, end] : null;
    },
    { immediate: true },
);

const emitChange = (payload) => {
    emit('change', payload);
};

const onPeriodChange = () => {
    emit('update:startDate', null);
    emit('update:endDate', null);

    emitChange({
        period: periodModel.value,
        startDate: null,
        endDate: null,
    });
};

const onRangeChange = (range) => {
    rangeModel.value = range;

    if (!Array.isArray(range) || !range[0] || !range[1]) return;

    const [first, second] = range;
    const start = first <= second ? first : second;
    const end = first <= second ? second : first;
    const startDate = formatDate(start);
    const endDate = formatDate(end);

    emit('update:startDate', startDate);
    emit('update:endDate', endDate);
    emitChange({
        period: 'custom',
        startDate,
        endDate,
    });
};
</script>

<template>
    <div class="flex flex-col sm:flex-row gap-2 sm:gap-3">
        <div class="w-full sm:w-56">
            <label v-if="label" class="block text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1.5">{{ label }}</label>
            <select
                v-model="periodModel"
                class="form-select w-full"
                :disabled="loading"
                @change="onPeriodChange"
            >
                <option value="today">Today</option>
                <option value="week">This Week</option>
                <option value="month">This Month</option>
                <option value="year">This Year</option>
                <option value="all">All Time</option>
            </select>
        </div>

        <div class="w-full sm:w-56">
            <VueDatePicker
                v-model="rangeModel"
                auto-apply
                :enable-time-picker="false"
                :range="{ partialRange: false }"
                format="yyyy-MM-dd"
                :teleport="true"
                :placeholder="selectedRangeLabel"
                :disabled="loading"
                :clearable="false"
                @update:model-value="onRangeChange"
            >
                <template #dp-input="{ value, openMenu }">
                    <button
                        type="button"
                        class="form-select w-full text-left"
                        :disabled="loading"
                        @click.stop="openMenu"
                    >
                        <span class="block truncate">{{ value || selectedRangeLabel }}</span>
                    </button>
                </template>
            </VueDatePicker>
        </div>
    </div>
</template>
