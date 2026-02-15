<script setup>
import { ref, onMounted, onUnmounted, nextTick } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import { usePeriodQuery } from '@/composables/usePeriodQuery';
import { useProviders } from '@/composables/useProviders';
import { useCharts } from '@/composables/useCharts';
import { useLoading } from '@/composables/useLoading';
import Skeleton from '@/components/Skeleton.vue';
import PageRangeHeader from '@/components/PageRangeHeader.vue';

const { formatNumber, formatCurrency, currencySymbol, providerChartColor, apiRequest } = useHelpers();
const { period, startDate, endDate, applyQueryState, buildQueryParams, syncQueryToRouter } = usePeriodQuery();
const { providers, loadProviders, providerDisplayName, providerLogo } = useProviders();
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
        labels: data.map(d => d.date),
        values: data.map(d => d.cost / 100),
    }, {
        label: 'Cost',
        yTicks: {
            callback: (value) => currencySymbol + value.toFixed(2),
        },
    });
};

const renderProviderChart = () => {
    if (providerChart) providerChart.destroy();
    if (!providerChartRef.value) return;

    const data = stats.value.costs_by_provider || [];

    providerChart = createDoughnutChart(providerChartRef.value, {
        labels: data.map(d => d.provider),
        values: data.map(d => d.cost),
        colors: data.map(d => providerChartColor(d.provider)),
    }, {
        type: 'pie',
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
        values: data.map(d => d.cost),
    }, {
        type: 'pie',
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

// Watch for dark mode changes to re-render charts
const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
        if (mutation.attributeName === 'class') {
            renderCharts();
        }
    });
});

onMounted(async () => {
    applyQueryState();
    await syncQueryToRouter(null, true);
    await loadProviders();
    await loadStats();
    observer.observe(document.documentElement, { attributes: true });
});

onUnmounted(() => {
    observer.disconnect();
    if (costChart) costChart.destroy();
    if (providerChart) providerChart.destroy();
    if (modelTypeChart) modelTypeChart.destroy();
});
</script>

