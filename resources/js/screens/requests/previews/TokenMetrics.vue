<script setup>
import { computed } from 'vue';
import { useHelpers } from '@/composables/useHelpers';

const props = defineProps({
    request: { type: Object, required: true },
    expanded: { type: Boolean, default: true },
});

const emit = defineEmits(['toggle']);

const { formatNumber } = useHelpers();

const hasReasoning = computed(() => props.request.reasoning_tokens > 0);
</script>

<template>
    <div class="card">
        <div
            class="card-header cursor-pointer select-none"
            :class="{ 'border-b-0': !expanded }"
            @click="emit('toggle')"
        >
            <div class="flex items-center justify-between w-full">
                <div class="flex items-center gap-2">
                    <svg
                        class="w-4 h-4 text-gray-400 transition-transform duration-200"
                        :class="{ 'rotate-90': expanded }"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                    <h3 class="text-sm font-medium">Token Usage</h3>
                </div>
            </div>
        </div>
        <div v-if="expanded" class="card-body">
            <div class="grid gap-6" :class="hasReasoning ? 'grid-cols-4' : 'grid-cols-3'">
                <div class="text-center p-4 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Prompt</div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                        {{ formatNumber(request.prompt_tokens) }}
                    </div>
                </div>
                <div class="text-center p-4 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Completion</div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                        {{ formatNumber(request.completion_tokens) }}
                    </div>
                </div>
                <div v-if="hasReasoning" class="text-center p-4 rounded-lg bg-violet-50 dark:bg-violet-900/20">
                    <div class="text-sm font-medium text-violet-600 dark:text-violet-400 mb-1">Reasoning</div>
                    <div class="text-2xl font-bold text-violet-700 dark:text-violet-300 tabular-nums">
                        {{ formatNumber(request.reasoning_tokens) }}<span v-if="request.reasoning_effort" class="text-sm font-medium text-violet-500 dark:text-violet-400"> / {{ request.reasoning_effort }}</span>
                    </div>
                </div>
                <div class="text-center p-4 rounded-lg bg-primary-50 dark:bg-primary-900/20">
                    <div class="text-sm font-medium text-primary-600 dark:text-primary-400 mb-1">Total</div>
                    <div class="text-2xl font-bold text-primary-700 dark:text-primary-300 tabular-nums">
                        {{ formatNumber(request.total_tokens) }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
