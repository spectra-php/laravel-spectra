<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { useLoading } from '@/composables/useLoading';
import Breadcrumb from '@/components/Breadcrumb.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import Skeleton from '@/components/Skeleton.vue';
import Money from '@/components/Money.vue';
import ScoresCard from '@/components/ScoresCard.vue';
import type { ConversationDetailsResponse } from '@/types/api';
import type { ApiError } from '@/types/app';

const route = useRoute();
const { formatNumber, formatDuration, apiRequest } = useHelpers();
const loading = useLoading();

const conversation = ref<ConversationDetailsResponse | null>(null);
const notFound = ref(false);
loading.value = true;

const loadConversation = async () => {
    loading.value = true;
    notFound.value = false;
    try {
        conversation.value = await apiRequest<ConversationDetailsResponse>('get', '/conversations/' + encodeURIComponent(route.params.conversationId as string));
    } catch (error) {
        conversation.value = null;
        notFound.value = (error as ApiError)?.response?.status === 404;
        if (!notFound.value) console.error('Failed to load conversation:', error);
    } finally {
        loading.value = false;
    }
};

onMounted(loadConversation);

watch(() => route.params.conversationId, (conversationId, previous) => {
    if (conversationId && conversationId !== previous) loadConversation();
});

const breadcrumbs = computed(() => [
    { label: 'Conversations', to: { name: 'conversations' } },
    { label: conversation.value?.conversation_id || 'Conversation' },
]);

const formatDateTime = (iso: string | null) => {
    if (!iso) return null;
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? iso : date.toLocaleString();
};

const formatTime = (iso: string | null) => {
    if (!iso) return '';
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? iso : date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
};

const trackableRoute = computed(() => {
    const trackable = conversation.value?.trackable;
    if (!trackable) return null;
    return { name: 'trackable-detail', params: { id: trackable.id }, query: { type: trackable.type } };
});

const trackableLabel = computed(() => {
    const trackable = conversation.value?.trackable;
    if (!trackable) return '';
    return trackable.name || `${String(trackable.type).split('\\').pop()} #${trackable.id}`;
});

const missingPrompt = computed(() => conversation.value?.stores_prompts ? 'No user message in this turn' : 'Prompts are not stored (spectra.storage.store_prompts)');
const missingReply = computed(() => conversation.value?.stores_responses ? 'No reply text in this turn' : 'Responses are not stored (spectra.storage.store_responses)');
</script>

