<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { usePeriodQuery } from '@/composables/usePeriodQuery';
import { useProviders } from '@/composables/useProviders';
import { useLoading } from '@/composables/useLoading';
import debounce from 'lodash/debounce';
import RequestFilters from '@/components/RequestFilters.vue';
import PageRangeHeader from '@/components/PageRangeHeader.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import StatCard from '@/components/StatCard.vue';
import Money from '@/components/Money.vue';
import type { LocationQueryValue } from 'vue-router';
import type { DashboardConfigResponse, ModelTypeOption, SoleTrackable, TraceListItem, TracesResponse, TracesSummary } from '@/types/api';
import type { RequestFilterKey, RequestFilterValues } from '@/types/app';

const router = useRouter();
const route = useRoute();
const { formatNumber, formatDuration, apiRequest } = useHelpers();
const { providers, loadProviders } = useProviders();
const loading = useLoading();

const traces = ref<TraceListItem[]>([]);
loading.value = true;
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const summary = ref<TracesSummary | null>(null);
const availableTags = ref<string[]>([]);
const availableFinishReasons = ref<string[]>([]);
const availableReasoningEfforts = ref<string[]>([]);
const availableTrackableTypes = ref<string[]>([]);
const modelTypes = ref<ModelTypeOption[]>([]);
const isBootstrapping = ref(true);

const filterKeys: RequestFilterKey[] = ['search', 'provider', 'model', 'status', 'tag', 'model_type', 'finish_reason', 'has_tool_calls', 'is_reasoning', 'reasoning_effort', 'trackable_type', 'trackable_id', 'sort_by', 'sort_dir'];
const clearableFilterKeys = filterKeys.filter((key) => !['sort_by', 'sort_dir'].includes(key));
const traceSortOptions = [
    { value: 'started_at', label: 'Sort by Time' },
    { value: 'cost', label: 'Sort by Cost' },
    { value: 'duration', label: 'Sort by Duration' },
    { value: 'requests', label: 'Sort by Calls' },
    { value: 'failed', label: 'Sort by Failures' },
];

const filtersFromQuery = () => {
    const q = route.query;
    const f: RequestFilterValues = {};
    const qs = (value: LocationQueryValue | LocationQueryValue[] | undefined) => Array.isArray(value) ? value[0] : value;
    for (const key of filterKeys) {
        const value = qs(q[key]);
        f[key] = typeof value === 'string' ? value : '';
    }
    f.sort_by = qs(q.sort_by) || 'started_at';
    f.sort_dir = qs(q.sort_dir) || 'desc';
    return f;
};

const filters = ref<RequestFilterValues>(filtersFromQuery());

const { period, startDate, endDate, applyQueryState, buildQueryParams, syncQueryToRouter } = usePeriodQuery({
    filterKeys,
    getFilters: () => filters.value,
});

const migrated = ref(true);

const loadTraces = async (page = 1) => {
    loading.value = true;
    try {
        const params = buildQueryParams(page);
        const data = await apiRequest<TracesResponse>('get', '/traces?' + params.toString());
        traces.value = data.data;
        summary.value = data.summary;
        migrated.value = data.migrated !== false;
        pagination.value = {
            current_page: data.current_page,
            last_page: data.last_page,
            total: data.total,
        };
        availableTags.value = data.available_tags || [];
        availableFinishReasons.value = data.available_finish_reasons || [];
        availableReasoningEfforts.value = data.available_reasoning_efforts || [];
        availableTrackableTypes.value = data.available_trackable_types || [];
    } catch (error) {
        console.error('Failed to load traces:', error);
    } finally {
        loading.value = false;
    }
};

const debouncedLoad = debounce(() => loadTraces(1), 300);

watch(filters, () => {
    if (isBootstrapping.value) return;
    void syncQueryToRouter(1);
    debouncedLoad();
}, { deep: true });

const onRangeChange = async () => {
    await syncQueryToRouter(1);
    await loadTraces(1);
};

const loadConfig = async () => {
    try {
        const data = await apiRequest<DashboardConfigResponse>('get', '/config');
        modelTypes.value = data.model_types || [];
    } catch (error) {
        // The type filter just stays empty.
    }
};

const goToTrace = (traceId: string) => {
    router.push({ name: 'trace-detail', params: { traceId } });
};

const changePage = async (page: number) => {
    await loadTraces(page);
    await syncQueryToRouter(page);
};

const clearFilters = () => {
    const f: RequestFilterValues = {};
    for (const key of filterKeys) f[key] = '';
    f.sort_by = 'started_at';
    f.sort_dir = 'desc';
    filters.value = f;
};

const hasActiveFilters = () => clearableFilterKeys.some((key) => filters.value[key] !== '');

