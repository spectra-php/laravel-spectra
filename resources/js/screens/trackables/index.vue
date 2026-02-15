<script setup>
import { ref, onMounted, onUnmounted, watch, computed } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { usePeriodQuery } from '@/composables/usePeriodQuery';
import { useProviders } from '@/composables/useProviders';
import { useLoading } from '@/composables/useLoading';
import { debounce } from 'lodash';
import RequestFilters from '@/components/RequestFilters.vue';
import StatCard from '@/components/StatCard.vue';
import PageRangeHeader from '@/components/PageRangeHeader.vue';

const router = useRouter();
const route = useRoute();
const { formatNumber, formatCurrency, formatDuration, formatDurationSeconds, apiRequest } = useHelpers();
const { providers, loadProviders } = useProviders();
const loading = useLoading();

const trackables = ref([]);
loading.value = true;
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const summary = ref({ total_trackables: 0, total_requests: 0, total_tokens: 0, total_images: 0, total_videos: 0, total_tts_characters: 0, tts_duration_seconds: 0, stt_duration_seconds: 0, total_cost: 0, avg_latency: 0 });
const latencyByModelTypeRaw = ref([]);
const showLatencyBreakdownModal = ref(false);
const modelTypes = ref([]);
const availableTags = ref([]);
const availableFinishReasons = ref([]);
const isBootstrapping = ref(true);

const filterKeys = [
    'search',
    'provider',
    'model',
    'status',
    'tag',
    'model_type',
    'finish_reason',
    'has_tool_calls',
    'trace_id',
    'sort_by',
    'sort_dir',
];

const clearableFilterKeys = filterKeys.filter((key) => !['sort_by', 'sort_dir'].includes(key));

const trackableSortOptions = [
    { value: 'cost', label: 'Sort by Cost' },
    { value: 'requests', label: 'Sort by Requests' },
    { value: 'tokens', label: 'Sort by Usage' },
];

const filtersFromQuery = () => {
    const query = route.query;
    const state = {};
    const qs = (value) => Array.isArray(value) ? value[0] : value;

    for (const key of filterKeys) {
        const value = qs(query[key]);
        state[key] = typeof value === 'string' ? value : '';
    }

    state.sort_by = qs(query.sort_by) || 'cost';
    state.sort_dir = qs(query.sort_dir) || 'desc';

    return state;
};

const filters = ref(filtersFromQuery());

const { period, startDate, endDate, periodLabel, applyQueryState, buildQueryParams, syncQueryToRouter } = usePeriodQuery({
    filterKeys,
    getFilters: () => filters.value,
});