<template>
    <div>
        <Breadcrumb :items="breadcrumbs" />

        <!-- Loading -->
        <div v-if="loading">
            <div class="mb-6 h-8 w-72 animate-pulse rounded-lg bg-gray-200 dark:bg-gray-800"></div>
            <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div v-for="i in 4" :key="i" class="stat-card">
                    <div class="mb-3 h-3 w-16 animate-pulse rounded-sm bg-gray-200 dark:bg-gray-800"></div>
                    <div class="h-7 w-28 animate-pulse rounded-sm bg-gray-200 dark:bg-gray-800"></div>
                </div>
            </div>
            <div class="card"><div class="card-body"><Skeleton variant="chart" height="240px" /></div></div>
        </div>

        <div v-else-if="notFound" class="card">
            <div class="empty-state py-16">
                <p class="empty-state-title text-base">Conversation not found</p>
                <p class="empty-state-description">No calls have the conversation id <span class="font-mono text-xs">{{ route.params.conversationId }}</span>.</p>
            </div>
        </div>

        <div v-else-if="conversation && conversation.migrated === false" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            A transcript is built from the start time of each call, which is stored once the trace migrations run. Run <code class="font-mono">php artisan spectra:install</code> to publish and run them.
        </div>

        <div v-else-if="conversation">
            <!-- Header -->
            <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                <div class="min-w-0">
                    <h1 class="truncate font-mono text-xl font-semibold tracking-tight text-gray-900 dark:text-white" v-tooltip.truncated="conversation.conversation_id">{{ conversation.conversation_id }}</h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Started {{ formatDateTime(conversation.started_at) }}
                        <template v-if="conversation.last_activity_at !== conversation.started_at"> · last active {{ formatDateTime(conversation.last_activity_at) }}</template>
                    </p>
                </div>
                <router-link
                    :to="{ name: 'requests', query: { conversation_id: conversation.conversation_id, period: 'all' } }"
                    class="btn btn-secondary self-start"
                >View calls in Requests</router-link>
            </div>

            <!-- Summary -->
            <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="stat-card">
                    <div class="stat-label">Cost</div>
                    <div class="stat-value text-2xl!"><Money :cents="conversation.total_cost_in_cents" /></div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Successful calls only</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Turns</div>
                    <div class="stat-value text-2xl!">{{ formatNumber(conversation.turn_count) }}</div>
                    <div class="mt-1 text-xs" :class="conversation.failed_count ? 'text-red-600 dark:text-red-400' : 'text-gray-500 dark:text-gray-400'">
                        {{ formatNumber(conversation.request_count) }} calls<template v-if="conversation.failed_count"> · {{ formatNumber(conversation.failed_count) }} failed</template>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Tokens</div>
                    <div class="stat-value text-2xl!">{{ formatNumber(conversation.total_tokens) }}</div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Input and output</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Models</div>
                    <div class="stat-value text-2xl!">{{ conversation.models.length }}</div>
                    <div class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400" v-tooltip.truncated="conversation.models.map((model) => model.model).join(', ')">{{ conversation.models.map((model) => model.model).join(', ') }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Transcript -->
                <div class="min-w-0 space-y-4 lg:col-span-2">
                    <div v-for="(turn, index) in conversation.turns" :key="index" class="card overflow-hidden">
                        <div class="card-header">
                            <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                                <h3 class="whitespace-nowrap">{{ turn.turn !== null ? `Turn ${turn.turn}` : `Call ${index + 1}` }}</h3>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ formatTime(turn.started_at) }} · {{ formatDuration(turn.duration_ms) }}</span>
                                <span v-if="turn.failed_count" class="badge badge-danger px-1.5! py-0! text-[11px]">{{ turn.failed_count }} failed</span>
                            </div>
                            <div class="shrink-0 text-right text-xs tabular-nums text-gray-500 dark:text-gray-400">
                                <span class="font-medium text-gray-900 dark:text-white"><Money :cents="turn.total_cost_in_cents" /></span>
                                <span class="ml-1">· <Money :cents="turn.running_cost_in_cents" /> so far</span>
                            </div>
                        </div>

                        <div class="space-y-3 px-5 py-4">
                            <!-- The user's message -->
                            <div class="flex justify-end">
                                <div
                                    v-if="turn.prompt"
                                    class="max-w-[85%] whitespace-pre-wrap wrap-break-word rounded-2xl rounded-br-md bg-primary-600 px-4 py-2.5 text-sm text-white dark:bg-primary-500"
                                >{{ turn.prompt }}</div>
                                <div v-else class="text-xs italic text-gray-400 dark:text-gray-500">{{ missingPrompt }}</div>
                            </div>

                            <!-- The model's reply -->
                            <div class="flex justify-start">
                                <div
                                    v-if="turn.reply"
                                    class="max-w-[85%] whitespace-pre-wrap wrap-break-word rounded-2xl rounded-bl-md bg-gray-100 px-4 py-2.5 text-sm text-gray-800 dark:bg-white/6 dark:text-gray-100"
                                >{{ turn.reply }}</div>
                                <div v-else class="text-xs italic text-gray-400 dark:text-gray-500">{{ turn.failed_count === turn.request_count ? 'The call failed' : missingReply }}</div>
                            </div>
                        </div>

                        <!-- The calls this turn made -->
                        <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 px-5 py-2.5 dark:border-gray-800">
                            <router-link
                                v-for="call in turn.calls"
                                :key="call.id"
                                :to="{ name: 'request-preview', params: { id: call.id } }"
                                class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs transition-colors"
                                :class="call.is_error
                                    ? 'border-red-200 text-red-700 hover:bg-red-50 dark:border-red-900/60 dark:text-red-300 dark:hover:bg-red-950/30'
                                    : 'border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-800 dark:text-gray-300 dark:hover:bg-white/4'"
                                v-tooltip="`${call.model} · ${formatNumber(call.total_tokens)} tokens`"
                            >
                                <ProviderLogo
                                    :provider="call.provider"
                                    class="inline-flex h-3.5 w-3.5 shrink-0 items-center [&>svg]:h-full [&>svg]:w-full"
                                />
                                <span class="max-w-40 truncate">{{ call.model }}</span>
                                <span class="tabular-nums text-gray-400 dark:text-gray-500">{{ formatDuration(call.latency_ms) }}</span>
                            </router-link>
                            <router-link
                                v-if="turn.trace_id"
                                :to="{ name: 'trace-detail', params: { traceId: turn.trace_id } }"
                                class="ml-auto text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                            >Open trace</router-link>
                        </div>
                    </div>

                    <div v-if="conversation.truncated" class="text-xs text-gray-500 dark:text-gray-400">
                        Showing the first {{ formatNumber(conversation.turns.reduce((sum, turn) => sum + turn.request_count, 0)) }} of {{ formatNumber(conversation.request_count) }} calls.
                        <router-link :to="{ name: 'requests', query: { conversation_id: conversation.conversation_id, period: 'all' } }" class="font-medium text-primary-600 hover:underline dark:text-primary-400">See all</router-link>
                    </div>
                </div>

                <div class="space-y-6">
                    <!-- Models -->
                    <div class="card overflow-hidden">
                        <div class="card-header"><h3>Models</h3></div>
                        <ul class="divide-y divide-gray-100 dark:divide-gray-800/80">
                            <li v-for="model in conversation.models" :key="model.provider + model.model" class="flex items-center gap-3 px-5 py-3">
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

                    <ScoresCard v-if="conversation.scores?.length" :scores="conversation.scores" />

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
                            <div>
                                <dt class="text-xs text-gray-500 dark:text-gray-400">Tags</dt>
                                <dd class="mt-1 flex flex-wrap gap-1">
                                    <span v-for="tag in conversation.tags" :key="tag" class="badge badge-secondary">#{{ tag }}</span>
                                    <span v-if="!conversation.tags.length" class="text-gray-400 dark:text-gray-500">None</span>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