const toggleSort = (field: string) => {
    if (filters.value.sort_by === field) {
        filters.value.sort_dir = filters.value.sort_dir === 'desc' ? 'asc' : 'desc';
    } else {
        filters.value.sort_by = field;
        filters.value.sort_dir = 'desc';
    }
};

const sortIcon = (field: string) => {
    if (filters.value.sort_by !== field) return null;
    return filters.value.sort_dir === 'desc' ? 'down' : 'up';
};

const sortableColumns = [
    { field: 'requests', label: 'Calls' },
    { field: 'duration', label: 'Duration' },
    { field: 'cost', label: 'Cost' },
    { field: 'started_at', label: 'Started' },
];

const formatStartedAt = (iso: string | null) => {
    if (!iso) return '—';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return iso;
    return date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
};

const shortTraceId = (traceId: string) => String(traceId).slice(0, 8);

const trackableLabel = (trackable: SoleTrackable) => trackable.name || `${String(trackable.type).split('\\').pop()} #${trackable.id}`;

onMounted(async () => {
    applyQueryState();
    filters.value = filtersFromQuery();
    const initialPage = parseInt(route.query.page as string) || 1;
    await syncQueryToRouter(initialPage, true);
    await Promise.all([loadConfig(), loadProviders()]);
    await loadTraces(initialPage);
    isBootstrapping.value = false;
});

onUnmounted(() => {
    debouncedLoad.cancel();
});
</script>

