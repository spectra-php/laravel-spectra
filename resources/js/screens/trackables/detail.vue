<script setup>
import { ref, onMounted, onUnmounted, nextTick, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { usePeriodQuery } from '@/composables/usePeriodQuery';
import { useProviders } from '@/composables/useProviders';
import { useCharts } from '@/composables/useCharts';
import { useLoading } from '@/composables/useLoading';
import Skeleton from '@/components/Skeleton.vue';
import StatCard from '@/components/StatCard.vue';
import StatusCode from '@/components/StatusCode.vue';
import Breadcrumb from '@/components/Breadcrumb.vue';
import PageRangeHeader from '@/components/PageRangeHeader.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import Money from '@/components/Money.vue';

const route = useRoute();
const router = useRouter();
const { formatNumber, formatCompact, formatShortDate, formatCurrency, formatDuration, formatDurationSeconds, truncate, providerChartColor, apiRequest } = useHelpers();
// `type` tells the API which model the id belongs to, so it stays in the URL with the period.
const { period, startDate, endDate, periodLabel, applyQueryState, syncQueryToRouter } = usePeriodQuery({
    filterKeys: ['type'],
    getFilters: () => ({ type: route.query.type }),
});
const { providers, loadProviders, providerDisplayName } = useProviders();
const { createBarChart, createLineChart, createDoughnutChart } = useCharts();
const loading = useLoading();

const trackable = ref(null);
const requests = ref([]);
loading.value = true;

const costChartRef = ref(null);
const requestsChartRef = ref(null);
const providerChartRef = ref(null);
let costChart = null;
let requestsChart = null;
let providerChart = null;

const trendTab = ref('cost');
const requestsByDate = ref(null);
const loadingRequestsByDate = ref(false);

const showLatencyBreakdownModal = ref(false);
const showAllModelsModal = ref(false);
const modelsSearch = ref('');
const modelsPage = ref(1);
const modelsPerPage = 20;

const latencyByModelType = computed(() => {
    const rows = trackable.value?.latency_by_model_type || [];
    return [...rows]
        .filter((row) => Number(row?.count) > 0)
        .sort((a, b) => b.count - a.count);
});

const allModels = computed(() => trackable.value?.usage_by_model || []);

const filteredModels = computed(() => {
    const q = modelsSearch.value.toLowerCase().trim();
    if (!q) return allModels.value;
    return allModels.value.filter(m =>
        m.model?.toLowerCase().includes(q) || m.provider?.toLowerCase().includes(q)
    );
});

const pagedModels = computed(() => {
    const start = (modelsPage.value - 1) * modelsPerPage;
    return filteredModels.value.slice(start, start + modelsPerPage);
});

const modelsTotalPages = computed(() => Math.max(1, Math.ceil(filteredModels.value.length / modelsPerPage)));

const openAllModels = () => {
    modelsSearch.value = '';
    modelsPage.value = 1;
    showAllModelsModal.value = true;
};

const closeAllModels = () => {
    showAllModelsModal.value = false;
};

const viewAllRequests = () => {
    if (!trackable.value) return;
    router.push({
        name: 'requests',
        query: {
            trackable_type: trackable.value.type,
            trackable_id: trackable.value.id,
        },
    });
};

const openLatencyBreakdown = () => {
    if (!latencyByModelType.value.length) return;
    showLatencyBreakdownModal.value = true;
};

const closeLatencyBreakdown = () => {
    showLatencyBreakdownModal.value = false;
};

const buildQueryParams = () => {
    const params = new URLSearchParams();
    const hasCustomRange = Boolean(startDate.value && endDate.value);
    params.set('period', hasCustomRange ? 'custom' : period.value);

    if (hasCustomRange) {
        params.set('start_date', startDate.value);
        params.set('end_date', endDate.value);
    }

    const type = route.query.type;
    if (type) params.set('type', type);

    return params;
};

const loadTrackable = async () => {
    loading.value = true;
    requestsByDate.value = null;
    trendTab.value = 'cost';
    try {
        const id = route.params.id;
        const data = await apiRequest('get', `/trackables/view/${id}?${buildQueryParams().toString()}`);
        trackable.value = data.trackable;
        requests.value = data.requests || [];
        loading.value = false;
        await nextTick();
        renderCharts();
    } catch (error) {
        console.error('Failed to load trackable:', error);
        loading.value = false;
    }
};

const onRangeChange = async () => {
    await syncQueryToRouter();
    await loadTrackable();
};

const renderCharts = () => {
    renderCostChart();
    renderProviderChart();
};

const renderCostChart = () => {
    if (costChart) costChart.destroy();
    if (!costChartRef.value || !trackable.value?.costs_by_date?.length) return;

    const data = trackable.value.costs_by_date;
    costChart = createBarChart(costChartRef.value, {
        labels: data.map(d => formatShortDate(d.date)),
        values: data.map(d => d.cost / 100),
    }, {
        label: 'Cost',
        yTicks: {
            callback: (value) => formatCurrency(Number(value) * 100),
        },
        tooltipLabel: (dollars) => formatCurrency(dollars * 100),
    });
};

const renderRequestsChart = () => {
    if (requestsChart) requestsChart.destroy();
    if (!requestsChartRef.value || !requestsByDate.value?.length) return;

    const data = requestsByDate.value;
    requestsChart = createLineChart(requestsChartRef.value, {
        labels: data.map(d => formatShortDate(d.date)),
        values: data.map(d => d.count),
    }, {
        label: 'Requests',
    });
};

const renderProviderChart = () => {
    if (providerChart) providerChart.destroy();
    if (!providerChartRef.value || !trackable.value?.usage_by_provider?.length) return;

    const data = trackable.value.usage_by_provider;
    providerChart = createDoughnutChart(providerChartRef.value, {
        labels: data.map(d => providerDisplayName(d.provider)),
        values: data.map(d => d.requests),
        colors: () => data.map(d => providerChartColor(d.provider)),
    }, {
        legend: false,
        chartOptions: {
            animation: false,
        },
    });
};

// Legend rows for the provider doughnut, with counts and shares.
const providerRows = computed(() => {
    const rows = trackable.value?.usage_by_provider || [];
    const total = rows.reduce((sum, row) => sum + Number(row.requests || 0), 0) || 1;

    return rows.map((row) => ({
        provider: row.provider,
        label: providerDisplayName(row.provider),
        count: Number(row.requests || 0),
        share: (Number(row.requests || 0) / total) * 100,
        color: providerChartColor(row.provider),
    }));
});

const switchTrendTab = async (tab) => {
    trendTab.value = tab;

    if (tab === 'requests' && !requestsByDate.value) {
        loadingRequestsByDate.value = true;
        try {
            const id = route.params.id;
            const data = await apiRequest('get', `/trackables/view/${id}/requests-by-date?${buildQueryParams().toString()}`);
            requestsByDate.value = data;
            await nextTick();
            renderRequestsChart();
        } catch (error) {
            console.error('Failed to load requests by date:', error);
        } finally {
            loadingRequestsByDate.value = false;
        }
    } else if (tab === 'requests') {
        await nextTick();
        renderRequestsChart();
    } else {
        await nextTick();
        renderCostChart();
    }
};

const goToRequest = (id) => {
    router.push({ name: 'request-preview', params: { id } });
};

const layout = ref('full');
const showTokens = computed(() => ['full', 'text', 'embedding'].includes(layout.value));
const showImages = computed(() => ['full', 'image'].includes(layout.value));
const showVideos = computed(() => ['full', 'video'].includes(layout.value));
const showTts = computed(() => ['full', 'audio'].includes(layout.value));
const showStt = computed(() => ['full', 'audio'].includes(layout.value));

// Media usage shown only when the period has that kind of traffic.
const mediaCards = computed(() => {
    const t = trackable.value || {};

    return [
        { key: 'images', show: showImages.value && t.total_images > 0, label: 'Images', value: formatNumber(t.total_images), unit: t.total_images === 1 ? 'image' : 'images' },
        { key: 'videos', show: showVideos.value && t.total_videos > 0, label: 'Videos', value: formatNumber(t.total_videos), unit: t.total_videos === 1 ? 'video' : 'videos' },
        { key: 'tts', show: showTts.value && t.total_tts_characters > 0, label: 'Text-to-speech', value: formatCompact(t.total_tts_characters), unit: 'characters' },
        { key: 'stt', show: showStt.value && t.stt_duration_seconds > 0, label: 'Speech-to-text', value: formatDurationSeconds(t.stt_duration_seconds), unit: 'audio' },
    ].filter((card) => card.show);
});

const loadConfig = async () => {
    try {
        const data = await apiRequest('get', '/config');
        layout.value = data.layout || 'full';
    } catch (error) {
        // fallback to full
    }
};

const formatUsage = (request) => {
    const type = request.model_type;
    if (type === 'image') {
        const count = request.image_count || 0;
        return count + (count === 1 ? ' image' : ' images');
    }
    if (type === 'tts') {
        return formatNumber(request.input_characters || 0) + ' chars';
    }
    if (type === 'video') {
        const count = request.video_count || 0;
        return count + (count === 1 ? ' video' : ' videos');
    }
    if (type === 'stt') {
        const dur = request.duration_seconds || 0;
        return dur.toFixed(1) + 's';
    }
    return formatNumber((request.prompt_tokens || 0) + (request.completion_tokens || 0));
};

const getDisplayName = () => {
    if (!trackable.value) return '';
    if (trackable.value.name) return trackable.value.name;
    if (trackable.value.email) return trackable.value.email;

    const parts = trackable.value.type.split('\\');
    const className = parts[parts.length - 1];
    return `${className} #${trackable.value.id}`;
};

const getTypeName = () => {
    if (!trackable.value) return '';
    const parts = trackable.value.type.split('\\');
    return parts[parts.length - 1];
};

const headerTitle = computed(() => {
    if (!trackable.value) return 'Trackable';
    return `${getDisplayName()} / ${getTypeName()}`;
});

const breadcrumbs = computed(() => [
    { label: 'Trackables', to: { name: 'trackables' } },
    { label: trackable.value ? getDisplayName() : `Trackable #${route.params.id}` },
]);

// Watch for dark mode changes
const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
        if (mutation.attributeName === 'class') {
            renderCharts();
            if (trendTab.value === 'requests' && requestsByDate.value) {
                renderRequestsChart();
            }
        }
    });
});

