<script setup>
import { computed } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import Money from '@/components/Money.vue';

/**
 * Token usage for one request. Input is split into uncached, cache-read and
 * cache-write tokens (the three are billed at different rates); output into
 * answer and reasoning tokens.
 */
const props = defineProps({
    request: { type: Object, required: true },
    expanded: { type: Boolean, default: true },
});

const emit = defineEmits(['toggle']);

const { formatNumber } = useHelpers();

const input = computed(() => {
    const total = Number(props.request.prompt_tokens || 0);
    const cached = Math.min(Number(props.request.cached_tokens || 0), total);
    const written = Math.min(Number(props.request.cache_write_tokens || 0), total - cached);

    return {
        total,
        segments: [
            { key: 'uncached', label: 'Uncached', value: total - cached - written, class: 'bg-primary-500' },
            { key: 'cached', label: 'Cache read', value: cached, class: 'bg-emerald-500' },
            { key: 'written', label: 'Cache write', value: written, class: 'bg-amber-500' },
        ],
    };
});

const output = computed(() => {
    const total = Number(props.request.completion_tokens || 0);
    const reasoning = Math.min(Number(props.request.reasoning_tokens || 0), total);

    return {
        total,
        segments: [
            { key: 'answer', label: 'Answer', value: total - reasoning, class: 'bg-sky-500' },
            { key: 'reasoning', label: 'Reasoning', value: reasoning, class: 'bg-violet-500' },
        ],
    };
});

const cacheHitRate = computed(() => (input.value.total > 0 ? (Number(props.request.cached_tokens || 0) / input.value.total) * 100 : 0));

const width = (value, total) => (total > 0 ? (value / total) * 100 : 0) + '%';
</script>

<template>
    <div class="card">
        <div class="card-header">
            <button type="button" class="flex items-center gap-2" @click="emit('toggle')">
                <svg class="h-4 w-4 text-gray-400 transition-transform duration-200" :class="{ 'rotate-90': expanded }" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" /></svg>
                <h3>Token usage</h3>
            </button>
            <span v-if="cacheHitRate > 0" class="badge badge-success">{{ cacheHitRate.toFixed(0) }}% from cache</span>
        </div>

        <div v-if="expanded" class="card-body space-y-6">
            <div v-for="group in [{ key: 'input', title: 'Input', data: input, cost: request.prompt_cost }, { key: 'output', title: 'Output', data: output, cost: request.completion_cost }]" :key="group.key">
                <div class="mb-2 flex items-baseline justify-between gap-4">
                    <div class="flex items-baseline gap-2">
                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ group.title }}</span>
                        <span class="text-sm tabular-nums text-gray-500 dark:text-gray-400">{{ formatNumber(group.data.total) }} tokens</span>
                    </div>
                    <span v-if="group.cost" class="text-sm tabular-nums text-gray-500 dark:text-gray-400"><Money :cents="group.cost" /></span>
                </div>

                <div class="flex h-2 gap-0.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                    <div
                        v-for="segment in group.data.segments.filter((s) => s.value > 0)"
                        :key="segment.key"
                        :class="segment.class"
                        class="h-full first:rounded-l-full last:rounded-r-full"
                        :style="{ width: width(segment.value, group.data.total) }"
                        v-tooltip="`${segment.label}: ${formatNumber(segment.value)}`"
                    ></div>
                </div>

                <div class="mt-2.5 flex flex-wrap gap-x-5 gap-y-1.5 text-xs">
                    <div v-for="segment in group.data.segments" :key="segment.key" class="flex items-center gap-1.5" :class="{ 'opacity-50': segment.value === 0 }">
                        <span class="h-2 w-2 rounded-sm" :class="segment.class" aria-hidden="true"></span>
                        <span class="text-gray-500 dark:text-gray-400">{{ segment.label }}</span>
                        <span class="tabular-nums font-medium text-gray-900 dark:text-white">{{ formatNumber(segment.value) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
