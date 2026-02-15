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

const router = useRouter();
const { formatNumber, formatCurrency, formatDuration, formatDurationSeconds, truncate, providerClass, providerChartColor, apiRequest } = useHelpers();
const { period, startDate, endDate, periodLabel, applyQueryState, buildQueryParams, syncQueryToRouter } = usePeriodQuery();
const { providers, loadProviders, providerDisplayName, providerLogo } = useProviders();
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
        labels: data.map(d => d.date),
        values: data.map(d => d.count),
    }, {
        label: 'Requests',
    });
};

const renderProviderChart = () => {
    if (providerChart) providerChart.destroy();
    if (!providerChartRef.value) return;

    const data = stats.value.requests_by_provider || [];

    providerChart = createDoughnutChart(providerChartRef.value, {
        labels: data.map(d => providerDisplayName(d.provider)),
        values: data.map(d => d.count),
        colors: data.map(d => providerChartColor(d.provider)),
    }, {
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
    }, {
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
    await Promise.all([
        loadConfig(),
        loadProviders(),
    ]);
    await loadStats();
    observer.observe(document.documentElement, { attributes: true });
});

onUnmounted(() => {
    observer.disconnect();
    if (requestsChart) requestsChart.destroy();
    if (providerChart) providerChart.destroy();
    if (modelTypeChart) modelTypeChart.destroy();
});
</script>

<template>
    <div class="">
        <!-- Header -->
        <PageRangeHeader
            title="Dashboard"
            v-model:period="period"
            v-model:start-date="startDate"
            v-model:end-date="endDate"
            :loading="loading"
            @change="onRangeChange"
        />

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
            <StatCard label="Total Requests" gradient="from-primary-500 to-cyan-500" :loading="loading" :period-label="periodLabel">
                <div class="stat-value !mt-1 !text-[2rem]">{{ formatNumber(stats.total_requests) }}</div>
            </StatCard>
            <StatCard label="Total Cost" gradient="from-emerald-500 to-teal-500" :loading="loading" :period-label="periodLabel" skeleton-width="70%">
                <div class="stat-value !mt-1 !text-[2rem]">{{ formatCurrency(stats.total_cost_in_cents) }}</div>
            </StatCard>
            <StatCard v-if="showTokens" label="Tokens" gradient="from-violet-500 to-indigo-500" :loading="loading" skeleton-width="50%">
                <div class="stat-value !mt-1 !text-[2rem]">
                    {{ formatNumber(stats.total_tokens) }}
                    <span class="text-sm font-medium text-gray-400 ml-1">tokens</span>
                </div>
                <template #footer>
                    <div class="text-xs text-gray-400 dark:text-gray-500 mt-1 uppercase tracking-wider">Text + Embeddings</div>
                </template>
            </StatCard>
            <StatCard v-if="showImages" label="Images Generated" gradient="from-fuchsia-500 to-pink-500" :loading="loading" skeleton-width="50%">
                <div class="stat-value !mt-1 !text-[2rem]">
                    {{ formatNumber(stats.total_images) }}
                    <span class="text-sm font-medium text-gray-400 ml-1">images</span>
                    <span class="text-sm font-medium text-gray-400"> / {{ formatCurrency(stats.cost_by_model_type?.image ?? 0) }}</span>
                </div>
                <template #footer>
                    <div class="text-xs text-gray-400 dark:text-gray-500 mt-1 uppercase tracking-wider">{{ formatDurationSeconds(stats.total_duration_seconds) }} runtime</div>
                </template>
            </StatCard>
            <StatCard v-if="showVideos" label="Videos Generated" gradient="from-amber-500 to-orange-500" :loading="loading" skeleton-width="50%">
                <div class="stat-value !mt-1 !text-[2rem]">
                    {{ formatNumber(stats.total_videos) }}
                    <span class="text-sm font-medium text-gray-400 ml-1">videos</span>
                    <span class="text-sm font-medium text-gray-400"> / {{ formatCurrency(stats.cost_by_model_type?.video ?? 0) }}</span>
                </div>
                <template #footer>
                    <div class="text-xs text-gray-400 dark:text-gray-500 mt-1 uppercase tracking-wider">{{ formatDurationSeconds(stats.total_duration_seconds) }} runtime</div>
                </template>
            </StatCard>
            <StatCard v-if="showTts" label="Text-to-Speech" gradient="from-cyan-500 to-blue-500" :loading="loading" skeleton-width="50%">
                <div class="stat-value !mt-1 !text-[2rem]">
                    {{ formatNumber(stats.tts_characters) }}
                    <span class="text-sm font-medium text-gray-400 ml-1">chars</span>
                    <span class="text-sm font-medium text-gray-400"> / {{ formatCurrency(stats.cost_by_model_type?.tts ?? 0) }}</span>
                </div>
                <template #footer>
                    <div class="text-xs text-gray-400 dark:text-gray-500 mt-1 uppercase tracking-wider">{{ formatDurationSeconds(stats.tts_duration_seconds) }} synthesized</div>
                </template>
            </StatCard>
            <StatCard v-if="showStt" label="Speech-to-Text" gradient="from-sky-500 to-indigo-500" :loading="loading" skeleton-width="50%">
                <div class="stat-value !mt-1 !text-[2rem]">
                    {{ formatDurationSeconds(stats.stt_duration_seconds) }}
                    <span class="text-sm font-medium text-gray-400"> / {{ formatCurrency(stats.cost_by_model_type?.stt ?? 0) }}</span>
                </div>
                <template #footer>
                    <div class="text-xs text-gray-400 dark:text-gray-500 mt-1 uppercase tracking-wider">Total transcribed audio</div>
                </template>
            </StatCard>
            <StatCard label="Avg Latency" gradient="from-rose-500 to-pink-500" :loading="loading" skeleton-width="40%">
                <div class="stat-value !mt-1 !text-[2rem]">
                    {{ formatDuration(stats.avg_latency) }}
                    <template v-if="latencyByModelType.length">
                        <span class="text-sm font-medium text-gray-400"> / </span>
                        <button
                            type="button"
                            class="text-sm font-medium text-primary-600 dark:text-primary-400 hover:underline"
                            @click="openLatencyBreakdown"
                        >
                            stats
                        </button>
                    </template>
                </div>
                <template #footer>
                    <div class="text-xs text-gray-400 dark:text-gray-500 mt-1 uppercase tracking-wider">Across successful requests</div>
                </template>
            </StatCard>
        </div>

        <!-- Charts -->
        <div class="flex items-center justify-between mb-3 px-1">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white uppercase tracking-wider">Trends & Breakdown</h3>
            <span class="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wider">{{ periodLabel }}</span>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <div class="lg:col-span-2 card">
                <div class="card-header">
                    <div>
                        <h3 class="text-sm font-semibold">Requests Over Time</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Daily request volume trend</p>
                    </div>
                </div>
                <div class="card-body">
                    <template v-if="loading">
                        <Skeleton variant="chart" height="200px" />
                    </template>
                    <canvas v-show="!loading" ref="requestsChartRef" height="100"></canvas>
                </div>
            </div>
            <div class="card">
                <div class="card-header flex-wrap gap-3">
                    <div>
                        <h3 class="text-sm font-semibold">Request Breakdown</h3>
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
                            v-if="layout === 'full'"
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
                    <div v-if="loading" class="flex items-center justify-center" style="height: 200px">
                        <div class="w-32 h-32 rounded-full bg-gray-200 dark:bg-gray-700 animate-pulse"></div>
                    </div>
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
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="text-sm font-semibold">Top Models</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Highest request volume</p>
                    </div>
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
                                <tr v-for="model in stats.top_models" :key="model.model">
                                    <td>
                                        <div class="flex items-center gap-2 min-w-0">
                                            <span
                                                v-if="providerLogo(model.provider)"
                                                v-html="providerLogo(model.provider)"
                                                :title="providerDisplayName(model.provider)"
                                                class="inline-flex items-center shrink-0 w-4 h-4 [&>svg]:w-full [&>svg]:h-full text-gray-500 dark:text-gray-400"
                                                aria-hidden="true"
                                            ></span>
                                            <span class="max-w-[14rem] truncate font-medium text-gray-900 dark:text-white" :title="model.model">
                                                {{ model.model }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="text-right tabular-nums font-medium">{{ formatNumber(model.count) }}</td>
                                    <td class="text-right tabular-nums font-medium text-gray-900 dark:text-white">{{ formatCurrency(model.cost) }}</td>
                                </tr>
                                <tr v-if="!stats.top_models?.length">
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

            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="text-sm font-semibold">Recent Requests</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Latest activity feed</p>
                    </div>
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
                                    v-for="request in stats.recent_requests"
                                    :key="request.id"
                                    @click="goToRequest(request.id)"
                                    class="cursor-pointer group"
                                >
                                    <td class="max-w-0">
                                        <div class="flex items-center gap-2 min-w-0">
                                            <span
                                                v-if="providerLogo(request.provider)"
                                                v-html="providerLogo(request.provider)"
                                                :title="providerDisplayName(request.provider, request.provider_display_name)"
                                                class="inline-flex items-center shrink-0 w-4 h-4 [&>svg]:w-full [&>svg]:h-full text-gray-500 dark:text-gray-400"
                                                aria-hidden="true"
                                            ></span>
                                            <span
                                                class="truncate font-medium text-gray-900 dark:text-white"
                                                :title="request.model || ''"
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
                                <tr v-if="!stats.recent_requests?.length">
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

        <div
            v-if="showLatencyBreakdownModal"
            class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
            @click="closeLatencyBreakdown"
        >
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-2xl max-h-[85vh] overflow-hidden" @click.stop>
                <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700 flex items-start justify-between gap-4">
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
    </div>
</template>