onMounted(async () => {
    applyQueryState();
    await syncQueryToRouter(null, true);
    await Promise.all([loadConfig(), loadProviders()]);
    await loadTrackable();
    observer.observe(document.documentElement, { attributes: true });
});

onUnmounted(() => {
    observer.disconnect();
    if (costChart) costChart.destroy();
    if (requestsChart) requestsChart.destroy();
    if (providerChart) providerChart.destroy();
});
</script>

<template>
    <div class="">
        <!-- Breadcrumb -->
        <Breadcrumb :items="breadcrumbs" />

        <!-- Header -->
        <PageRangeHeader
            :title="headerTitle"
            v-model:period="period"
            v-model:start-date="startDate"
            v-model:end-date="endDate"
            :loading="loading"
            @change="onRangeChange"
        />

        <!-- Headline numbers -->
        <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Requests" :loading="loading">
                <div class="stat-value">{{ formatNumber(trackable?.total_requests ?? 0) }}</div>
            </StatCard>
            <StatCard label="Spend" :loading="loading" skeleton-width="70%">
                <div class="stat-value"><Money :cents="trackable?.total_cost" /></div>
                <template #footer>
                    <div v-if="trackable?.total_requests" class="mt-3 text-xs text-gray-500 dark:text-gray-400"><Money :cents="(trackable.total_cost || 0) / trackable.total_requests" /> per request on average</div>
                </template>
            </StatCard>
            <StatCard v-if="showTokens" label="Tokens" :loading="loading" skeleton-width="50%">
                <div class="stat-value" v-tooltip="formatNumber(trackable?.total_tokens || 0) + ' tokens'">{{ formatCompact(trackable?.total_tokens || 0) }}</div>
            </StatCard>
            <StatCard label="Average latency" :loading="loading" skeleton-width="40%">
                <template #action>
                    <button
                        v-if="latencyByModelType.length"
                        type="button"
                        class="text-xs font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400"
                        @click="openLatencyBreakdown"
                    >
                        By type
                    </button>
                </template>
                <div class="stat-value">{{ trackable ? formatDuration(trackable.avg_latency) : '0ms' }}</div>
            </StatCard>
        </div>

        <!-- Media usage (only kinds that appear in this period) -->
        <div
            v-if="!loading && mediaCards.length"
            class="card mb-4 grid grid-cols-1 divide-y divide-gray-100 sm:divide-x sm:divide-y-0 dark:divide-gray-800"
            :class="{ 1: 'sm:grid-cols-1', 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-3', 4: 'sm:grid-cols-4' }[mediaCards.length]"
        >
            <div v-for="card in mediaCards" :key="card.key" class="px-5 py-3.5">
                <div class="stat-label">{{ card.label }}</div>
                <div class="mt-0.5 flex items-baseline gap-1.5">
                    <span class="text-lg font-semibold tracking-tight text-gray-900 tabular-nums dark:text-white">{{ card.value }}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ card.unit }}</span>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2 card">
                <div class="card-header flex-wrap gap-3">
                    <div>
                        <h3 class="text-sm font-semibold">{{ trendTab === 'cost' ? 'Cost Over Time' : 'Requests Over Time' }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ trendTab === 'cost' ? 'Daily cost trend' : 'Daily request volume' }}</p>
                    </div>
                    <div class="inline-flex items-center p-1 rounded-lg bg-gray-100 dark:bg-gray-800/80">
                        <button
                            @click="switchTrendTab('cost')"
                            :class="[
                                'px-3 py-1.5 text-xs font-semibold rounded-md transition-colors',
                                trendTab === 'cost'
                                    ? 'bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm'
                                    : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200',
                            ]"
                        >
                            Cost
                        </button>
                        <button
                            @click="switchTrendTab('requests')"
                            :class="[
                                'px-3 py-1.5 text-xs font-semibold rounded-md transition-colors',
                                trendTab === 'requests'
                                    ? 'bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm'
                                    : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200',
                            ]"
                        >
                            Requests
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <template v-if="loading || loadingRequestsByDate">
                        <Skeleton variant="chart" height="200px" />
                    </template>
                    <!-- Cost chart -->
                    <div v-show="!loading && !loadingRequestsByDate && trendTab === 'cost' && !trackable?.costs_by_date?.length" class="empty-state py-12">
                        <p class="empty-state-description">No cost data available</p>
                    </div>
                    <canvas v-show="!loading && !loadingRequestsByDate && trendTab === 'cost' && trackable?.costs_by_date?.length" ref="costChartRef" height="100"></canvas>
                    <!-- Requests chart -->
                    <div v-show="!loading && !loadingRequestsByDate && trendTab === 'requests' && !requestsByDate?.length" class="empty-state py-12">
                        <p class="empty-state-description">No request data available</p>
                    </div>
                    <canvas v-show="!loading && !loadingRequestsByDate && trendTab === 'requests' && requestsByDate?.length" ref="requestsChartRef" height="100"></canvas>
                </div>
            </div>
            <div class="card flex flex-col">
                <div class="card-header">
                    <div>
                        <h3>Breakdown</h3>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Requests by provider</p>
                    </div>
                </div>
                <div class="card-body flex flex-1 flex-col gap-5">
                    <div v-if="loading" class="flex h-40 items-center justify-center">
                        <div class="h-32 w-32 animate-pulse rounded-full bg-gray-100 dark:bg-gray-800"></div>
                    </div>
                    <div v-show="!loading && !trackable?.usage_by_provider?.length" class="empty-state py-12">
                        <p class="empty-state-description">No provider data in this period</p>
                    </div>
                    <div v-show="!loading && trackable?.usage_by_provider?.length" class="mx-auto w-40">
                        <canvas ref="providerChartRef"></canvas>
                    </div>
                    <ul v-if="!loading" class="space-y-2 text-sm">
                        <li v-for="row in providerRows" :key="row.provider" class="flex items-center gap-2.5">
                            <span class="h-2.5 w-2.5 shrink-0 rounded-sm" :style="{ backgroundColor: row.color }" aria-hidden="true"></span>
                            <ProviderLogo
                                :provider="row.provider"
                                class="inline-flex h-3.5 w-3.5 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full"
                                aria-hidden="true"
                            />
                            <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-300">{{ row.label }}</span>
                            <span class="tabular-nums text-gray-900 dark:text-white">{{ formatNumber(row.count) }}</span>
                            <span class="w-12 text-right tabular-nums text-xs text-gray-500 dark:text-gray-400">{{ row.share.toFixed(1) }}%</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Tables -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <!-- Top Models -->
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="text-sm font-semibold">Top Models</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Highest request volume</p>
                    </div>
                    <button
                        v-if="allModels.length > 10"
                        @click="openAllModels"
                        class="text-xs font-medium text-primary-600 dark:text-primary-400 hover:underline"
                    >
                        View All ({{ allModels.length }})
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Model</th>
                                <th class="text-right">Requests</th>
                                <th class="text-right">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-if="loading">
                                <Skeleton variant="table-row" :lines="5" />
                            </template>
                            <template v-else>
                                <tr v-for="model in allModels.slice(0, 10)" :key="model.model">
                                    <td>
                                        <div class="flex items-center gap-2 min-w-0">
                                            <ProviderLogo
                                                :provider="model.provider"
                                                v-tooltip="providerDisplayName(model.provider)"
                                                class="inline-flex items-center shrink-0 w-4 h-4 [&>svg]:w-full [&>svg]:h-full text-gray-500 dark:text-gray-400"
                                                aria-hidden="true"
                                            />
                                            <span class="max-w-[14rem] truncate font-medium text-gray-900 dark:text-white" v-tooltip.truncated="model.model">
                                                {{ model.model }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="text-right tabular-nums font-medium">{{ formatNumber(model.requests) }}</td>
                                    <td class="text-right tabular-nums font-medium text-gray-900 dark:text-white"><Money :cents="model.cost" /></td>
                                </tr>
                                <tr v-if="!allModels.length">
                                    <td colspan="3" class="text-center py-8 text-gray-500">
                                        <div class="empty-state">
                                            <svg class="empty-state-icon mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
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

            <!-- Recent Requests -->
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="text-sm font-semibold">Recent Requests</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Latest activity feed</p>
                    </div>
                    <button
                        v-if="requests.length > 0"
                        @click="viewAllRequests"
                        class="text-xs font-medium text-primary-600 dark:text-primary-400 hover:underline"
                    >
                        View All
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="table table-fixed">
                        <colgroup>
                            <col class="w-[48%]" />
                            <col class="w-[18%]" />
                            <col class="w-[16%]" />
                            <col class="w-[18%]" />
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Model</th>
                                <th class="text-right">Usage</th>
                                <th>Status</th>
                                <th class="text-right">Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-if="loading">
                                <Skeleton variant="table-row" :lines="5" />
                            </template>
                            <template v-else>
                                <tr
                                    v-for="request in requests.slice(0, 10)"
                                    :key="request.id"
                                    @click="goToRequest(request.id)"
                                    class="cursor-pointer group"
                                >
                                    <td class="max-w-0">
                                        <div class="flex items-center gap-2 min-w-0">
                                            <ProviderLogo
                                                :provider="request.provider"
                                                v-tooltip="providerDisplayName(request.provider)"
                                                class="inline-flex items-center shrink-0 w-4 h-4 [&>svg]:w-full [&>svg]:h-full text-gray-500 dark:text-gray-400"
                                                aria-hidden="true"
                                            />
                                            <span
                                                class="truncate font-medium text-gray-900 dark:text-white"
                                                v-tooltip.truncated="request.model || ''"
                                            >
                                                {{ truncate(request.model, 32) }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="text-right tabular-nums text-sm">{{ formatUsage(request) }}</td>
                                    <td>
                                        <StatusCode :code="request.status_code" />
                                    </td>
                                    <td class="text-right whitespace-nowrap">
                                        <span class="group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors">
                                            {{ request.created_at_human }}
                                        </span>
                                    </td>
                                </tr>
                                <tr v-if="!requests.length">
                                    <td colspan="4" class="text-center py-8 text-gray-500">
                                        <div class="empty-state">
                                            <svg class="empty-state-icon mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                            </svg>
                                            <p class="empty-state-title">No requests yet</p>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Latency Breakdown Modal -->
        <div
            v-if="showLatencyBreakdownModal"
            class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
            @click="closeLatencyBreakdown"
        >
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-2xl max-h-[85vh] overflow-hidden" @click.stop>
                <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-800 flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Average Latency by Model Type</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ periodLabel }}</p>
                    </div>
                    <button
                        type="button"
                        class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors"
                        @click="closeLatencyBreakdown"
                    >
                        <span class="sr-only">Close</span>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="overflow-auto max-h-[65vh]">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Model Type</th>
                                <th class="text-right">Avg Latency</th>
                                <th class="text-right">Requests</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in latencyByModelType" :key="row.model_type">
                                <td>
                                    <div class="font-medium text-gray-900 dark:text-white">{{ row.label }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ row.model_type }}</div>
                                </td>
                                <td class="text-right tabular-nums">{{ formatDuration(row.avg_latency) }}</td>
                                <td class="text-right tabular-nums">{{ formatNumber(row.count) }}</td>
                            </tr>
                            <tr v-if="!latencyByModelType.length">
                                <td colspan="3" class="text-center py-8 text-gray-500 dark:text-gray-400">No latency data available.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <!-- All Models Modal -->
        <div
            v-if="showAllModelsModal"
            class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
            @click="closeAllModels"
        >
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-2xl max-h-[85vh] overflow-hidden" @click.stop>
                <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-800">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">All Models</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ filteredModels.length }} models</p>
                        </div>
                        <button
                            type="button"
                            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors"
                            @click="closeAllModels"
                        >
                            <span class="sr-only">Close</span>
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="mt-3">
                        <div class="relative">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <input
                                v-model="modelsSearch"
                                type="text"
                                placeholder="Search models..."
                                class="form-input pl-9 text-sm"
                                @input="modelsPage = 1"
                            />
                        </div>
                    </div>
                </div>

                <div class="overflow-auto max-h-[55vh]">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Model</th>
                                <th class="text-right">Requests</th>
                                <th class="text-right">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="model in pagedModels" :key="model.model">
                                <td>
                                    <div class="flex items-center gap-2 min-w-0">
                                        <ProviderLogo
                                            :provider="model.provider"
                                            v-tooltip="providerDisplayName(model.provider)"
                                            class="inline-flex items-center shrink-0 w-4 h-4 [&>svg]:w-full [&>svg]:h-full text-gray-500 dark:text-gray-400"
                                            aria-hidden="true"
                                        />
                                        <span class="max-w-[14rem] truncate font-medium text-gray-900 dark:text-white" v-tooltip.truncated="model.model">
                                            {{ model.model }}
                                        </span>
                                    </div>
                                </td>
                                <td class="text-right tabular-nums font-medium">{{ formatNumber(model.requests) }}</td>
                                <td class="text-right tabular-nums font-medium text-gray-900 dark:text-white"><Money :cents="model.cost" /></td>
                            </tr>
                            <tr v-if="!filteredModels.length">
                                <td colspan="3" class="text-center py-8 text-gray-500 dark:text-gray-400">
                                    No models match your search.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="modelsTotalPages > 1" class="px-5 py-3 border-t border-gray-200 dark:border-gray-800 flex items-center justify-between">
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        Page {{ modelsPage }} of {{ modelsTotalPages }}
                    </span>
                    <div class="flex gap-2">
                        <button
                            @click="modelsPage = Math.max(1, modelsPage - 1)"
                            :disabled="modelsPage <= 1"
                            class="btn btn-secondary btn-sm"
                        >Previous</button>
                        <button
                            @click="modelsPage = Math.min(modelsTotalPages, modelsPage + 1)"
                            :disabled="modelsPage >= modelsTotalPages"
                            class="btn btn-secondary btn-sm"
                        >Next</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