const latencyByModelType = computed(() => {
    const rows = latencyByModelTypeRaw.value || [];
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

const loadTrackables = async (page = 1) => {
    loading.value = true;
    try {
        const params = buildQueryParams(page);
        const data = await apiRequest('get', '/trackables?' + params.toString());
        trackables.value = data.data;
        pagination.value = {
            current_page: data.current_page,
            last_page: data.last_page,
            total: data.total,
        };
        summary.value = data.summary || summary.value;
        latencyByModelTypeRaw.value = data.latency_by_model_type || [];
        availableTags.value = data.available_tags || [];
        availableFinishReasons.value = data.available_finish_reasons || [];
    } catch (error) {
        console.error('Failed to load trackables:', error);
    } finally {
        loading.value = false;
    }
};

const debouncedLoad = debounce(() => loadTrackables(1), 300);

watch(filters, () => {
    if (isBootstrapping.value) return;
    void syncQueryToRouter(1);
    debouncedLoad();
}, { deep: true });

const onRangeChange = async () => {
    await syncQueryToRouter(1);
    await loadTrackables(1);
};

const goToTrackable = (trackable) => {
    router.push({
        name: 'trackable-detail',
        params: { id: trackable.trackable_id },
        query: { type: trackable.trackable_type },
    });
};

const prevPage = async () => {
    if (pagination.value.current_page > 1) {
        const page = pagination.value.current_page - 1;
        await loadTrackables(page);
        await syncQueryToRouter(page);
    }
};

const nextPage = async () => {
    if (pagination.value.current_page < pagination.value.last_page) {
        const page = pagination.value.current_page + 1;
        await loadTrackables(page);
        await syncQueryToRouter(page);
    }
};

const clearFilters = () => {
    filters.value = {
        search: '',
        provider: '',
        model: '',
        status: '',
        tag: '',
        model_type: '',
        finish_reason: '',
        has_tool_calls: '',
        trace_id: '',
        sort_by: 'cost',
        sort_dir: 'desc',
    };
};

const hasActiveFilters = () => {
    return clearableFilterKeys.some((key) => filters.value[key] !== '');
};

const toggleSort = (field) => {
    if (filters.value.sort_by === field) {
        filters.value.sort_dir = filters.value.sort_dir === 'desc' ? 'asc' : 'desc';
    } else {
        filters.value.sort_by = field;
        filters.value.sort_dir = 'desc';
    }
};

const sortIcon = (field) => {
    if (filters.value.sort_by !== field) return null;
    return filters.value.sort_dir === 'desc' ? 'down' : 'up';
};

const getDisplayName = (trackable) => {
    if (trackable.trackable_name) return trackable.trackable_name;
    if (trackable.trackable_email) return trackable.trackable_email;

    const parts = trackable.trackable_type.split('\\');
    const className = parts[parts.length - 1];
    return `${className} #${trackable.trackable_id}`;
};

const getTypeName = (trackable) => {
    const parts = trackable.trackable_type.split('\\');
    return parts[parts.length - 1];
};

const getInitials = (trackable) => {
    const name = trackable.trackable_name || trackable.trackable_email || getTypeName(trackable);
    return name.charAt(0).toUpperCase();
};

const getTypeColor = (type) => {
    const colors = {
        'User': 'from-blue-500 to-indigo-500',
        'Team': 'from-green-500 to-emerald-500',
        'Organization': 'from-purple-500 to-violet-500',
        'Project': 'from-orange-500 to-amber-500',
        'Workspace': 'from-pink-500 to-rose-500',
        'default': 'from-primary-500 to-purple-500',
    };
    const typeName = type.split('\\').pop();
    return colors[typeName] || colors.default;
};

const usageSegments = (data) => {
    const segments = [];
    if (data.tokens) segments.push({ value: formatNumber(data.tokens), unit: 'tokens' });
    if (data.images) segments.push({ value: formatNumber(data.images), unit: data.images === 1 ? 'image' : 'images' });
    if (data.videos) segments.push({ value: formatNumber(data.videos), unit: data.videos === 1 ? 'video' : 'videos' });
    if (data.tts_characters) segments.push({ value: formatNumber(data.tts_characters), unit: 'chars' });
    if (data.audio_duration) segments.push({ value: data.audio_duration.toFixed(1) + 's', unit: 'audio' });
    return segments;
};

const layout = ref('full');
const showTokens = computed(() => ['full', 'text', 'embedding'].includes(layout.value));
const showImages = computed(() => ['full', 'image'].includes(layout.value));
const showVideos = computed(() => ['full', 'video'].includes(layout.value));
const showTts = computed(() => ['full', 'audio'].includes(layout.value));
const showStt = computed(() => ['full', 'audio'].includes(layout.value));

const loadConfig = async () => {
    try {
        const data = await apiRequest('get', '/config');
        modelTypes.value = data.model_types || [];
        layout.value = data.layout || 'full';
    } catch (error) {
        modelTypes.value = [];
    }
};

onMounted(async () => {
    applyQueryState();
    filters.value = filtersFromQuery();
    const initialPage = parseInt(route.query.page) || 1;
    await syncQueryToRouter(initialPage, true);
    await Promise.all([
        loadConfig(),
        loadProviders(),
    ]);
    await loadTrackables(initialPage);
    isBootstrapping.value = false;
});

onUnmounted(() => {
    debouncedLoad.cancel();
});
</script>

<template>
    <div class="">
        <!-- Header -->
        <PageRangeHeader
            title="Trackables"
            v-model:period="period"
            v-model:start-date="startDate"
            v-model:end-date="endDate"
            :loading="loading"
            @change="onRangeChange"
        />

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
            <StatCard label="Total Requests" gradient="from-primary-500 to-cyan-500" :loading="loading" :period-label="periodLabel">
                <div class="stat-value !mt-1 !text-[2rem]">
                    {{ formatNumber(summary.total_requests) }}
                    <span class="text-sm font-medium text-gray-400"> / {{ formatNumber(summary.total_trackables) }} trackables</span>
                </div>
            </StatCard>
            <StatCard label="Total Cost" gradient="from-emerald-500 to-teal-500" :loading="loading" :period-label="periodLabel" skeleton-width="70%">
                <div class="stat-value !mt-1 !text-[2rem]">{{ formatCurrency(summary.total_cost) }}</div>
            </StatCard>
            <StatCard v-if="showTokens" label="Tokens" gradient="from-violet-500 to-indigo-500" :loading="loading" skeleton-width="50%">
                <div class="stat-value !mt-1 !text-[2rem]">
                    {{ formatNumber(summary.total_tokens) }}
                    <span class="text-sm font-medium text-gray-400 ml-1">tokens</span>
                </div>
                <template #footer>
                    <div class="text-xs text-gray-400 dark:text-gray-500 mt-1 uppercase tracking-wider">Text + Embeddings</div>
                </template>
            </StatCard>
            <StatCard v-if="showImages" label="Images Generated" gradient="from-fuchsia-500 to-pink-500" :loading="loading" skeleton-width="50%">
                <div class="stat-value !mt-1 !text-[2rem]">
                    {{ formatNumber(summary.total_images) }}
                    <span class="text-sm font-medium text-gray-400 ml-1">images</span>
                </div>
                <template #footer>
                    <div class="text-xs text-gray-400 dark:text-gray-500 mt-1 uppercase tracking-wider">{{ periodLabel }}</div>
                </template>
            </StatCard>
            <StatCard v-if="showVideos" label="Videos Generated" gradient="from-amber-500 to-orange-500" :loading="loading" skeleton-width="50%">
                <div class="stat-value !mt-1 !text-[2rem]">
                    {{ formatNumber(summary.total_videos) }}
                    <span class="text-sm font-medium text-gray-400 ml-1">videos</span>
                </div>
                <template #footer>
                    <div class="text-xs text-gray-400 dark:text-gray-500 mt-1 uppercase tracking-wider">{{ periodLabel }}</div>
                </template>
            </StatCard>
            <StatCard v-if="showTts" label="Text-to-Speech" gradient="from-cyan-500 to-blue-500" :loading="loading" skeleton-width="50%">
                <div class="stat-value !mt-1 !text-[2rem]">
                    {{ formatNumber(summary.total_tts_characters) }}
                    <span class="text-sm font-medium text-gray-400 ml-1">chars</span>
                </div>
                <template #footer>
                    <div class="text-xs text-gray-400 dark:text-gray-500 mt-1 uppercase tracking-wider">{{ formatDurationSeconds(summary.tts_duration_seconds) }} synthesized</div>
                </template>
            </StatCard>
            <StatCard v-if="showStt" label="Speech-to-Text" gradient="from-sky-500 to-indigo-500" :loading="loading" skeleton-width="50%">
                <div class="stat-value !mt-1 !text-[2rem]">
                    {{ formatDurationSeconds(summary.stt_duration_seconds) }}
                </div>
                <template #footer>
                    <div class="text-xs text-gray-400 dark:text-gray-500 mt-1 uppercase tracking-wider">Total transcribed audio</div>
                </template>
            </StatCard>
            <StatCard label="Avg Latency" gradient="from-rose-500 to-pink-500" :loading="loading" skeleton-width="40%">
                <div class="stat-value !mt-1 !text-[2rem]">
                    {{ formatDuration(summary.avg_latency) }}
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

        <!-- Filters -->
        <div class="card mb-6">
            <div class="card-body">
                <RequestFilters
                    v-model:filters="filters"
                    :providers="providers"
                    :model-types="modelTypes"
                    :available-tags="availableTags"
                    :available-finish-reasons="availableFinishReasons"
                    :sort-options="trackableSortOptions"
                    :active-filter-keys="clearableFilterKeys"
                    search-placeholder="Search trackables by ID..."
                    @clear="clearFilters"
                />
            </div>
        </div>

        <!-- Table -->
        <div class="card">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Trackable</th>
                            <th>Type</th>
                            <th class="text-right cursor-pointer hover:text-gray-900 dark:hover:text-white" @click="toggleSort('requests')">
                                <span class="inline-flex items-center gap-1">
                                    Requests
                                    <svg v-if="sortIcon('requests') === 'down'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                    <svg v-else-if="sortIcon('requests') === 'up'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" /></svg>
                                </span>
                            </th>
                            <th class="text-right cursor-pointer hover:text-gray-900 dark:hover:text-white" @click="toggleSort('tokens')">
                                <span class="inline-flex items-center gap-1">
                                    Usage
                                    <svg v-if="sortIcon('tokens') === 'down'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                    <svg v-else-if="sortIcon('tokens') === 'up'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" /></svg>
                                </span>
                            </th>
                            <th class="text-right cursor-pointer hover:text-gray-900 dark:hover:text-white" @click="toggleSort('cost')">
                                <span class="inline-flex items-center gap-1">
                                    Cost
                                    <svg v-if="sortIcon('cost') === 'down'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                    <svg v-else-if="sortIcon('cost') === 'up'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" /></svg>
                                </span>
                            </th>
                            <th class="text-right">Avg Latency</th>
                            <th>Top Model</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-if="loading">
                            <tr v-for="i in 10" :key="i" class="border-b border-gray-100 dark:border-gray-700/50">
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-full bg-gray-200 dark:bg-gray-700 animate-pulse"></div>
                                        <div>
                                            <div class="h-4 w-32 bg-gray-200 dark:bg-gray-700 rounded animate-pulse mb-1"></div>
                                            <div class="h-3 w-24 bg-gray-200 dark:bg-gray-700 rounded animate-pulse"></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4"><div class="h-6 w-16 bg-gray-200 dark:bg-gray-700 rounded-full animate-pulse"></div></td>
                                <td class="px-5 py-4"><div class="h-4 w-12 bg-gray-200 dark:bg-gray-700 rounded animate-pulse ml-auto"></div></td>
                                <td class="px-5 py-4"><div class="h-4 w-16 bg-gray-200 dark:bg-gray-700 rounded animate-pulse ml-auto"></div></td>
                                <td class="px-5 py-4"><div class="h-4 w-14 bg-gray-200 dark:bg-gray-700 rounded animate-pulse ml-auto"></div></td>
                                <td class="px-5 py-4"><div class="h-4 w-14 bg-gray-200 dark:bg-gray-700 rounded animate-pulse ml-auto"></div></td>
                                <td class="px-5 py-4"><div class="h-4 w-20 bg-gray-200 dark:bg-gray-700 rounded animate-pulse"></div></td>
                            </tr>
                        </template>
                        <template v-else>
                            <tr
                                v-for="(trackable, index) in trackables"
                                :key="`${trackable.trackable_type}-${trackable.trackable_id}`"
                                @click="goToTrackable(trackable)"
                                class="cursor-pointer group"
                            >
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div :class="['w-10 h-10 rounded-full bg-gradient-to-br flex items-center justify-center text-white font-bold', getTypeColor(trackable.trackable_type)]">
                                            {{ getInitials(trackable) }}
                                        </div>
                                        <div>
                                            <div class="font-medium text-gray-900 dark:text-white group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors">
                                                {{ getDisplayName(trackable) }}
                                            </div>
                                            <div v-if="trackable.trackable_email" class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ trackable.trackable_email }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-secondary">{{ getTypeName(trackable) }}</span>
                                </td>
                                <td class="text-right tabular-nums">{{ formatNumber(trackable.requests) }}</td>
                                <td class="text-right tabular-nums">
                                    <template v-for="(seg, i) in usageSegments(trackable)" :key="i">
                                        <span v-if="i > 0" class="text-gray-300 dark:text-gray-600 mx-1">&middot;</span>
                                        {{ seg.value }} <span class="text-xs text-gray-400 dark:text-gray-500">{{ seg.unit }}</span>
                                    </template>
                                </td>
                                <td class="text-right tabular-nums font-medium text-gray-900 dark:text-white">{{ formatCurrency(trackable.cost) }}</td>
                                <td class="text-right tabular-nums">{{ formatDuration(trackable.avg_latency) }}</td>
                                <td>
                                    <span class="text-sm text-gray-600 dark:text-gray-400">{{ trackable.top_model || '—' }}</span>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!loading && !trackables.length">
                            <td colspan="7">
                                <div class="empty-state py-16">
                                    <svg class="empty-state-icon mx-auto h-16 w-16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                    </svg>
                                    <p class="empty-state-title text-base">No trackables found</p>
                                    <p class="empty-state-description" v-if="hasActiveFilters()">
                                        Try adjusting your filters or
                                        <button @click="clearFilters" class="text-primary-600 dark:text-primary-400 hover:underline">clear all filters</button>
                                    </p>
                                    <p class="empty-state-description" v-else>
                                        No entities have made AI requests yet
                                    </p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="card-body border-t border-gray-100 dark:border-gray-700">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        <span v-if="loading" class="inline-block w-48 h-4 bg-gray-200 dark:bg-gray-700 rounded animate-pulse"></span>
                        <span v-else>
                            Showing page <span class="font-medium text-gray-700 dark:text-gray-300">{{ pagination.current_page }}</span>
                            of <span class="font-medium text-gray-700 dark:text-gray-300">{{ pagination.last_page }}</span>
                            <span class="text-gray-400 dark:text-gray-500 mx-1">&middot;</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ formatNumber(pagination.total) }}</span> total
                        </span>
                    </div>
                    <div class="flex gap-2">
                        <button
                            @click="prevPage"
                            :disabled="pagination.current_page <= 1 || loading"
                            class="btn btn-secondary"
                        >
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                            Previous
                        </button>
                        <button
                            @click="nextPage"
                            :disabled="pagination.current_page >= pagination.last_page || loading"
                            class="btn btn-secondary"
                        >
                            Next
                            <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>
                    </div>
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