<template>
    <div class="">
        <!-- Header -->
        <PageRangeHeader
            title="Cost Analysis"
            v-model:period="period"
            v-model:start-date="startDate"
            v-model:end-date="endDate"
            :loading="loading"
            @change="onRangeChange"
        />

        <!-- Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <div class="lg:col-span-2 card">
                <div class="card-header">
                    <h3 class="text-sm font-medium">Cost Over Time</h3>
                </div>
                <div class="card-body">
                    <template v-if="loading">
                        <Skeleton variant="chart" height="200px" />
                    </template>
                    <canvas v-show="!loading" ref="costChartRef" height="100"></canvas>
                </div>
            </div>
            <div class="card">
                <div class="card-header flex-wrap gap-3">
                    <div>
                        <h3 class="text-sm font-semibold">Cost Breakdown</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Distribution view</p>
                    </div>
                    <div class="inline-flex items-center p-1 rounded-lg bg-gray-100 dark:bg-gray-700/60">
                        <button
                            @click="breakdownTab = 'provider'"
                            :class="[
                                'px-3 py-1.5 text-xs font-semibold rounded-md transition-colors',
                                breakdownTab === 'provider'
                                    ? 'bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm'
                                    : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200',
                            ]"
                        >
                            By Provider
                        </button>
                        <button
                            @click="breakdownTab = 'model-type'"
                            :class="[
                                'px-3 py-1.5 text-xs font-semibold rounded-md transition-colors',
                                breakdownTab === 'model-type'
                                    ? 'bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm'
                                    : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200',
                            ]"
                        >
                            By Model Type
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <template v-if="loading">
                        <div class="flex items-center justify-center" style="height: 200px">
                            <div class="w-32 h-32 rounded-full bg-gray-200 dark:bg-gray-700 animate-pulse"></div>
                        </div>
                    </template>
                    <div v-show="!loading && breakdownTab === 'provider'">
                        <canvas ref="providerChartRef" height="200"></canvas>
                    </div>
                    <div v-show="!loading && breakdownTab === 'model-type'">
                        <canvas ref="modelTypeChartRef" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tables -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Cost by Model -->
            <div class="card">
                <div class="card-header">
                    <h3 class="text-sm font-medium">Cost by Model</h3>
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
                                <tr v-for="i in 5" :key="i" class="border-b border-gray-100 dark:border-gray-700/50">
                                    <td class="px-5 py-4"><div class="h-4 w-28 bg-gray-200 dark:bg-gray-700 rounded animate-pulse"></div></td>
                                    <td class="px-5 py-4"><div class="h-4 w-12 bg-gray-200 dark:bg-gray-700 rounded animate-pulse ml-auto"></div></td>
                                    <td class="px-5 py-4"><div class="h-4 w-16 bg-gray-200 dark:bg-gray-700 rounded animate-pulse ml-auto"></div></td>
                                    <td class="px-5 py-4"><div class="h-4 w-14 bg-gray-200 dark:bg-gray-700 rounded animate-pulse ml-auto"></div></td>
                                </tr>
                            </template>
                            <template v-else>
                                <tr v-for="model in stats.costs_by_model" :key="model.model + ':' + (model.model_type || 'text')">
                                    <td>
                                        <div class="flex items-center gap-2 min-w-0">
                                            <span
                                                v-if="providerLogo(model.provider)"
                                                v-html="providerLogo(model.provider)"
                                                :title="providerDisplayName(model.provider)"
                                                class="inline-flex items-center shrink-0 w-4 h-4 [&>svg]:w-full [&>svg]:h-full text-gray-500 dark:text-gray-400"
                                                aria-hidden="true"
                                            ></span>
                                            <span class="font-medium text-gray-900 dark:text-white truncate" :title="model.model">{{ model.model }}</span>
                                            <span
                                                v-if="model.model_type"
                                                class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium uppercase bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400 shrink-0"
                                            >{{ model.model_type }}</span>
                                        </div>
                                    </td>
                                    <td class="text-right tabular-nums">{{ formatNumber(model.requests) }}</td>
                                    <td class="text-right tabular-nums">{{ formatUsage(model) }}</td>
                                    <td class="text-right tabular-nums font-medium text-gray-900 dark:text-white">{{ formatCurrency(model.cost) }}</td>
                                </tr>
                                <tr v-if="!stats.costs_by_model?.length">
                                    <td colspan="4">
                                        <div class="empty-state py-12">
                                            <svg class="empty-state-icon mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <p class="empty-state-title">No data available</p>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Cost by User -->
            <div class="card">
                <div class="card-header">
                    <h3 class="text-sm font-medium">Top Users by Cost</h3>
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
                                <tr v-for="i in 5" :key="i" class="border-b border-gray-100 dark:border-gray-700/50">
                                    <td class="px-5 py-4"><div class="h-4 w-24 bg-gray-200 dark:bg-gray-700 rounded animate-pulse"></div></td>
                                    <td class="px-5 py-4"><div class="h-4 w-12 bg-gray-200 dark:bg-gray-700 rounded animate-pulse ml-auto"></div></td>
                                    <td class="px-5 py-4"><div class="h-4 w-14 bg-gray-200 dark:bg-gray-700 rounded animate-pulse ml-auto"></div></td>
                                </tr>
                            </template>
                            <template v-else>
                                <tr v-for="user in stats.costs_by_user" :key="user.user_id">
                                    <td class="font-medium text-gray-900 dark:text-white">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-primary-500 to-purple-500 flex items-center justify-center text-white text-xs font-bold">
                                                {{ (user.user_name || 'U')[0].toUpperCase() }}
                                            </div>
                                            <span>{{ user.user_name || 'User #' + user.user_id }}</span>
                                        </div>
                                    </td>
                                    <td class="text-right tabular-nums">{{ formatNumber(user.requests) }}</td>
                                    <td class="text-right tabular-nums font-medium text-gray-900 dark:text-white">{{ formatCurrency(user.cost) }}</td>
                                </tr>
                                <tr v-if="!stats.costs_by_user?.length">
                                    <td colspan="3">
                                        <div class="empty-state py-12">
                                            <svg class="empty-state-icon mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                            </svg>
                                            <p class="empty-state-title">No user data available</p>
                                        </div>
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
