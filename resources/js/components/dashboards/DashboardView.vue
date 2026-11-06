<script setup lang="ts">
import { computed } from 'vue';
import Skeleton from '@/components/Skeleton.vue';
import BudgetWidget from './BudgetWidget.vue';
import ProviderWidget from './ProviderWidget.vue';
import RecentRequestsWidget from './RecentRequestsWidget.vue';
import StatsWidget from './StatsWidget.vue';
import TrendWidget from './TrendWidget.vue';
import UsageByModelWidget from './UsageByModelWidget.vue';
import type { DashboardResponse } from '@/types/api';

// A shared dashboard as its viewer sees it. The admin's preview shows this same
// component, so what an admin checks is what a viewer gets.
const props = defineProps<{
    dashboard: DashboardResponse | null;
    period: string;
    loading: boolean;
}>();

const emit = defineEmits<{ 'update:period': [period: string] }>();

const periods = [
    { value: 'today', label: 'Today' },
    { value: 'week', label: 'This week' },
    { value: 'month', label: 'This month' },
    { value: 'year', label: 'This year' },
];

const widgets = computed(() => props.dashboard?.widgets ?? []);
const showCosts = computed(() => props.dashboard?.show_costs ?? false);
</script>

<template>
    <div>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div class="min-w-0">
                <Skeleton v-if="!dashboard" variant="text" width="12rem" height="1.75rem" />
                <template v-else>
                    <h1 class="truncate text-xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ dashboard.name }}</h1>
                    <p v-if="dashboard.trackable_name" class="mt-1 truncate text-sm text-gray-500 dark:text-gray-400">{{ dashboard.trackable_name }}</p>
                </template>
            </div>
            <div class="inline-flex items-center rounded-lg bg-gray-100 p-1 dark:bg-gray-800/80" role="group" aria-label="Period">
                <button
                    v-for="option in periods"
                    :key="option.value"
                    type="button"
                    :aria-pressed="period === option.value"
                    :class="[
                        'rounded-md px-3 py-1.5 text-xs font-semibold transition-colors',
                        period === option.value
                            ? 'bg-white text-gray-900 shadow-xs dark:bg-gray-800 dark:text-white'
                            : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200',
                    ]"
                    @click="emit('update:period', option.value)"
                >{{ option.label }}</button>
            </div>
        </div>

        <div v-if="!dashboard" class="card"><Skeleton variant="chart" height="240px" /></div>

        <div v-else-if="!widgets.length" class="card">
            <div class="empty-state">
                <p class="empty-state-title">Nothing to show yet</p>
            </div>
        </div>

        <div v-else class="grid grid-cols-1 gap-4 transition-opacity lg:grid-cols-2" :class="{ 'opacity-60': loading }">
            <div v-for="widget in widgets" :key="widget.type" class="min-w-0" :class="widget.size === 'full' ? 'lg:col-span-2' : ''">
                <StatsWidget v-if="widget.type === 'stats'" :stats="widget.data" />
                <TrendWidget
                    v-else-if="widget.type === 'cost_trend'"
                    title="Cost over time"
                    kind="cost"
                    :labels="widget.data.map((row) => row.date)"
                    :values="widget.data.map((row) => row.cost)"
                />
                <TrendWidget
                    v-else-if="widget.type === 'requests_trend'"
                    title="Requests over time"
                    kind="requests"
                    :labels="widget.data.map((row) => row.date)"
                    :values="widget.data.map((row) => row.count)"
                />
                <UsageByModelWidget v-else-if="widget.type === 'usage_by_model'" :rows="widget.data" :show-costs="showCosts" />
                <ProviderWidget v-else-if="widget.type === 'usage_by_provider'" :rows="widget.data" :show-costs="showCosts" />
                <RecentRequestsWidget v-else-if="widget.type === 'recent_requests'" :requests="widget.data" :show-costs="showCosts" />
                <BudgetWidget v-else-if="widget.type === 'budget'" :limits="widget.data" />
            </div>
        </div>
    </div>
</template>
