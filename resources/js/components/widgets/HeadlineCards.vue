<script setup lang="ts">
import { computed, ref } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import Money from '@/components/Money.vue';
import Sparkline from '@/components/Sparkline.vue';
import StatCard from '@/components/StatCard.vue';
import type { ModelTypeLatency } from '@/types/api';

// The headline numbers on the Overview and on a shared dashboard. A null cost
// means the page hides costs, so the row holds one card fewer.
const props = withDefaults(defineProps<{
    requests: number;
    requestTrend: number[];
    cost: number | null;
    tokens: number;
    avgLatency: number;
    latencyByModelType?: ModelTypeLatency[];
    periodLabel?: string;
    showTokens?: boolean;
    loading?: boolean;
}>(), {
    latencyByModelType: () => [],
    periodLabel: '',
    showTokens: true,
    loading: false,
});

const { formatNumber, formatCompact, formatDuration } = useHelpers();

const showLatencyBreakdown = ref(false);

const latencyRows = computed(() => [...props.latencyByModelType]
    .filter((row) => Number(row?.count) > 0)
    .sort((a, b) => b.count - a.count));

const columns = computed(() => {
    const cards = 2 + (props.cost !== null ? 1 : 0) + (props.showTokens ? 1 : 0);

    return cards === 4 ? 'sm:grid-cols-2 xl:grid-cols-4' : 'md:grid-cols-3';
});
</script>

<template>
    <div class="grid grid-cols-1 gap-4" :class="columns">
        <StatCard label="Requests" :loading="loading">
            <div class="stat-value">{{ formatNumber(requests) }}</div>
            <template #footer>
                <Sparkline v-if="!loading" :values="requestTrend" class="mt-3" />
            </template>
        </StatCard>
        <StatCard v-if="cost !== null" label="Spend" :loading="loading" skeleton-width="70%">
            <div class="stat-value"><Money :cents="cost" /></div>
            <template #footer>
                <div class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    <template v-if="requests">
                        <Money :cents="cost / requests" /> per request on average
                    </template>
                    <template v-else>No spend in this period</template>
                </div>
                <slot name="spend-footer" />
            </template>
        </StatCard>
        <StatCard v-if="showTokens" label="Tokens" :loading="loading" skeleton-width="50%">
            <div class="stat-value" v-tooltip="formatNumber(tokens) + ' tokens'">{{ formatCompact(tokens) }}</div>
            <template #footer>
                <div class="mt-3 text-xs text-gray-500 dark:text-gray-400">Input and output, text and embeddings</div>
            </template>
        </StatCard>
        <StatCard label="Average latency" :loading="loading" skeleton-width="40%">
            <template #action>
                <button
                    v-if="latencyRows.length"
                    type="button"
                    class="text-xs font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400"
                    @click="showLatencyBreakdown = true"
                >
                    By type
                </button>
            </template>
            <div class="stat-value">{{ formatDuration(avgLatency) }}</div>
            <template #footer>
                <div class="mt-3 text-xs text-gray-500 dark:text-gray-400">Across successful requests</div>
            </template>
        </StatCard>

        <!-- Latency by model type -->
        <div
            v-if="showLatencyBreakdown"
            class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4 backdrop-blur-xs"
            @click="showLatencyBreakdown = false"
        >
            <div class="w-full max-w-xl overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-pop dark:border-gray-800 dark:bg-gray-900" @click.stop>
                <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Average latency by model type</h3>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ periodLabel }}</p>
                    </div>
                    <button type="button" class="btn-ghost inline-flex h-8 w-8 items-center justify-center rounded-lg" @click="showLatencyBreakdown = false">
                        <span class="sr-only">Close</span>
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>
                <div class="max-h-[65vh] overflow-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Model type</th>
                                <th class="text-right">Average</th>
                                <th class="text-right">Requests</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in latencyRows" :key="row.model_type">
                                <td class="font-medium text-gray-900 dark:text-white">{{ row.label }}</td>
                                <td class="text-right tabular-nums">{{ formatDuration(row.avg_latency) }}</td>
                                <td class="text-right tabular-nums">{{ formatNumber(row.count) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
