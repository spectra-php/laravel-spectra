<script setup lang="ts">
import { computed } from 'vue';
import Skeleton from '@/components/Skeleton.vue';
import BudgetWidget from './BudgetWidget.vue';
import StatsWidget from './StatsWidget.vue';
import BreakdownCard from '@/components/widgets/BreakdownCard.vue';
import PerformanceCard from '@/components/widgets/PerformanceCard.vue';
import RecentRequestsCard from '@/components/widgets/RecentRequestsCard.vue';
import ScoreSummaryCard from '@/components/widgets/ScoreSummaryCard.vue';
import TopModelsCard from '@/components/widgets/TopModelsCard.vue';
import TrendCard from '@/components/widgets/TrendCard.vue';
import type { DashboardResponse, DashboardWidgetData } from '@/types/api';

// A shared dashboard as its viewer sees it, drawn with the Overview's own
// widgets. The admin's preview shows this same component, so what an admin
// checks is what a viewer gets.
const props = defineProps<{
    dashboard: DashboardResponse | null;
    period: string;
    loading: boolean;
}>();

const emit = defineEmits<{ 'update:period': [period: string] }>();

const periods = [
    { value: 'today', label: 'Today' },
    { value: 'week', label: 'This Week' },
    { value: 'month', label: 'This Month' },
    { value: 'year', label: 'This Year' },
];

const widgets = computed(() => props.dashboard?.widgets ?? []);
const showCosts = computed(() => props.dashboard?.show_costs ?? false);
// Performance and Scores hide when empty, as they do on the Overview.
const hidesWhenEmpty = (widget: DashboardWidgetData) => (widget.type === 'performance' || widget.type === 'scores') && !widget.data.length;
const widgetsToShow = computed(() => widgets.value.filter((widget) => !hidesWhenEmpty(widget)));

const periodLabel = computed(() => periods.find((option) => option.value === props.period)?.label);
const subtitle = computed(() => [props.dashboard?.trackable_name, periodLabel.value].filter(Boolean).join(' · '));
</script>

<template>
    <div>
        <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div class="min-w-0">
                <Skeleton v-if="!dashboard" variant="text" width="12rem" height="2rem" />
                <template v-else>
                    <h1 class="truncate text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ dashboard.name }}</h1>
                    <p class="mt-1 truncate text-sm text-gray-500 dark:text-gray-400">{{ subtitle }}</p>
                </template>
            </div>
            <select
                class="form-select w-full sm:w-48"
                aria-label="Period"
                :value="period"
                @change="emit('update:period', ($event.target as HTMLSelectElement).value)"
            >
                <option v-for="option in periods" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
        </div>

        <div v-if="!dashboard" class="card"><Skeleton variant="chart" height="240px" /></div>

        <div v-else-if="!widgetsToShow.length" class="card">
            <div class="empty-state">
                <p class="empty-state-title">Nothing to show yet</p>
            </div>
        </div>

        <div v-else class="grid grid-cols-1 gap-4 transition-opacity lg:grid-cols-2" :class="{ 'opacity-60': loading }">
            <div v-for="widget in widgetsToShow" :key="widget.type" class="min-w-0" :class="widget.size === 'full' ? 'lg:col-span-2' : ''">
                <StatsWidget v-if="widget.type === 'stats'" :stats="widget.data" :period-label="periodLabel ?? ''" />
                <TrendCard
                    v-else-if="widget.type === 'cost_trend'"
                    title="Cost over time"
                    description="Daily spend"
                    kind="cost"
                    :labels="widget.data.map((row) => row.date)"
                    :values="widget.data.map((row) => row.cost)"
                />
                <TrendCard
                    v-else-if="widget.type === 'requests_trend'"
                    title="Requests over time"
                    description="Daily volume"
                    kind="requests"
                    :labels="widget.data.map((row) => row.date)"
                    :values="widget.data.map((row) => row.count)"
                />
                <TopModelsCard
                    v-else-if="widget.type === 'usage_by_model'"
                    :models="widget.data.map((row) => ({ provider: row.provider, providerName: row.provider_name, model: row.model, count: row.requests, cost: row.cost }))"
                    :show-costs="showCosts"
                />
                <BreakdownCard
                    v-else-if="widget.type === 'usage_by_provider'"
                    :providers="widget.data.providers.map((row) => ({ provider: row.provider, label: row.provider_name, count: row.requests }))"
                    :model-types="widget.data.model_types"
                />
                <RecentRequestsCard v-else-if="widget.type === 'recent_requests'" :requests="widget.data" />
                <BudgetWidget v-else-if="widget.type === 'budget'" :limits="widget.data" />
                <PerformanceCard v-else-if="widget.type === 'performance'" :models="widget.data" />
                <ScoreSummaryCard v-else-if="widget.type === 'scores'" :scores="widget.data" />
            </div>
        </div>
    </div>
</template>
