<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { useLoading } from '@/composables/useLoading';
import Breadcrumb from '@/components/Breadcrumb.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import Skeleton from '@/components/Skeleton.vue';
import TraceWaterfall from '@/components/TraceWaterfall.vue';
import Money from '@/components/Money.vue';
import ScoresCard from '@/components/ScoresCard.vue';

const route = useRoute();
const { formatNumber, formatDuration, apiRequest } = useHelpers();
const loading = useLoading();

const trace = ref(null);
const notFound = ref(false);
const copied = ref(false);
loading.value = true;

const loadTrace = async () => {
    loading.value = true;
    notFound.value = false;
    try {
        trace.value = await apiRequest('get', '/traces/' + encodeURIComponent(route.params.traceId));
    } catch (error) {
        trace.value = null;
        notFound.value = error?.response?.status === 404;
        if (!notFound.value) console.error('Failed to load trace:', error);
    } finally {
        loading.value = false;
    }
};

onMounted(loadTrace);

// Put this trace in a review queue, when the app has queues and the user may review.
const reviewQueues = ref([]);
const queuedIn = ref('');
const queueError = ref('');
onMounted(async () => {
    try {
        const data = await apiRequest('get', '/annotations');
        reviewQueues.value = data.can_annotate && data.migrated ? data.queues : [];
    } catch {
        reviewQueues.value = [];
    }
});
const queueForReview = async (event) => {
    const queue = event.target.value;
    event.target.value = '';
    if (!queue) return;
    queueError.value = '';
    try {
        await apiRequest('post', `/annotations/${encodeURIComponent(queue)}/items`, { trace_id: route.params.traceId });
        queuedIn.value = queue;
    } catch {
        queueError.value = 'Could not add it to the queue.';
    }
};

watch(() => route.params.traceId, (traceId, previous) => {
    if (traceId && traceId !== previous) loadTrace();
});

const title = computed(() => trace.value?.origin || 'Unnamed trace');

const breadcrumbs = computed(() => [
    { label: 'Traces', to: { name: 'traces' } },
    { label: trace.value ? title.value : 'Trace' },
]);

const startedAt = computed(() => {
    if (!trace.value?.started_at) return null;
    const date = new Date(trace.value.started_at);
    return Number.isNaN(date.getTime()) ? trace.value.started_at : date.toLocaleString();
});

// Origins in the order the trace reached them: the request, then the jobs it dispatched.
const origins = computed(() => [...new Set((trace.value?.requests || []).map((step) => step.origin).filter(Boolean))]);

const successfulCount = computed(() => (trace.value?.request_count ?? 0) - (trace.value?.failed_count ?? 0));

const trackableRoute = computed(() => {
    const trackable = trace.value?.trackable;
    if (!trackable) return null;
    return { name: 'trackable-detail', params: { id: trackable.id }, query: { type: trackable.type } };
});

const trackableLabel = computed(() => {
    const trackable = trace.value?.trackable;
    if (!trackable) return '';
    return trackable.name || `${String(trackable.type).split('\\').pop()} #${trackable.id}`;
});

const copyTraceId = async () => {
    try {
        await navigator.clipboard.writeText(trace.value.trace_id);
        copied.value = true;
        setTimeout(() => copied.value = false, 2000);
    } catch {
        // Clipboard can be blocked outside secure contexts; the id stays selectable.
    }
};
</script>

