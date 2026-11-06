<script setup lang="ts">
import { useHelpers } from '@/composables/useHelpers';
import type { DashboardBudgetLimit } from '@/types/api';

// Budget periods are the budget's own, so the page's period doesn't change them.
defineProps<{ limits: DashboardBudgetLimit[] }>();

const { formatCurrency, formatNumber } = useHelpers();

const periodLabels = { daily: 'Today', weekly: 'This week', monthly: 'This month', total: 'All time' };
const metricLabels = { cost: 'spend', tokens: 'tokens', requests: 'requests' };

const amount = (limit: DashboardBudgetLimit, value: number) => (limit.metric === 'cost' ? formatCurrency(value) : formatNumber(value));
const percent = (limit: DashboardBudgetLimit) => (limit.used / limit.limit) * 100;
const barClass = (limit: DashboardBudgetLimit) => {
    const used = percent(limit);

    if (used >= 100) return 'bg-red-500';

    return used >= 80 ? 'bg-amber-500' : 'bg-primary-500';
};
</script>

<template>
    <div class="card h-full">
        <div class="card-header">
            <div>
                <h3>Budget</h3>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Usage against your limits</p>
            </div>
        </div>
        <div class="card-body">
            <div v-if="!limits.length" class="empty-state py-8">
                <p class="empty-state-description">No budget is set</p>
            </div>
            <ul v-else class="space-y-4">
                <li v-for="limit in limits" :key="`${limit.metric}-${limit.period}`">
                    <div class="mb-1.5 flex items-baseline justify-between gap-3 text-sm">
                        <span class="text-gray-700 dark:text-gray-300">{{ periodLabels[limit.period] }}, {{ metricLabels[limit.metric] }}</span>
                        <span class="tabular-nums text-gray-500 dark:text-gray-400">{{ amount(limit, limit.used) }} of {{ amount(limit, limit.limit) }}</span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                        <div class="h-full rounded-full" :class="barClass(limit)" :style="{ width: Math.min(percent(limit), 100) + '%' }"></div>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>
