<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { usePeriodQuery } from '@/composables/usePeriodQuery';
import { useProviders } from '@/composables/useProviders';
import { useLoading } from '@/composables/useLoading';
import { debounce } from 'lodash';
import RequestFilters from '@/components/RequestFilters.vue';
import PageRangeHeader from '@/components/PageRangeHeader.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import StatCard from '@/components/StatCard.vue';
import Money from '@/components/Money.vue';

const router = useRouter();
const route = useRoute();
const { formatNumber, formatDuration, apiRequest } = useHelpers();
const { providers, loadProviders } = useProviders();
const loading = useLoading();

const conversations = ref([]);
loading.value = true;
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const summary = ref(null);
const availableTags = ref([]);
const availableFinishReasons = ref([]);
const availableReasoningEfforts = ref([]);
const modelTypes = ref([]);
const isBootstrapping = ref(true);

const filterKeys = ['search', 'provider', 'model', 'status', 'tag', 'model_type', 'finish_reason', 'has_tool_calls', 'is_reasoning', 'reasoning_effort', 'trackable_type', 'trackable_id', 'sort_by', 'sort_dir'];
const clearableFilterKeys = filterKeys.filter((key) => !['sort_by', 'sort_dir'].includes(key));
const conversationSortOptions = [
    { value: 'last_activity', label: 'Sort by Last Activity' },
    { value: 'started_at', label: 'Sort by Start' },
    { value: 'cost', label: 'Sort by Cost' },
    { value: 'turns', label: 'Sort by Turns' },
    { value: 'failed', label: 'Sort by Failures' },
];

const filtersFromQuery = () => {
    const q = route.query;
    const f = {};
    const qs = (value) => Array.isArray(value) ? value[0] : value;
    for (const key of filterKeys) {
        const value = qs(q[key]);
        f[key] = typeof value === 'string' ? value : '';
    }
    f.sort_by = qs(q.sort_by) || 'last_activity';
    f.sort_dir = qs(q.sort_dir) || 'desc';
    return f;
};

const filters = ref(filtersFromQuery());

const { period, startDate, endDate, applyQueryState, buildQueryParams, syncQueryToRouter } = usePeriodQuery({
    filterKeys,
    getFilters: () => filters.value,
});

const loadConversations = async (page = 1) => {
    loading.value = true;
    try {
        const params = buildQueryParams(page);
        const data = await apiRequest('get', '/conversations?' + params.toString());
        conversations.value = data.data;
        summary.value = data.summary;
        pagination.value = {
            current_page: data.current_page,
            last_page: data.last_page,
            total: data.total,
        };
        availableTags.value = data.available_tags || [];
        availableFinishReasons.value = data.available_finish_reasons || [];
        availableReasoningEfforts.value = data.available_reasoning_efforts || [];
    } catch (error) {
        console.error('Failed to load conversations:', error);
    } finally {
        loading.value = false;
    }
};

const debouncedLoad = debounce(() => loadConversations(1), 300);

watch(filters, () => {
    if (isBootstrapping.value) return;
    void syncQueryToRouter(1);
    debouncedLoad();
}, { deep: true });

const onRangeChange = async () => {
    await syncQueryToRouter(1);
    await loadConversations(1);
};

const loadConfig = async () => {
    try {
        const data = await apiRequest('get', '/config');
        modelTypes.value = data.model_types || [];
    } catch (error) {
        // The type filter just stays empty.
    }
};

const goToConversation = (conversationId) => {
    router.push({ name: 'conversation-detail', params: { conversationId } });
};

const changePage = async (page) => {
    await loadConversations(page);
    await syncQueryToRouter(page);
};

const clearFilters = () => {
    const f = {};
    for (const key of filterKeys) f[key] = '';
    f.sort_by = 'last_activity';
    f.sort_dir = 'desc';
    filters.value = f;
};

const hasActiveFilters = () => clearableFilterKeys.some((key) => filters.value[key] !== '');

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

const sortableColumns = [
    { field: 'turns', label: 'Turns' },
    { field: 'cost', label: 'Cost' },
    { field: 'last_activity', label: 'Last activity' },
];

const formatDateTime = (iso) => {
    if (!iso) return '—';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return iso;
    return date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
};

const trackableLabel = (trackable) => trackable.name || `${String(trackable.type).split('\\').pop()} #${trackable.id}`;

onMounted(async () => {
    applyQueryState();
    filters.value = filtersFromQuery();
    const initialPage = parseInt(route.query.page) || 1;
    await syncQueryToRouter(initialPage, true);
    await Promise.all([loadConfig(), loadProviders()]);
    await loadConversations(initialPage);
    isBootstrapping.value = false;
});

onUnmounted(() => {
    debouncedLoad.cancel();
});
</script>