<template>
    <div>
        <Breadcrumb :items="breadcrumbs" />

        <!-- Loading -->
        <div v-if="loading">
            <div class="mb-6 h-8 w-72 animate-pulse rounded-lg bg-gray-200 dark:bg-gray-800"></div>
            <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div v-for="i in 4" :key="i" class="stat-card">
                    <div class="mb-3 h-3 w-16 animate-pulse rounded bg-gray-200 dark:bg-gray-800"></div>
                    <div class="h-7 w-28 animate-pulse rounded bg-gray-200 dark:bg-gray-800"></div>
                </div>
            </div>
            <div class="card"><div class="card-body"><Skeleton variant="chart" height="200px" /></div></div>
        </div>

        <div v-else-if="notFound" class="card">
            <div class="empty-state py-16">
                <p class="empty-state-title text-base">Trace not found</p>
                <p class="empty-state-description">No calls have the trace id <span class="font-mono text-xs">{{ route.params.traceId }}</span>.</p>
            </div>
        </div>

        <div v-else-if="trace">
            <!-- Header -->
            <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                <div class="min-w-0">
                    <h1 class="truncate font-mono text-xl font-semibold tracking-tight text-gray-900 dark:text-white" v-tooltip.truncated="title">{{ title }}</h1>
                    <p class="mt-1 flex flex-wrap items-center gap-x-2 text-sm text-gray-500 dark:text-gray-400">
                        <button class="font-mono text-xs hover:text-gray-900 dark:hover:text-white" v-tooltip="copied ? 'Copied' : 'Copy trace id'" @click="copyTraceId">
                            {{ trace.trace_id }}<span v-if="copied" class="ml-1 font-sans text-primary-600 dark:text-primary-400">copied</span>
                        </button>
                        <template v-if="startedAt"><span aria-hidden="true">·</span> {{ startedAt }}</template>
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2 self-start">
                    <span v-if="queuedIn" class="text-xs text-gray-500 dark:text-gray-400">In <router-link :to="{ name: 'review-queue', params: { queue: queuedIn } }" class="font-mono text-primary-600 hover:underline dark:text-primary-400">{{ queuedIn }}</router-link></span>
                    <span v-if="queueError" class="text-xs text-red-600 dark:text-red-400">{{ queueError }}</span>
                    <select v-if="reviewQueues.length" class="form-select form-select-sm" aria-label="Add to a review queue" @change="queueForReview">
                        <option value="">Add to review…</option>
                        <option v-for="queue in reviewQueues" :key="queue.name" :value="queue.name">{{ queue.name }}</option>
                    </select>
                    <router-link
                        :to="{ name: 'requests', query: { trace_id: trace.trace_id, period: 'all' } }"
                        class="btn btn-secondary"
                    >View calls in Requests</router-link>
                </div>
            </div>

            <!-- Summary -->
            <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="stat-card">
                    <div class="stat-label">Cost</div>
                    <div class="stat-value !text-2xl"><Money :cents="trace.total_cost_in_cents" /></div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Successful calls only</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Duration</div>
                    <div class="stat-value !text-2xl">{{ formatDuration(trace.duration_ms) }}</div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">First call start to last call end</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Calls</div>
                    <div class="stat-value !text-2xl">{{ formatNumber(trace.request_count) }}</div>
                    <div class="mt-1 text-xs" :class="trace.failed_count ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400'">
                        <template v-if="trace.failed_count">{{ formatNumber(trace.failed_count) }} failed · {{ formatNumber(successfulCount) }} succeeded</template>
                        <template v-else>All succeeded</template>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Tokens</div>
                    <div class="stat-value !text-2xl">{{ formatNumber(trace.total_tokens) }}</div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ trace.models.length }} {{ trace.models.length === 1 ? 'model' : 'models' }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Timeline -->
                <div class="min-w-0 lg:col-span-2">
                    <div class="card overflow-hidden">
                        <div class="card-header">
                            <h3>Timeline</h3>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                {{ trace.spans?.length ? `${trace.spans.length} ${trace.spans.length === 1 ? 'span' : 'spans'} · ${trace.request_count} calls` : (origins.length > 1 ? `${origins.length} origins` : 'In the order the calls started') }}
                            </span>
                        </div>
                        <TraceWaterfall :requests="trace.requests" :spans="trace.spans || []" :duration-ms="trace.timeline_ms || trace.duration_ms" :show-origins="origins.length > 0" />
                        <div v-if="trace.truncated" class="border-t border-gray-100 px-5 py-2.5 text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
                            Showing the first {{ trace.requests.length }} of {{ formatNumber(trace.request_count) }} calls.
                            <router-link :to="{ name: 'requests', query: { trace_id: trace.trace_id, period: 'all' } }" class="font-medium text-primary-600 hover:underline dark:text-primary-400">See all</router-link>
                        </div>
                    </div>
                </div>

                <div class="space-y-6">
                    <!-- Models -->
                    <div class="card overflow-hidden">
                        <div class="card-header"><h3>Models</h3></div>
                        <ul class="divide-y divide-gray-100 dark:divide-gray-800/80">
                            <li v-for="model in trace.models" :key="model.provider + model.model" class="flex items-center gap-3 px-5 py-3">
                                <ProviderLogo
                                    :provider="model.provider"
                                    v-tooltip="model.provider_display_name || model.provider"
                                    class="inline-flex h-4 w-4 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full"
                                />
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium text-gray-900 dark:text-white" v-tooltip.truncated="model.model">{{ model.model }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ formatNumber(model.request_count) }} {{ model.request_count === 1 ? 'call' : 'calls' }} · {{ formatNumber(model.total_tokens) }} tokens
                                    </div>
                                </div>
                                <div class="text-sm font-medium tabular-nums text-gray-700 dark:text-gray-200"><Money :cents="model.total_cost_in_cents" /></div>
                            </li>
                        </ul>
                    </div>

                    <ScoresCard v-if="trace.scores?.length" :scores="trace.scores" />

                    <!-- Context -->
                    <div class="card">
                        <div class="card-header"><h3>Context</h3></div>
                        <dl class="card-body space-y-3 text-sm">
                            <div>
                                <dt class="text-xs text-gray-500 dark:text-gray-400">Trackable</dt>
                                <dd class="mt-0.5">
                                    <router-link v-if="trackableRoute" :to="trackableRoute" class="text-primary-600 hover:underline dark:text-primary-400">{{ trackableLabel }}</router-link>
                                    <span v-else class="text-gray-400 dark:text-gray-500">None, or more than one</span>
                                </dd>
                            </div>
                            <div v-if="origins.length">
                                <dt class="text-xs text-gray-500 dark:text-gray-400">Origins</dt>
                                <dd class="mt-0.5 space-y-0.5">
                                    <div v-for="origin in origins" :key="origin" class="truncate font-mono text-xs text-gray-700 dark:text-gray-300" v-tooltip.truncated="origin">{{ origin }}</div>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500 dark:text-gray-400">Tags</dt>
                                <dd class="mt-1 flex flex-wrap gap-1">
                                    <span v-for="tag in trace.tags" :key="tag" class="badge badge-secondary">#{{ tag }}</span>
                                    <span v-if="!trace.tags.length" class="text-gray-400 dark:text-gray-500">None</span>
                                </dd>
                            </div>
                            <div v-if="trace.parent_span_id">
                                <dt class="text-xs text-gray-500 dark:text-gray-400">Parent span</dt>
                                <dd class="mt-0.5 font-mono text-xs text-gray-700 dark:text-gray-300" v-tooltip="'From the incoming traceparent header'">{{ trace.parent_span_id }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