<template>
    <div>
        <PageRangeHeader
            title="Traces"
            subtitle="The AI calls of one request, job or command, together"
            v-model:period="period"
            v-model:start-date="startDate"
            v-model:end-date="endDate"
            :loading="loading"
            @change="onRangeChange"
        />

        <div v-if="!migrated" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            Traces are built from the start time of each call, which is stored once the trace migrations run; until then calls are recorded without it and there is nothing to list here. Run <code class="font-mono">php artisan spectra:install</code> to publish and run them.
        </div>

        <!-- Totals over every matching trace -->
        <div class="mb-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <StatCard label="Traces" :loading="loading && !summary">
                <div class="stat-value text-2xl!">{{ formatNumber(summary?.trace_count ?? 0) }}</div>
                <template #footer>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        <span v-if="summary?.failed_trace_count" class="text-red-600 dark:text-red-400">{{ formatNumber(summary.failed_trace_count) }} with a failed call</span>
                        <span v-else>None with a failed call</span>
                    </div>
                </template>
            </StatCard>
            <StatCard label="Total cost" :loading="loading && !summary">
                <div class="stat-value text-2xl!"><Money :cents="summary?.total_cost_in_cents ?? 0" /></div>
                <template #footer><div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Successful calls only</div></template>
            </StatCard>
            <StatCard label="Cost per trace" :loading="loading && !summary">
                <div class="stat-value text-2xl!"><Money :cents="summary?.avg_cost_in_cents ?? 0" /></div>
                <template #footer><div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ summary?.avg_request_count ?? 0 }} calls on average</div></template>
            </StatCard>
            <StatCard label="Duration per trace" :loading="loading && !summary">
                <div class="stat-value text-2xl!">{{ formatDuration(summary?.avg_duration_ms ?? 0) }}</div>
                <template #footer><div class="mt-1 text-xs text-gray-500 dark:text-gray-400">First call start to last call end</div></template>
            </StatCard>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="px-4 py-3.5">
                <RequestFilters
                    v-model:filters="filters"
                    :providers="providers"
                    :model-types="modelTypes"
                    :available-tags="availableTags"
                    :available-finish-reasons="availableFinishReasons"
                    :available-reasoning-efforts="availableReasoningEfforts"
                    :available-trackable-types="availableTrackableTypes"
                    :sort-options="traceSortOptions"
                    :active-filter-keys="clearableFilterKeys"
                    :show-trace-badge="false"
                    search-placeholder="Search by origin or trace ID..."
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
                            <th>Trace</th>
                            <th>Models</th>
                            <th>Trackable</th>
                            <th
                                v-for="column in sortableColumns"
                                :key="column.field"
                                class="text-right cursor-pointer select-none hover:text-gray-900 dark:hover:text-white"
                                @click="toggleSort(column.field)"
                            >
                                <span class="inline-flex items-center gap-1">
                                    {{ column.label }}
                                    <svg v-if="sortIcon(column.field) === 'down'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                    <svg v-else-if="sortIcon(column.field) === 'up'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" /></svg>
                                </span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-if="loading">
                            <tr v-for="i in 10" :key="i" class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-5 py-4"><div class="h-4 w-48 animate-pulse rounded-sm bg-gray-200 dark:bg-gray-800"></div></td>
                                <td class="px-5 py-4"><div class="h-4 w-24 animate-pulse rounded-sm bg-gray-200 dark:bg-gray-800"></div></td>
                                <td class="px-5 py-4"><div class="h-4 w-20 animate-pulse rounded-sm bg-gray-200 dark:bg-gray-800"></div></td>
                                <td v-for="j in 4" :key="j" class="px-5 py-4"><div class="ml-auto h-4 w-14 animate-pulse rounded-sm bg-gray-200 dark:bg-gray-800"></div></td>
                            </tr>
                        </template>
                        <template v-else>
                            <tr
                                v-for="trace in traces"
                                :key="trace.trace_id"
                                class="group cursor-pointer"
                                @click="goToTrace(trace.trace_id)"
                            >
                                <td class="max-w-sm">
                                    <div class="truncate font-medium text-gray-900 dark:text-white" v-tooltip.truncated="trace.origin || trace.trace_id">
                                        <span v-if="trace.origin" class="font-mono text-[13px]">{{ trace.origin }}</span>
                                        <span v-else class="text-gray-500 dark:text-gray-400">Unnamed trace</span>
                                    </div>
                                    <div class="mt-0.5 font-mono text-[11px] text-gray-400 dark:text-gray-500" v-tooltip="trace.trace_id">{{ shortTraceId(trace.trace_id) }}</div>
                                </td>
                                <td>
                                    <div class="flex items-center gap-1.5">
                                        <ProviderLogo
                                            v-for="model in trace.models.slice(0, 3)"
                                            :key="model.provider + model.model"
                                            :provider="model.provider"
                                            v-tooltip="model.model"
                                            class="inline-flex h-4 w-4 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full"
                                        />
                                        <span class="truncate text-sm text-gray-600 dark:text-gray-300" v-tooltip.truncated="trace.models.map((model) => model.model).join(', ')">
                                            {{ trace.models[0]?.model }}<template v-if="trace.models.length > 1"> +{{ trace.models.length - 1 }}</template>
                                        </span>
                                    </div>
                                </td>
                                <td class="max-w-48 truncate text-sm text-gray-600 dark:text-gray-300">
                                    <template v-if="trace.trackable">{{ trackableLabel(trace.trackable) }}</template>
                                    <span v-else class="text-gray-400 dark:text-gray-500">&mdash;</span>
                                </td>
                                <td class="text-right tabular-nums">
                                    {{ formatNumber(trace.request_count) }}
                                    <span v-if="trace.failed_count" class="badge badge-danger ml-1 px-1.5! py-0! text-[11px]">{{ trace.failed_count }} failed</span>
                                </td>
                                <td class="text-right tabular-nums">{{ formatDuration(trace.duration_ms) }}</td>
                                <td class="text-right font-medium tabular-nums"><Money :cents="trace.total_cost_in_cents" /></td>
                                <td class="whitespace-nowrap text-right">
                                    <span class="transition-colors group-hover:text-primary-600 dark:group-hover:text-primary-400">{{ formatStartedAt(trace.started_at) }}</span>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!loading && !traces.length">
                            <td colspan="7">
                                <div class="empty-state py-16">
                                    <svg class="empty-state-icon mx-auto h-16 w-16" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" /></svg>
                                    <p class="empty-state-title text-base">No traces found</p>
                                    <p v-if="hasActiveFilters()" class="empty-state-description">
                                        Try adjusting your filters or
                                        <button class="text-primary-600 hover:underline dark:text-primary-400" @click="clearFilters">clear all filters</button>
                                    </p>
                                    <p v-else class="empty-state-description">
                                        Every AI call of one HTTP request, queued job or Artisan command shares a trace.
                                        Traces need <code class="font-mono text-xs">SPECTRA_TRACE_PER_REQUEST</code> on (the default).
                                    </p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="card-body border-t border-gray-100 dark:border-gray-800">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        <span v-if="loading" class="inline-block h-4 w-48 animate-pulse rounded-sm bg-gray-200 dark:bg-gray-800"></span>
                        <span v-else>
                            Showing page <span class="font-medium text-gray-700 dark:text-gray-300">{{ pagination.current_page }}</span>
                            of <span class="font-medium text-gray-700 dark:text-gray-300">{{ pagination.last_page }}</span>
                            <span class="mx-1 text-gray-400 dark:text-gray-500">&middot;</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ formatNumber(pagination.total) }}</span> traces
                        </span>
                    </div>
                    <div class="flex gap-2">
                        <button
                            :disabled="pagination.current_page <= 1 || loading"
                            class="btn btn-secondary"
                            @click="changePage(pagination.current_page - 1)"
                        >
                            <svg class="mr-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                            Previous
                        </button>
                        <button
                            :disabled="pagination.current_page >= pagination.last_page || loading"
                            class="btn btn-secondary"
                            @click="changePage(pagination.current_page + 1)"
                        >
                            Next
                            <svg class="ml-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
