<script setup>
import { ref, onMounted, onUnmounted, nextTick, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { usePeriodQuery } from '@/composables/usePeriodQuery';
import { useProviders } from '@/composables/useProviders';
import { useCharts } from '@/composables/useCharts';
import { useLoading } from '@/composables/useLoading';
import Skeleton from '@/components/Skeleton.vue';
import StatCard from '@/components/StatCard.vue';
import StatusCode from '@/components/StatusCode.vue';
import PageRangeHeader from '@/components/PageRangeHeader.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import Sparkline from '@/components/Sparkline.vue';
import Money from '@/components/Money.vue';

const router = useRouter();
const { formatNumber, formatCompact, formatShortDate, formatDuration, formatDurationSeconds, truncate, providerChartColor, seriesColor, apiRequest } = useHelpers();
const { period, startDate, endDate, periodLabel, applyQueryState, buildQueryParams, syncQueryToRouter } = usePeriodQuery();
const { providers, loadProviders, providerDisplayName } = useProviders();
const { createLineChart, createDoughnutChart } = useCharts();
const loading = useLoading();

loading.value = true;
const layout = ref('full');
const stats = ref({
    total_requests: 0,
    total_cost_in_cents: 0,
    total_tokens: 0,
    total_images: 0,
    total_videos: 0,
    total_duration_seconds: 0,
    total_input_characters: 0,
    tts_characters: 0,
    tts_duration_seconds: 0,
    stt_duration_seconds: 0,
    avg_latency: 0,
    cost_by_model_type: {
        text: 0,
        embedding: 0,
        image: 0,
        video: 0,
        tts: 0,
        stt: 0,
        unknown: 0,
    },
    top_models: [],
    recent_requests: [],
    requests_by_date: [],
    requests_by_provider: [],
    stats_by_model_type: [],
    latency_by_model_type: [],
    layout: 'full',
});

const requestsChartRef = ref(null);
const providerChartRef = ref(null);
const modelTypeChartRef = ref(null);
const breakdownTab = ref('provider');
const showLatencyBreakdownModal = ref(false);
let requestsChart = null;
let providerChart = null;
let modelTypeChart = null;

const latencyByModelType = computed(() => {
    const rows = stats.value.latency_by_model_type || [];
    return [...rows]
        .filter((row) => Number(row?.count) > 0)
        .sort((a, b) => b.count - a.count);
});

const openLatencyBreakdown = () => {
    if (!latencyByModelType.value.length) return;
    showLatencyBreakdownModal.value = true;
};

const closeLatencyBreakdown = () => {
    showLatencyBreakdownModal.value = false;
};

const loadConfig = async () => {
    try {
        const data = await apiRequest('get', '/config');
        layout.value = data.layout || 'full';
    } catch (error) {
        // fallback to full
    }
};

const loadStats = async () => {
    loading.value = true;
    try {
        const data = await apiRequest('get', '/stats?' + buildQueryParams().toString());
        stats.value = data;
        layout.value = data.layout || layout.value;
        // Draw once the chart containers are visible, so they get a real size.
        loading.value = false;
        await nextTick();
        renderCharts();
    } catch (error) {
        console.error('Failed to load stats:', error);
    } finally {
        loading.value = false;
    }
};

const onRangeChange = async () => {
    await syncQueryToRouter();
    await loadStats();
};

const renderCharts = () => {
    renderRequestsChart();
    renderProviderChart();
    renderModelTypeChart();
};

const renderRequestsChart = () => {
    if (requestsChart) requestsChart.destroy();
    if (!requestsChartRef.value) return;

    const data = stats.value.requests_by_date || [];

    requestsChart = createLineChart(requestsChartRef.value, {
        labels: data.map(d => formatShortDate(d.date)),
        values: data.map(d => d.count),
    }, {
        label: 'Requests',
        chartOptions: { aspectRatio: 2.6 },
    });
};

const renderProviderChart = () => {
    if (providerChart) providerChart.destroy();
    if (!providerChartRef.value) return;

    const data = stats.value.requests_by_provider || [];

    providerChart = createDoughnutChart(providerChartRef.value, {
        labels: data.map(d => providerDisplayName(d.provider)),
        values: data.map(d => d.count),
        colors: () => data.map(d => providerChartColor(d.provider)),
    }, {
        legend: false,
        chartOptions: {
            animation: false,
        },
    });
};

const renderModelTypeChart = () => {
    if (modelTypeChart) modelTypeChart.destroy();
    if (!modelTypeChartRef.value) return;

    const data = stats.value.stats_by_model_type || [];

    modelTypeChart = createDoughnutChart(modelTypeChartRef.value, {
        labels: data.map(d => d.label),
        values: data.map(d => d.count),
        colors: () => data.map((d, index) => seriesColor(index)),
    }, {
        legend: false,
        chartOptions: {
            animation: false,
        },
    });
};

const goToRequest = (id) => {
    router.push({ name: 'request-preview', params: { id } });
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

const showTokens = computed(() => ['full', 'text', 'embedding'].includes(layout.value));
const showImages = computed(() => ['full', 'image'].includes(layout.value));
const showVideos = computed(() => ['full', 'video'].includes(layout.value));
const showTts = computed(() => ['full', 'audio'].includes(layout.value));
const showStt = computed(() => ['full', 'audio'].includes(layout.value));

// Daily request counts for the stat-card sparkline.
const requestTrend = computed(() => (stats.value.requests_by_date || []).map((d) => d.count));

// Legend rows for the breakdown doughnut: label, count, share and color.
const breakdownRows = computed(() => {
    const byProvider = breakdownTab.value === 'provider';
    const rows = byProvider ? (stats.value.requests_by_provider || []) : (stats.value.stats_by_model_type || []);
    const total = rows.reduce((sum, row) => sum + Number(row.count || 0), 0) || 1;

    return rows.map((row, index) => ({
        key: byProvider ? row.provider : row.model_type ?? row.label,
        label: byProvider ? providerDisplayName(row.provider) : row.label,
        provider: byProvider ? row.provider : null,
        count: Number(row.count || 0),
        share: (Number(row.count || 0) / total) * 100,
        color: byProvider ? providerChartColor(row.provider) : seriesColor(index),
    }));
});

const topModelMax = computed(() => Math.max(1, ...(stats.value.top_models || []).map((m) => Number(m.count || 0))));

// Media stat cards are shown only when the period has that kind of traffic.
const mediaCards = computed(() => [
    { key: 'images', show: showImages.value && stats.value.total_images > 0, label: 'Images', value: formatNumber(stats.value.total_images), unit: stats.value.total_images === 1 ? 'image' : 'images', cost: stats.value.cost_by_model_type?.image ?? 0 },
    { key: 'videos', show: showVideos.value && stats.value.total_videos > 0, label: 'Videos', value: formatNumber(stats.value.total_videos), unit: stats.value.total_videos === 1 ? 'video' : 'videos', cost: stats.value.cost_by_model_type?.video ?? 0 },
    { key: 'tts', show: showTts.value && stats.value.tts_characters > 0, label: 'Text-to-speech', value: formatCompact(stats.value.tts_characters), unit: 'characters', cost: stats.value.cost_by_model_type?.tts ?? 0 },
    { key: 'stt', show: showStt.value && stats.value.stt_duration_seconds > 0, label: 'Speech-to-text', value: formatDurationSeconds(stats.value.stt_duration_seconds), unit: 'audio', cost: stats.value.cost_by_model_type?.stt ?? 0 },
].filter((card) => card.show));

onMounted(async () => {
    applyQueryState();
    await syncQueryToRouter(null, true);
    await Promise.all([
        loadConfig(),
        loadProviders(),
    ]);
    await loadStats();
});

onUnmounted(() => {
    if (requestsChart) requestsChart.destroy();
    if (providerChart) providerChart.destroy();
    if (modelTypeChart) modelTypeChart.destroy();
});
</script>

<template>
    <div>
        <PageRangeHeader
            title="Overview"
            :subtitle="`AI traffic, spend and latency · ${periodLabel}`"
            v-model:period="period"
            v-model:start-date="startDate"
            v-model:end-date="endDate"
            :loading="loading"
            @change="onRangeChange"
        />

        <!-- Headline numbers -->
        <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Requests" :loading="loading">
                <div class="stat-value">{{ formatNumber(stats.total_requests) }}</div>
                <template #footer>
                    <Sparkline v-if="!loading" :values="requestTrend" class="mt-3" />
                </template>
            </StatCard>
            <StatCard label="Spend" :loading="loading" skeleton-width="70%">
                <div class="stat-value"><Money :cents="stats.total_cost_in_cents" /></div>
                <template #footer>
                    <div class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        <template v-if="stats.total_requests">
                            <Money :cents="stats.total_cost_in_cents / stats.total_requests" /> per request on average
                        </template>
                        <template v-else>No spend in this period</template>
                    </div>
                </template>
            </StatCard>
            <StatCard v-if="showTokens" label="Tokens" :loading="loading" skeleton-width="50%">
                <div class="stat-value" :title="formatNumber(stats.total_tokens) + ' tokens'">{{ formatCompact(stats.total_tokens) }}</div>
                <template #footer>
                    <div class="mt-3 text-xs text-gray-500 dark:text-gray-400">Input and output, text and embeddings</div>
                </template>
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
                <div class="stat-value">{{ formatDuration(stats.avg_latency) }}</div>
                <template #footer>
                    <div class="mt-3 text-xs text-gray-500 dark:text-gray-400">Across successful requests</div>
                </template>
            </StatCard>
        </div>

        <!-- Media usage (only kinds that appear in this period) -->
        <div
            v-if="!loading && mediaCards.length"
            class="card mb-4 grid grid-cols-1 divide-y divide-gray-100 sm:divide-x sm:divide-y-0 dark:divide-gray-800"
            :class="{ 1: 'sm:grid-cols-1', 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-3', 4: 'sm:grid-cols-4' }[mediaCards.length]"
        >
            <div v-for="card in mediaCards" :key="card.key" class="flex items-center justify-between gap-4 px-5 py-3.5">
                <div class="min-w-0">
                    <div class="stat-label">{{ card.label }}</div>
                    <div class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="text-lg font-semibold tracking-tight text-gray-900 tabular-nums dark:text-white">{{ card.value }}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ card.unit }}</span>
                    </div>
                </div>
                <span class="text-sm font-medium tabular-nums text-gray-700 dark:text-gray-300"><Money :cents="card.cost" /></span>
            </div>
        </div>

        <!-- Charts -->
        <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="card lg:col-span-2">
                <div class="card-header">
                    <div>
                        <h3>Requests over time</h3>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Daily volume</p>
                    </div>
                </div>
                <div class="card-body">
                    <template v-if="loading">
                        <Skeleton variant="chart" height="240px" />
                    </template>
                    <canvas v-show="!loading" ref="requestsChartRef"></canvas>
                </div>
            </div>

            <div class="card flex flex-col">
                <div class="card-header">
                    <div>
                        <h3>Breakdown</h3>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Share of requests</p>
                    </div>
                    <div class="segmented">
                        <button type="button" :class="{ 'is-active': breakdownTab === 'provider' }" @click="breakdownTab = 'provider'">Provider</button>
                        <button v-if="layout === 'full'" type="button" :class="{ 'is-active': breakdownTab === 'model-type' }" @click="breakdownTab = 'model-type'">Type</button>
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
                            <span class="tabular-nums text-gray-900 dark:text-white">{{ formatNumber(row.count) }}</span>
                            <span class="w-12 text-right tabular-nums text-xs text-gray-500 dark:text-gray-400">{{ row.share.toFixed(1) }}%</span>
                        </li>
                        <li v-if="!breakdownRows.length" class="py-6 text-center text-gray-500 dark:text-gray-400">No requests in this period</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Tables -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="card overflow-hidden">
                <div class="card-header">
                    <div>
                        <h3>Top models</h3>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">By request volume</p>
                    </div>
                    <router-link :to="{ name: 'costs' }" class="text-xs font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400">View costs</router-link>
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
                                <tr v-for="model in stats.top_models" :key="model.provider + model.model">
                                    <td class="max-w-0 w-[55%]">
                                        <div class="flex min-w-0 items-center gap-2.5">
                                            <ProviderLogo
                                                :provider="model.provider"
                                                :title="providerDisplayName(model.provider)"
                                                class="inline-flex h-4 w-4 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full"
                                                aria-hidden="true"
                                            />
                                            <div class="min-w-0 flex-1">
                                                <div class="truncate font-medium text-gray-900 dark:text-white" :title="model.model">{{ model.model }}</div>
                                                <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                                    <div class="h-full rounded-full bg-primary-500/70" :style="{ width: (model.count / topModelMax) * 100 + '%' }"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-right tabular-nums">{{ formatNumber(model.count) }}</td>
                                    <td class="text-right tabular-nums font-medium text-gray-900 dark:text-white"><Money :cents="model.cost" /></td>
                                </tr>
                                <tr v-if="!stats.top_models?.length">
                                    <td colspan="3">
                                        <div class="empty-state">
                                            <p class="empty-state-title">No models used in this period</p>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card overflow-hidden">
                <div class="card-header">
                    <div>
                        <h3>Recent requests</h3>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Latest activity</p>
                    </div>
                    <router-link :to="{ name: 'requests' }" class="text-xs font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400">View all</router-link>
                </div>
                <div class="overflow-x-auto">
                    <table class="table table-fixed">
                        <colgroup>
                            <col class="w-[46%]" />
                            <col class="w-[20%]" />
                            <col class="w-[14%]" />
                            <col class="w-[20%]" />
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Model</th>
                                <th class="text-right">Usage</th>
                                <th>Status</th>
                                <th class="text-right">When</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-if="loading">
                                <Skeleton variant="table-row" :lines="5" />
                            </template>
                            <template v-else>
                                <tr
                                    v-for="request in stats.recent_requests"
                                    :key="request.id"
                                    class="group cursor-pointer"
                                    @click="goToRequest(request.id)"
                                >
                                    <td class="max-w-0">
                                        <div class="flex min-w-0 items-center gap-2.5">
                                            <ProviderLogo
                                                :provider="request.provider"
                                                :title="providerDisplayName(request.provider, request.provider_display_name)"
                                                class="inline-flex h-4 w-4 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full"
                                                aria-hidden="true"
                                            />
                                            <span class="truncate font-medium text-gray-900 dark:text-white" :title="request.model || ''">{{ truncate(request.model, 32) }}</span>
                                        </div>
                                    </td>
                                    <td class="text-right tabular-nums">{{ formatUsage(request) }}</td>
                                    <td><StatusCode :code="request.status_code" /></td>
                                    <td class="whitespace-nowrap text-right text-gray-500 transition-colors group-hover:text-primary-600 dark:text-gray-400 dark:group-hover:text-primary-400">
                                        {{ request.created_at_human }}
                                    </td>
                                </tr>
                                <tr v-if="!stats.recent_requests?.length">
                                    <td colspan="4">
                                        <div class="empty-state">
                                            <p class="empty-state-title">No requests yet</p>
                                            <p class="empty-state-description">Requests to AI providers will show up here as your app makes them.</p>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Latency by model type -->
        <div
            v-if="showLatencyBreakdownModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4 backdrop-blur-sm"
            @click="closeLatencyBreakdown"
        >
            <div class="w-full max-w-xl overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-pop dark:border-gray-800 dark:bg-gray-900" @click.stop>
                <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">Average latency by model type</h3>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ periodLabel }}</p>
                    </div>
                    <button type="button" class="btn-ghost inline-flex h-8 w-8 items-center justify-center rounded-lg" @click="closeLatencyBreakdown">
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
                            <tr v-for="row in latencyByModelType" :key="row.model_type">
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
