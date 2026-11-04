<script setup>
import { useHelpers } from '@/composables/useHelpers';
import ProviderLogo from '@/components/ProviderLogo.vue';

// The overview's Performance card: per model, the error rate and latency
// percentiles. Averages hide the slow tail; p95 and p99 show it.
defineProps({
    models: { type: Array, default: () => [] },
});

const { formatNumber, formatDuration } = useHelpers();

const latency = (ms) => (ms === null || ms === undefined ? '—' : formatDuration(ms));
const errorRate = (rate) => `${(Number(rate || 0) * 100).toLocaleString('en-US', { maximumFractionDigits: 1 })}%`;
const errorClass = (rate) => {
    if (rate >= 0.05) return 'text-red-600 dark:text-red-400';
    if (rate >= 0.01) return 'text-amber-600 dark:text-amber-400';

    return 'text-gray-500 dark:text-gray-400';
};
</script>

<template>
    <div class="card overflow-hidden">
        <div class="card-header">
            <div>
                <h3>Performance</h3>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Error rate over all calls; latency over successful ones</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Model</th>
                        <th class="text-right">Requests</th>
                        <th class="text-right">Error rate</th>
                        <th class="text-right">p50</th>
                        <th class="text-right">p95</th>
                        <th class="text-right">p99</th>
                        <th class="text-right" title="Median time to first token of streamed calls">First token</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="model in models" :key="model.provider + model.model">
                        <td class="max-w-0 w-[34%]">
                            <div class="flex min-w-0 items-center gap-2.5">
                                <ProviderLogo
                                    :provider="model.provider"
                                    :title="model.provider"
                                    class="inline-flex h-4 w-4 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full"
                                    aria-hidden="true"
                                />
                                <span class="truncate font-medium text-gray-900 dark:text-white" :title="model.model">{{ model.model }}</span>
                            </div>
                        </td>
                        <td class="text-right tabular-nums">{{ formatNumber(model.request_count) }}</td>
                        <td class="text-right tabular-nums font-medium" :class="errorClass(model.error_rate)" :title="`${formatNumber(model.failed_count)} failed`">{{ errorRate(model.error_rate) }}</td>
                        <td class="text-right tabular-nums">{{ latency(model.latency_p50) }}</td>
                        <td class="text-right tabular-nums font-medium text-gray-900 dark:text-white">{{ latency(model.latency_p95) }}</td>
                        <td class="text-right tabular-nums">{{ latency(model.latency_p99) }}</td>
                        <td class="text-right tabular-nums text-gray-500 dark:text-gray-400">{{ latency(model.ttft_p50) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
