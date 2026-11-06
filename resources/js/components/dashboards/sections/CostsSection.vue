<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import { useViewer } from '@/composables/useViewer';
import DashboardHeading, { dashboardPeriods } from '../DashboardHeading.vue';
import SectionFailed from '../SectionFailed.vue';
import Money from '@/components/Money.vue';
import Skeleton from '@/components/Skeleton.vue';
import StatCard from '@/components/StatCard.vue';
import BreakdownCard from '@/components/widgets/BreakdownCard.vue';
import TopModelsCard from '@/components/widgets/TopModelsCard.vue';
import TrendCard from '@/components/widgets/TrendCard.vue';
import type { DashboardCosts } from '@/types/api';

// The viewer's spend, in the Overview's own cards.
const { config, period, fetchData } = useViewer();
const { formatNumber, formatCurrency } = useHelpers();

const costs = ref<DashboardCosts | null>(null);
const failed = ref(false);

const subtitle = computed(() => [config.trackableName, dashboardPeriods.find((option) => option.value === period.value)?.label].filter(Boolean).join(' · '));

const load = async () => {
    failed.value = false;

    try {
        costs.value = await fetchData<DashboardCosts>('/costs', { period: period.value });
    } catch (error) {
        failed.value = true;
    }
};

onMounted(load);
watch(period, load);
</script>

<template>
    <div>
        <DashboardHeading title="Costs" :subtitle="subtitle" :period="period" @update:period="period = $event" />
        <SectionFailed v-if="failed" @retry="load" />
        <div v-else-if="!costs" class="card"><Skeleton variant="chart" height="240px" /></div>
        <template v-else>
            <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Total spend">
                    <div class="stat-value"><Money :cents="costs.total_cost" /></div>
                    <template #footer>
                        <div class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                            <template v-if="costs.total_requests"><Money :cents="costs.total_cost / costs.total_requests" /> per request on average</template>
                            <template v-else>No spend in this period</template>
                        </div>
                    </template>
                </StatCard>
                <StatCard label="Top provider">
                    <div class="stat-value truncate">{{ costs.by_provider[0]?.provider_name ?? '—' }}</div>
                    <template #footer><div v-if="costs.by_provider[0]" class="mt-3 text-xs text-gray-500 dark:text-gray-400"><Money :cents="costs.by_provider[0].cost" /></div></template>
                </StatCard>
                <StatCard label="Top model">
                    <div class="stat-value truncate" v-tooltip.truncated="costs.by_model[0]?.model ?? ''">{{ costs.by_model[0]?.model ?? '—' }}</div>
                    <template #footer><div v-if="costs.by_model[0]" class="mt-3 text-xs text-gray-500 dark:text-gray-400"><Money :cents="costs.by_model[0].cost" /></div></template>
                </StatCard>
                <StatCard label="Requests">
                    <div class="stat-value">{{ formatNumber(costs.total_requests) }}</div>
                    <template #footer><div class="mt-3 text-xs text-gray-500 dark:text-gray-400">Successful and failed</div></template>
                </StatCard>
            </div>
            <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <TrendCard
                    class="lg:col-span-2"
                    title="Spend over time"
                    description="Daily spend"
                    kind="cost"
                    :labels="costs.by_date.map((row) => row.date)"
                    :values="costs.by_date.map((row) => row.cost)"
                />
                <BreakdownCard
                    description="Share of spend"
                    :format-value="formatCurrency"
                    :providers="costs.by_provider.map((row) => ({ provider: row.provider, label: row.provider_name, count: row.cost }))"
                    :model-types="costs.by_model_type.map((row) => ({ model_type: row.model_type, label: row.label, count: row.cost }))"
                />
            </div>
            <TopModelsCard
                title="Cost by model"
                description="The biggest ten"
                :models="costs.by_model.map((row) => ({ provider: row.provider, providerName: row.provider_name, model: row.model, count: row.requests, cost: row.cost }))"
            />
        </template>
    </div>
</template>