<template>
    <div>
        <PageRangeHeader
            title="Conversations"
            subtitle="Multi-turn chats, turn by turn"
            v-model:period="period"
            v-model:start-date="startDate"
            v-model:end-date="endDate"
            :loading="loading"
            @change="onRangeChange"
        />

        <!-- Totals over every matching conversation -->
        <div class="mb-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <StatCard label="Conversations" :loading="loading && !summary">
                <div class="stat-value !text-2xl">{{ formatNumber(summary?.conversation_count ?? 0) }}</div>
                <template #footer>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        <span v-if="summary?.failed_conversation_count" class="text-red-600 dark:text-red-400">{{ formatNumber(summary.failed_conversation_count) }} with a failed call</span>
                        <span v-else>None with a failed call</span>
                    </div>
                </template>
            </StatCard>
            <StatCard label="Total cost" :loading="loading && !summary">
                <div class="stat-value !text-2xl"><Money :cents="summary?.total_cost_in_cents ?? 0" /></div>
                <template #footer><div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Successful calls only</div></template>
            </StatCard>
            <StatCard label="Cost per conversation" :loading="loading && !summary">
                <div class="stat-value !text-2xl"><Money :cents="summary?.avg_cost_in_cents ?? 0" /></div>
                <template #footer><div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Across its turns</div></template>
            </StatCard>
            <StatCard label="Turns per conversation" :loading="loading && !summary">
                <div class="stat-value !text-2xl">{{ summary?.avg_turn_count ?? 0 }}</div>
                <template #footer><div class="mt-1 text-xs text-gray-500 dark:text-gray-400">On average</div></template>
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
                    :sort-options="conversationSortOptions"
                    :active-filter-keys="clearableFilterKeys"
                    :show-trace-badge="false"
                    search-placeholder="Search by conversation ID..."
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
                            <th>Conversation</th>
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
                                <td class="px-5 py-4"><div class="h-4 w-40 animate-pulse rounded bg-gray-200 dark:bg-gray-800"></div></td>
                                <td class="px-5 py-4"><div class="h-4 w-24 animate-pulse rounded bg-gray-200 dark:bg-gray-800"></div></td>
                                <td class="px-5 py-4"><div class="h-4 w-20 animate-pulse rounded bg-gray-200 dark:bg-gray-800"></div></td>
                                <td v-for="j in 3" :key="j" class="px-5 py-4"><div class="ml-auto h-4 w-14 animate-pulse rounded bg-gray-200 dark:bg-gray-800"></div></td>
                            </tr>
                        </template>
                        <template v-else>
                            <tr
                                v-for="conversation in conversations"
                                :key="conversation.conversation_id"
                                class="group cursor-pointer"
                                @click="goToConversation(conversation.conversation_id)"
                            >
                                <td class="max-w-sm">
                                    <div class="truncate font-mono text-[13px] font-medium text-gray-900 dark:text-white" v-tooltip.truncated="conversation.conversation_id">{{ conversation.conversation_id }}</div>
                                    <div class="mt-0.5 text-[11px] text-gray-400 dark:text-gray-500">Started {{ formatDateTime(conversation.started_at) }}</div>
                                </td>
                                <td>
                                    <div class="flex items-center gap-1.5">
                                        <ProviderLogo
                                            v-for="model in conversation.models.slice(0, 3)"
                                            :key="model.provider + model.model"
                                            :provider="model.provider"
                                            v-tooltip="model.model"
                                            class="inline-flex h-4 w-4 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full"
                                        />
                                        <span class="truncate text-sm text-gray-600 dark:text-gray-300" v-tooltip.truncated="conversation.models.map((model) => model.model).join(', ')">
                                            {{ conversation.models[0]?.model }}<template v-if="conversation.models.length > 1"> +{{ conversation.models.length - 1 }}</template>
                                        </span>
                                    </div>
                                </td>
                                <td class="max-w-[12rem] truncate text-sm text-gray-600 dark:text-gray-300">
                                    <template v-if="conversation.trackable">{{ trackableLabel(conversation.trackable) }}</template>
                                    <span v-else class="text-gray-400 dark:text-gray-500">&mdash;</span>
                                </td>
                                <td class="text-right tabular-nums">
                                    {{ formatNumber(conversation.turn_count) }}
                                    <span v-if="conversation.failed_count" class="badge badge-danger ml-1 !px-1.5 !py-0 text-[11px]">{{ conversation.failed_count }} failed</span>
                                </td>
                                <td class="text-right font-medium tabular-nums"><Money :cents="conversation.total_cost_in_cents" /></td>
                                <td class="whitespace-nowrap text-right">
                                    <span class="transition-colors group-hover:text-primary-600 dark:group-hover:text-primary-400">{{ formatDateTime(conversation.last_activity_at) }}</span>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!loading && !conversations.length">
                            <td colspan="6">
                                <div class="empty-state py-16">
                                    <svg class="empty-state-icon mx-auto h-16 w-16" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" /></svg>
                                    <p class="empty-state-title text-base">No conversations found</p>
                                    <p v-if="hasActiveFilters()" class="empty-state-description">
                                        Try adjusting your filters or
                                        <button class="text-primary-600 hover:underline dark:text-primary-400" @click="clearFilters">clear all filters</button>
                                    </p>
                                    <p v-else class="empty-state-description">
                                        Link the calls of a chat with <code class="font-mono text-xs">$ctx-&gt;inConversation($chatId, $turn)</code> to see them here.
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
                        <span v-if="loading" class="inline-block h-4 w-48 animate-pulse rounded bg-gray-200 dark:bg-gray-800"></span>
                        <span v-else>
                            Showing page <span class="font-medium text-gray-700 dark:text-gray-300">{{ pagination.current_page }}</span>
                            of <span class="font-medium text-gray-700 dark:text-gray-300">{{ pagination.last_page }}</span>
                            <span class="mx-1 text-gray-400 dark:text-gray-500">&middot;</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ formatNumber(pagination.total) }}</span> conversations
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
