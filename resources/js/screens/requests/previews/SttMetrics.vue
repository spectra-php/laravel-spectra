<script setup>
import { computed } from 'vue';
import { useHelpers } from '@/composables/useHelpers';

const props = defineProps({
    request: { type: Object, required: true },
    expanded: { type: Boolean, default: true },
});

const emit = defineEmits(['toggle']);

const { formatNumber, formatCurrency } = useHelpers();

const hasTokens = computed(() => (props.request.prompt_tokens || 0) + (props.request.completion_tokens || 0) > 0);
const hasDuration = computed(() => props.request.duration_seconds > 0);
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
                    <h3 class="text-sm font-medium">Speech-to-Text</h3>
                </div>
            </div>
        </div>
        <div v-if="expanded" class="card-body">
            <!-- Token-based models (gpt-4o-transcribe, etc.) -->
            <div v-if="hasTokens" class="grid grid-cols-3 gap-6">
                <div class="text-center p-4 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Input Tokens</div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                        {{ formatNumber(request.prompt_tokens || 0) }}
                    </div>
                </div>
                <div class="text-center p-4 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Output Tokens</div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                        {{ formatNumber(request.completion_tokens || 0) }}
                    </div>
                </div>
                <div class="text-center p-4 rounded-lg bg-primary-50 dark:bg-primary-900/20">
                    <div class="text-sm font-medium text-primary-600 dark:text-primary-400 mb-1">Cost</div>
                    <div class="text-2xl font-bold text-primary-700 dark:text-primary-300 tabular-nums">
                        {{ formatCurrency(request.total_cost_in_cents) }}
                    </div>
                </div>
            </div>

            <!-- Duration-based models (whisper-1) -->
            <div v-else class="grid grid-cols-2 gap-6">
                <div class="text-center p-4 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Duration</div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                        {{ (request.duration_seconds || 0).toFixed(1) }}s
                    </div>
                </div>
                <div class="text-center p-4 rounded-lg bg-primary-50 dark:bg-primary-900/20">
                    <div class="text-sm font-medium text-primary-600 dark:text-primary-400 mb-1">Cost</div>
                    <div class="text-2xl font-bold text-primary-700 dark:text-primary-300 tabular-nums">
                        {{ formatCurrency(request.total_cost_in_cents) }}
                    </div>
                </div>
            </div>

            <!-- Show duration alongside tokens if both present -->
            <div v-if="hasTokens && hasDuration" class="mt-3 text-center text-sm text-gray-500 dark:text-gray-400">
                Audio duration: {{ (request.duration_seconds || 0).toFixed(1) }}s
            </div>
        </div>
    </div>
</template>
