<script setup>
import { ref, onMounted, onUnmounted, watch, computed } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { usePeriodQuery } from '@/composables/usePeriodQuery';
import { useProviders } from '@/composables/useProviders';
import { useLoading } from '@/composables/useLoading';
import { debounce } from 'lodash';
import Skeleton from '@/components/Skeleton.vue';
import StatusCode from '@/components/StatusCode.vue';
import RequestFilters from '@/components/RequestFilters.vue';
import PageRangeHeader from '@/components/PageRangeHeader.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';

const router = useRouter();
const route = useRoute();
const { formatNumber, formatCurrency, formatDuration, providerClass, apiRequest } = useHelpers();
const { providers, loadProviders } = useProviders();
const loading = useLoading();

const requests = ref([]);
loading.value = true;
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const availableTags = ref([]);
const availableFinishReasons = ref([]);
const availableReasoningEfforts = ref([]);
const modelTypes = ref([]);
const isBootstrapping = ref(true);

const filterKeys = ['search', 'provider', 'model', 'status', 'tag', 'model_type', 'finish_reason', 'has_tool_calls', 'is_reasoning', 'reasoning_effort', 'trace_id', 'trackable_type', 'trackable_id', 'sort_by', 'sort_dir'];
const clearableFilterKeys = filterKeys.filter((key) => !['sort_by', 'sort_dir'].includes(key));
const requestSortOptions = [
    { value: 'created_at', label: 'Sort by Time' },
    { value: 'total_cost_in_cents', label: 'Sort by Cost' },
    { value: 'total_tokens', label: 'Sort by Usage' },
    { value: 'latency_ms', label: 'Sort by Latency' },
];

const filtersFromQuery = () => {
    const q = route.query;
    const f = {};
    const qs = (value) => Array.isArray(value) ? value[0] : value;
    for (const key of filterKeys) {
        const value = qs(q[key]);
        f[key] = typeof value === 'string' ? value : '';
    }
    f.sort_by = qs(q.sort_by) || 'created_at';
    f.sort_dir = qs(q.sort_dir) || 'desc';
    return f;
};

const filters = ref(filtersFromQuery());

const { period, startDate, endDate, applyQueryState, buildQueryParams, syncQueryToRouter } = usePeriodQuery({
    filterKeys,
    getFilters: () => filters.value,
});

const loadRequests = async (page = 1) => {
    loading.value = true;
    try {
        const params = buildQueryParams(page);
        const data = await apiRequest('get', '/requests?' + params.toString());
        requests.value = data.data;
        pagination.value = {
            current_page: data.current_page,
            last_page: data.last_page,
            total: data.total,
        };
        if (data.available_tags) {
            availableTags.value = data.available_tags;
        }
        if (data.available_finish_reasons) {
            availableFinishReasons.value = data.available_finish_reasons;
        }
        if (data.available_reasoning_efforts) {
            availableReasoningEfforts.value = data.available_reasoning_efforts;
        }
    } catch (error) {
        console.error('Failed to load requests:', error);
    } finally {
        loading.value = false;
    }
};

const debouncedLoad = debounce(() => loadRequests(1), 300);

watch(filters, () => {
    if (isBootstrapping.value) return;
    void syncQueryToRouter(1);
    debouncedLoad();
}, { deep: true });

const onRangeChange = async () => {
    await syncQueryToRouter(1);
    await loadRequests(1);
};

const loadConfig = async () => {
    try {
        const data = await apiRequest('get', '/config');
        modelTypes.value = data.model_types || [];
    } catch (error) {
        // ignore
    }
};

const goToRequest = (id) => {
    router.push({ name: 'request-preview', params: { id } });
};

const prevPage = async () => {
    if (pagination.value.current_page > 1) {
        const page = pagination.value.current_page - 1;
        await loadRequests(page);
        await syncQueryToRouter(page);
    }
};

const nextPage = async () => {
    if (pagination.value.current_page < pagination.value.last_page) {
        const page = pagination.value.current_page + 1;
        await loadRequests(page);
        await syncQueryToRouter(page);
    }
};

const clearFilters = () => {
    const f = {};
    for (const key of filterKeys) f[key] = '';
    f.sort_by = 'created_at';
    f.sort_dir = 'desc';
    filters.value = f;
};

