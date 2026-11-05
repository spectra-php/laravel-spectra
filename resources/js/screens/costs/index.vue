<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import { usePeriodQuery } from '@/composables/usePeriodQuery';
import { useProviders } from '@/composables/useProviders';
import { useCharts } from '@/composables/useCharts';
import { useLoading } from '@/composables/useLoading';
import Skeleton from '@/components/Skeleton.vue';
import PageRangeHeader from '@/components/PageRangeHeader.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import StatCard from '@/components/StatCard.vue';
import Money from '@/components/Money.vue';

const { formatNumber, formatCompact, formatCurrency, formatShortDate, providerChartColor, seriesColor, apiRequest } = useHelpers();
const { period, startDate, endDate, periodLabel, applyQueryState, buildQueryParams, syncQueryToRouter } = usePeriodQuery();
const { providers, loadProviders, providerDisplayName } = useProviders();
const { createLineChart, createDoughnutChart } = useCharts();
const loading = useLoading();

loading.value = true;
const stats = ref({
    total_cost_in_cents: 0,
    costs_by_provider: [],
    costs_by_model: [],
    costs_by_model_type: [],
    costs_by_date: [],
    costs_by_user: [],
});

const costChartRef = ref(null);
const providerChartRef = ref(null);
const modelTypeChartRef = ref(null);
const breakdownTab = ref('provider');
let costChart = null;
let providerChart = null;
let modelTypeChart = null;

const loadStats = async () => {
    loading.value = true;
    try {
        const data = await apiRequest('get', '/costs?' + buildQueryParams().toString());
        stats.value = data;
        // Draw once the chart containers are visible, so they get a real size.
        loading.value = false;
        await nextTick();
        renderCharts();
    } catch (error) {
        console.error('Failed to load costs:', error);
    } finally {
        loading.value = false;
    }
};

const renderCharts = () => {
    renderCostChart();
    renderProviderChart();
    renderModelTypeChart();
};

const renderCostChart = () => {
    if (costChart) costChart.destroy();
    if (!costChartRef.value) return;

    const data = stats.value.costs_by_date || [];

    costChart = createLineChart(costChartRef.value, {
        labels: data.map(d => formatShortDate(d.date)),
        values: data.map(d => Number(d.cost) / 100),
    }, {
        label: 'Cost',
        yTicks: {
            callback: (value) => formatCurrency(Number(value) * 100),
        },
        tooltipLabel: (dollars) => formatCurrency(dollars * 100),
        chartOptions: {
            aspectRatio: 2.6,
        },
    });
};

const renderProviderChart = () => {
    if (providerChart) providerChart.destroy();
    if (!providerChartRef.value) return;

    const data = stats.value.costs_by_provider || [];

    providerChart = createDoughnutChart(providerChartRef.value, {
        labels: data.map(d => providerDisplayName(d.provider)),
        values: data.map(d => Number(d.cost)),
        colors: () => data.map(d => providerChartColor(d.provider)),
    }, {
        legend: false,
        tooltipCallbacks: {
            label: (context) => {
                return context.label + ': ' + formatCurrency(context.raw);
            },
        },
    });
};

const renderModelTypeChart = () => {
    if (modelTypeChart) modelTypeChart.destroy();
    if (!modelTypeChartRef.value) return;

    const data = stats.value.costs_by_model_type || [];

    modelTypeChart = createDoughnutChart(modelTypeChartRef.value, {
        labels: data.map(d => d.label || d.model_type),
        values: data.map(d => Number(d.cost)),
        colors: () => data.map((d, index) => seriesColor(index)),
    }, {
        legend: false,
        tooltipCallbacks: {
            label: (context) => {
                return context.label + ': ' + formatCurrency(context.raw);
            },
        },
    });
};

const formatUsage = (model) => {
    const type = model.model_type || 'text';

    if (type === 'image') {
        const count = model.images || 0;
        return formatNumber(count) + ' ' + (count === 1 ? 'image' : 'images');
    }

    if (type === 'tts') {
        return formatNumber(model.input_characters || 0) + ' chars';
    }

    if (type === 'video') {
        const count = model.videos || 0;
        return formatNumber(count) + ' ' + (count === 1 ? 'video' : 'videos');
    }

    if (type === 'stt') {
        const duration = model.duration_seconds || 0;
        return duration.toFixed(1) + 's';
    }

    return formatNumber(model.tokens || 0) + ' tokens';
};

const onRangeChange = async () => {
    await syncQueryToRouter();
    await loadStats();
};

const totalCost = computed(() => Number(stats.value.total_cost_in_cents || 0));

