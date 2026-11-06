<script setup lang="ts">
import { computed } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import TraceWaterfall from '@/components/TraceWaterfall.vue';
import Money from '@/components/Money.vue';
import type { TraceDetailsResponse } from '@/types/api';

const props = defineProps<{
    trace: TraceDetailsResponse;
}>();

const { formatNumber, formatDuration } = useHelpers();

const failedCount = computed(() => props.trace.failed_count ?? (props.trace.requests || []).filter((step) => step.is_error).length);
</script>

<template>
    <div class="card overflow-hidden">
        <div class="card-header">
            <div class="flex min-w-0 items-center gap-2.5">
                <svg class="h-4 w-4 shrink-0 text-gray-400" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                </svg>
                <h3>Trace</h3>
                <span class="truncate text-xs text-gray-500 dark:text-gray-400">Call {{ trace.position }} of {{ trace.request_count }}</span>
            </div>
            <router-link
                :to="{ name: 'trace-detail', params: { traceId: trace.trace_id } }"
                class="shrink-0 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
            >Open trace</router-link>
        </div>

        <!-- Totals for the whole trace -->
        <dl class="grid grid-cols-3 divide-x divide-gray-100 border-b border-gray-100 dark:divide-gray-800 dark:border-gray-800">
            <div class="px-5 py-3">
                <dt class="text-xs text-gray-500 dark:text-gray-400">Total cost</dt>
                <dd class="mt-0.5 text-sm font-semibold tabular-nums text-gray-900 dark:text-white"><Money :cents="trace.total_cost_in_cents" /></dd>
            </div>
            <div class="px-5 py-3">
                <dt class="text-xs text-gray-500 dark:text-gray-400">Total tokens</dt>
                <dd class="mt-0.5 text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ formatNumber(trace.total_tokens) }}</dd>
            </div>
            <div class="px-5 py-3">
                <dt class="text-xs text-gray-500 dark:text-gray-400">Duration</dt>
                <dd class="mt-0.5 text-sm font-semibold tabular-nums text-gray-900 dark:text-white">
                    {{ formatDuration(trace.duration_ms) }}
                    <span v-if="failedCount" class="ml-1 text-xs font-medium text-red-600 dark:text-red-400">· {{ failedCount }} failed</span>
                </dd>
            </div>
        </dl>

        <TraceWaterfall :requests="trace.requests || []" :spans="trace.spans || []" :duration-ms="trace.timeline_ms || trace.duration_ms || 0" />

        <div v-if="trace.truncated" class="border-t border-gray-100 px-5 py-2.5 text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
            Showing the first {{ (trace.requests || []).length }} of {{ formatNumber(trace.request_count) }} calls.
            <router-link :to="{ name: 'requests', query: { trace_id: trace.trace_id } }" class="font-medium text-primary-600 hover:underline dark:text-primary-400">See all</router-link>
        </div>
    </div>
</template>