const hasActiveFilters = () => {
    return filterKeys.filter(k => k !== 'sort_by' && k !== 'sort_dir').some(k => filters.value[k] !== '');
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

const formatUsage = (request) => {
    const type = request.model_type;
    if (type === 'image') {
        const tokens = (request.prompt_tokens || 0) + (request.completion_tokens || 0);
        if (tokens > 0) {
            return { value: formatNumber(tokens), label: 'tokens' };
        }
        const count = request.image_count || 0;
        return { value: count, label: count === 1 ? 'image' : 'images' };
    }
    if (type === 'tts') {
        const tokens = (request.prompt_tokens || 0) + (request.completion_tokens || 0);
        if (tokens > 0) {
            return { value: formatNumber(tokens), label: 'tokens' };
        }
        return { value: formatNumber(request.input_characters || 0), label: 'chars' };
    }
    if (type === 'video') {
        const count = request.video_count || 0;
        return { value: count, label: count === 1 ? 'video' : 'videos' };
    }
    if (type === 'stt') {
        const tokens = (request.prompt_tokens || 0) + (request.completion_tokens || 0);
        if (tokens > 0) {
            return { value: formatNumber(tokens), label: 'tokens' };
        }
        const dur = request.duration_seconds || 0;
        return { value: dur.toFixed(1) + 's', label: 'audio' };
    }
    return { value: formatNumber(request.total_tokens), label: 'tokens' };
};

const usageLabel = computed(() => 'Usage');

onMounted(async () => {
    applyQueryState();
    filters.value = filtersFromQuery();
    const initialPage = parseInt(route.query.page) || 1;
    await syncQueryToRouter(initialPage, true);
    await Promise.all([
        loadConfig(),
        loadProviders(),
    ]);
    await loadRequests(initialPage);
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
            title="Requests"
            v-model:period="period"
            v-model:start-date="startDate"
            v-model:end-date="endDate"
            :loading="loading"
            @change="onRangeChange"
        />

        <!-- Filters -->
        <div class="card mb-6">
            <div class="card-body">
                <RequestFilters
                    v-model:filters="filters"
                    :providers="providers"
                    :model-types="modelTypes"
                    :available-tags="availableTags"
                    :available-finish-reasons="availableFinishReasons"
                    :available-reasoning-efforts="availableReasoningEfforts"
                    :sort-options="requestSortOptions"
                    :active-filter-keys="clearableFilterKeys"
                    search-placeholder="Search requests..."
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
                            <th>Model</th>
                            <th>Tags</th>
                            <th class="text-right cursor-pointer hover:text-gray-900 dark:hover:text-white select-none" @click="toggleSort('total_tokens')">
                                <span class="inline-flex items-center gap-1">
                                    {{ usageLabel }}
                                    <svg v-if="sortIcon('total_tokens') === 'down'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                    <svg v-else-if="sortIcon('total_tokens') === 'up'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" /></svg>
                                </span>
                            </th>
                            <th class="text-right cursor-pointer hover:text-gray-900 dark:hover:text-white select-none" @click="toggleSort('total_cost_in_cents')">
                                <span class="inline-flex items-center gap-1">
                                    Cost
                                    <svg v-if="sortIcon('total_cost_in_cents') === 'down'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                    <svg v-else-if="sortIcon('total_cost_in_cents') === 'up'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" /></svg>
                                </span>
                            </th>
                            <th class="text-right cursor-pointer hover:text-gray-900 dark:hover:text-white select-none" @click="toggleSort('latency_ms')">
                                <span class="inline-flex items-center gap-1">
                                    Latency
                                    <svg v-if="sortIcon('latency_ms') === 'down'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                    <svg v-else-if="sortIcon('latency_ms') === 'up'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" /></svg>
                                </span>
                            </th>
                            <th>Status</th>
                            <th class="text-right cursor-pointer hover:text-gray-900 dark:hover:text-white select-none" @click="toggleSort('created_at')">
                                <span class="inline-flex items-center gap-1">
                                    Time
                                    <svg v-if="sortIcon('created_at') === 'down'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                    <svg v-else-if="sortIcon('created_at') === 'up'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" /></svg>
                                </span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-if="loading">
                            <tr v-for="i in 10" :key="i" class="border-b border-gray-100 dark:border-gray-700/50">
                                <td class="px-5 py-4"><div class="h-4 w-32 bg-gray-200 dark:bg-gray-700 rounded animate-pulse"></div></td>
                                <td class="px-5 py-4"><div class="flex gap-1"><div class="h-5 w-16 bg-gray-200 dark:bg-gray-700 rounded-full animate-pulse"></div></div></td>
                                <td class="px-5 py-4"><div class="h-4 w-12 bg-gray-200 dark:bg-gray-700 rounded animate-pulse ml-auto"></div></td>
                                <td class="px-5 py-4"><div class="h-4 w-16 bg-gray-200 dark:bg-gray-700 rounded animate-pulse ml-auto"></div></td>
                                <td class="px-5 py-4"><div class="h-4 w-14 bg-gray-200 dark:bg-gray-700 rounded animate-pulse ml-auto"></div></td>
                                <td class="px-5 py-4"><div class="h-6 w-20 bg-gray-200 dark:bg-gray-700 rounded-full animate-pulse"></div></td>
                                <td class="px-5 py-4"><div class="h-4 w-20 bg-gray-200 dark:bg-gray-700 rounded animate-pulse ml-auto"></div></td>
                            </tr>
                        </template>
                        <template v-else>
                            <tr
                                v-for="(request, index) in requests"
                                :key="request.id"
                                @click="goToRequest(request.id)"
                                class="cursor-pointer group"
                            >
                                <td>
                                    <div class="flex items-center gap-2 min-w-0">
                                        <ProviderLogo
                                            :provider="request.provider"
                                            :title="request.provider_display_name || request.provider"
                                            class="inline-flex items-center shrink-0 w-4 h-4 [&>svg]:w-full [&>svg]:h-full text-gray-500 dark:text-gray-400"
                                        />
                                        <span class="font-medium text-gray-900 dark:text-white">{{ request.model }}</span>
                                        <span
                                            v-if="request.model_type"
                                            class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium uppercase bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400 shrink-0"
                                        >{{ request.model_type }}</span>
                                        <span
                                            v-if="request.is_streaming"
                                            class="inline-flex items-center text-primary-500 dark:text-primary-400 shrink-0"
                                            title="Streaming"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path d="M6.26,19.089A9.625,9.625,0,0,1,6.234,4.911C6.709,4.475,6,3.769,5.527,4.2A10.516,10.516,0,0,0,5.553,19.8c.475.433,1.184-.273.707-.707Z"/>
                                                <path d="M8.84,15.706a5.024,5.024,0,0,1-.014-7.412c.474-.437-.234-1.143-.707-.707a6.028,6.028,0,0,0,.014,8.826c.474.434,1.183-.272.707-.707Z"/>
                                                <circle cx="12" cy="12" r="1.244"/>
                                                <path d="M17.74,4.911a9.625,9.625,0,0,1,.026,14.178c-.475.436.234,1.142.707.707A10.516,10.516,0,0,0,18.447,4.2c-.475-.433-1.184.273-.707.707Z"/>
                                                <path d="M15.16,8.294a5.024,5.024,0,0,1,.014,7.412c-.474.437.234,1.143.707.707a6.028,6.028,0,0,0-.014-8.826c-.474-.434-1.183.272-.707.707Z"/>
                                            </svg>
                                        </span>
                                        <span
                                            v-if="request.is_reasoning"
                                            class="inline-flex items-center text-amber-500 dark:text-amber-400 shrink-0"
                                            :title="'Reasoning' + (request.reasoning_effort ? ` (${request.reasoning_effort})` : '')"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
                                                <path d="M16,4c-4.963,0-9,4.038-9,9c0,3.186,1.781,5.278,3.212,6.959C11.172,21.085,12,22.059,12,23v5h3v1h2v-1h3v-5c0-0.941,0.828-1.915,1.788-3.041C23.219,18.278,25,16.186,25,13C25,8.038,20.963,4,16,4z M18,26h-4v-2h4V26z M20.265,18.662c-0.923,1.084-1.805,2.12-2.132,3.338h-4.266c-0.327-1.218-1.209-2.254-2.132-3.338C10.391,17.083,9,15.45,9,13c0-3.86,3.141-7,7-7s7,3.14,7,7C23,15.45,21.609,17.083,20.265,18.662z M16,7v2c-2.206,0-4,1.794-4,4h-2C10,9.691,12.691,7,16,7z"/>
                                            </svg>
                                        </span>
                                    </div>
                                </td>
                                <td class="max-w-xs">
                                    <div class="flex flex-wrap gap-1" v-if="request.tags?.length">
                                        <span
                                            v-for="tag in request.tags.slice(0, 3)"
                                            :key="tag"
                                            class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300"
                                        >
                                            {{ tag }}
                                        </span>
                                        <span
                                            v-if="request.tags.length > 3"
                                            class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400"
                                        >
                                            +{{ request.tags.length - 3 }}
                                        </span>
                                    </div>
                                    <span v-else class="text-gray-400 dark:text-gray-500">&mdash;</span>
                                </td>
                                <td class="text-right tabular-nums">
                                    {{ formatUsage(request).value }}
                                    <span class="text-[11px] text-gray-400 dark:text-gray-500 ml-0.5">{{ formatUsage(request).label }}</span>
                                </td>
                                <td class="text-right tabular-nums font-medium">{{ formatCurrency(request.total_cost_in_cents) }}</td>
                                <td class="text-right tabular-nums">{{ formatDuration(request.latency_ms) }}</td>
                                <td>
                                    <StatusCode :code="request.status_code" />
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <span class="group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors">
                                        {{ request.created_at_human }}
                                    </span>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!loading && !requests.length">
                            <td colspan="7">
                                <div class="empty-state py-16">
                                    <svg class="empty-state-icon mx-auto h-16 w-16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                    </svg>
                                    <p class="empty-state-title text-base">No requests found</p>
                                    <p class="empty-state-description" v-if="hasActiveFilters()">
                                        Try adjusting your filters or
                                        <button @click="clearFilters" class="text-primary-600 dark:text-primary-400 hover:underline">clear all filters</button>
                                    </p>
                                    <p class="empty-state-description" v-else>
                                        Start making AI requests to see them here
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
    </div>
</template>