// Legend rows for the breakdown doughnut, with amounts and shares.
const breakdownRows = computed(() => {
    const byProvider = breakdownTab.value === 'provider';
    const rows = byProvider ? (stats.value.costs_by_provider || []) : (stats.value.costs_by_model_type || []);
    const total = rows.reduce((sum, row) => sum + Number(row.cost || 0), 0) || 1;

    return rows.map((row, index) => ({
        key: byProvider ? row.provider : (row.model_type || row.label),
        label: byProvider ? providerDisplayName(row.provider) : (row.label || row.model_type),
        provider: byProvider ? row.provider : null,
        cost: Number(row.cost || 0),
        share: (Number(row.cost || 0) / total) * 100,
        color: byProvider ? providerChartColor(row.provider) : seriesColor(index),
    }));
});

const topProvider = computed(() => [...(stats.value.costs_by_provider || [])].sort((a, b) => Number(b.cost) - Number(a.cost))[0] || null);
const topModel = computed(() => [...(stats.value.costs_by_model || [])].sort((a, b) => Number(b.cost) - Number(a.cost))[0] || null);
const modelCostMax = computed(() => Math.max(1, ...(stats.value.costs_by_model || []).map((m) => Number(m.cost || 0))));
const dailyAverage = computed(() => {
    const days = (stats.value.costs_by_date || []).length;

    return days ? totalCost.value / days : 0;
});

const initials = (name) => String(name || 'U').split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase();

onMounted(async () => {
    applyQueryState();
    await syncQueryToRouter(null, true);
    await loadProviders();
    await loadStats();
});

onUnmounted(() => {
    if (costChart) costChart.destroy();
    if (providerChart) providerChart.destroy();
    if (modelTypeChart) modelTypeChart.destroy();
});
</script>

<template>
    <div>
        <PageRangeHeader
            title="Costs"
            :subtitle="`Where the money goes · ${periodLabel}`"
            v-model:period="period"
            v-model:start-date="startDate"
            v-model:end-date="endDate"
            :loading="loading"
            @change="onRangeChange"
        />

        <!-- Headline numbers -->
        <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Total spend" :loading="loading" skeleton-width="70%">
                <div class="stat-value"><Money :cents="totalCost" /></div>
                <template #footer>
                    <div class="mt-3 text-xs text-gray-500 dark:text-gray-400"><Money :cents="dailyAverage" /> per day on average</div>
                </template>
            </StatCard>
            <StatCard label="Top provider" :loading="loading">
                <div class="mt-1.5 flex items-center gap-2">
                    <ProviderLogo
                        v-if="topProvider"
                        :provider="topProvider.provider"
                        class="inline-flex h-5 w-5 items-center text-gray-700 dark:text-gray-200 [&>svg]:h-full [&>svg]:w-full"
                    />
                    <span class="truncate text-xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ topProvider ? providerDisplayName(topProvider.provider) : '—' }}</span>
                </div>
                <template #footer>
                    <div v-if="topProvider" class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        <Money :cents="topProvider.cost" /> · {{ totalCost ? ((Number(topProvider.cost) / totalCost) * 100).toFixed(0) : 0 }}% of spend
                    </div>
                </template>
            </StatCard>
            <StatCard label="Top model" :loading="loading">
                <div class="mt-1.5 truncate text-xl font-semibold tracking-tight text-gray-900 dark:text-white" v-tooltip.truncated="topModel?.model">{{ topModel?.model || '—' }}</div>
                <template #footer>
                    <div v-if="topModel" class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        <Money :cents="topModel.cost" /> across {{ formatNumber(topModel.requests) }} requests
                    </div>
                </template>
            </StatCard>
            <StatCard label="Models used" :loading="loading">
                <div class="stat-value">{{ formatNumber((stats.costs_by_model || []).length) }}</div>
                <template #footer>
                    <div class="mt-3 text-xs text-gray-500 dark:text-gray-400">From {{ formatNumber((stats.costs_by_provider || []).length) }} providers</div>
                </template>
            </StatCard>
        </div>

        <!-- Charts -->
        <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="card lg:col-span-2">
                <div class="card-header">
                    <div>
                        <h3>Spend over time</h3>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Daily cost</p>
                    </div>
                </div>
                <div class="card-body">
                    <template v-if="loading">
                        <Skeleton variant="chart" height="240px" />
                    </template>
                    <canvas v-show="!loading" ref="costChartRef"></canvas>
                </div>
            </div>

            <div class="card flex flex-col">
                <div class="card-header">
                    <div>
                        <h3>Breakdown</h3>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Share of spend</p>
                    </div>
                    <div class="segmented">
                        <button type="button" :class="{ 'is-active': breakdownTab === 'provider' }" @click="breakdownTab = 'provider'">Provider</button>
                        <button type="button" :class="{ 'is-active': breakdownTab === 'model-type' }" @click="breakdownTab = 'model-type'">Type</button>
                    </div>
                </div>
                <div class="card-body flex flex-1 flex-col gap-5">
                    <div v-if="loading" class="flex h-40 items-center justify-center">
                        <div class="h-32 w-32 animate-pulse rounded-full bg-gray-100 dark:bg-gray-800"></div>
                    </div>
                    <div v-show="!loading" class="mx-auto w-40">
                        <div v-show="breakdownTab === 'provider'"><canvas ref="providerChartRef"></canvas></div>
                        <div v-show="breakdownTab === 'model-type'"><canvas ref="modelTypeChartRef"></canvas></div>
                    </div>
                    <ul v-if="!loading" class="space-y-2 text-sm">
                        <li v-for="row in breakdownRows" :key="row.key" class="flex items-center gap-2.5">
                            <span class="h-2.5 w-2.5 shrink-0 rounded-sm" :style="{ backgroundColor: row.color }" aria-hidden="true"></span>
                            <ProviderLogo
                                v-if="row.provider"
                                :provider="row.provider"
                                class="inline-flex h-3.5 w-3.5 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full"
                                aria-hidden="true"
                            />
                            <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-300">{{ row.label }}</span>
                            <span class="tabular-nums text-gray-900 dark:text-white"><Money :cents="row.cost" /></span>
                            <span class="w-12 text-right tabular-nums text-xs text-gray-500 dark:text-gray-400">{{ row.share.toFixed(1) }}%</span>
                        </li>
                        <li v-if="!breakdownRows.length" class="py-6 text-center text-gray-500 dark:text-gray-400">No spend in this period</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Tables -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-5">
            <div class="card overflow-hidden lg:col-span-3">
                <div class="card-header">
                    <div>
                        <h3>Cost by model</h3>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Highest spend first</p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Model</th>
                                <th class="text-right">Requests</th>
                                <th class="text-right">Usage</th>
                                <th class="text-right">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-if="loading">
                                <Skeleton variant="table-row" :lines="5" />
                            </template>
                            <template v-else>
                                <tr v-for="model in stats.costs_by_model" :key="model.provider + model.model + ':' + (model.model_type || 'text')">
                                    <td class="max-w-0 w-1/2">
                                        <div class="flex min-w-0 items-center gap-2.5">
                                            <ProviderLogo
                                                :provider="model.provider"
                                                v-tooltip="providerDisplayName(model.provider)"
                                                class="inline-flex h-4 w-4 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full"
                                                aria-hidden="true"
                                            />
                                            <div class="min-w-0 flex-1">
                                                <div class="flex min-w-0 items-center gap-1.5">
                                                    <span class="truncate font-medium text-gray-900 dark:text-white" v-tooltip.truncated="model.model">{{ model.model }}</span>
                                                    <span v-if="model.model_type && model.model_type !== 'text'" class="badge badge-secondary shrink-0">{{ model.model_type }}</span>
                                                </div>
                                                <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                                    <div class="h-full rounded-full bg-primary-500/70" :style="{ width: (Number(model.cost) / modelCostMax) * 100 + '%' }"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-right tabular-nums">{{ formatNumber(model.requests) }}</td>
                                    <td class="whitespace-nowrap text-right tabular-nums text-gray-500 dark:text-gray-400">{{ formatUsage(model) }}</td>
                                    <td class="text-right tabular-nums font-medium text-gray-900 dark:text-white"><Money :cents="model.cost" /></td>
                                </tr>
                                <tr v-if="!stats.costs_by_model?.length">
                                    <td colspan="4">
                                        <div class="empty-state"><p class="empty-state-title">No spend in this period</p></div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card overflow-hidden lg:col-span-2">
                <div class="card-header">
                    <div>
                        <h3>Top users</h3>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">By spend</p>
                    </div>
                    <router-link :to="{ name: 'trackables' }" class="text-xs font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400">All trackables</router-link>
                </div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th class="text-right">Requests</th>
                                <th class="text-right">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-if="loading">
                                <Skeleton variant="table-row" :lines="5" />
                            </template>
                            <template v-else>
                                <tr v-for="user in stats.costs_by_user" :key="`${user.trackable_type}-${user.user_id}`">
                                    <td class="whitespace-nowrap">
                                        <div class="flex min-w-0 items-center gap-2.5">
                                            <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gray-100 text-[11px] font-semibold text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700">
                                                {{ initials(user.user_name) }}
                                            </div>
                                            <span class="truncate font-medium text-gray-900 dark:text-white">{{ user.user_name || 'User #' + user.user_id }}</span>
                                        </div>
                                    </td>
                                    <td class="text-right tabular-nums">{{ formatNumber(user.requests) }}</td>
                                    <td class="text-right tabular-nums font-medium text-gray-900 dark:text-white"><Money :cents="user.cost" /></td>
                                </tr>
                                <tr v-if="!stats.costs_by_user?.length">
                                    <td colspan="3">
                                        <div class="empty-state"><p class="empty-state-title">No user spend yet</p></div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
