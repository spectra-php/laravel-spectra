<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue';
import type { Component } from 'vue';
import { useRoute } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { useLoading } from '@/composables/useLoading';
import DocsLink from '@/components/DocsLink.vue';
import JsonViewer from '@/components/JsonViewer.vue';
import CodeBlock from '@/components/CodeBlock.vue';
import Skeleton from '@/components/Skeleton.vue';
import StatusCode from '@/components/StatusCode.vue';
import ExpiryCountdown from '@/components/ExpiryCountdown.vue';
import TokenMetrics from './previews/TokenMetrics.vue';
import ImageMetrics from './previews/ImageMetrics.vue';
import TtsMetrics from './previews/TtsMetrics.vue';
import SttMetrics from './previews/SttMetrics.vue';
import VideoMetrics from './previews/VideoMetrics.vue';
import TraceTimeline from './previews/TraceTimeline.vue';
import Breadcrumb from '@/components/Breadcrumb.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import Money from '@/components/Money.vue';
import ScoresCard from '@/components/ScoresCard.vue';
import AddToDataset from '@/components/AddToDataset.vue';
import type { RequestDetailsResponse } from '@/types/api';

const route = useRoute();
const { spectra, formatNumber, formatDuration, apiRequest } = useHelpers();
const loading = useLoading();

const request = ref<RequestDetailsResponse | null>(null);
loading.value = true;
const activeTab = ref('response');
const metricsExpanded = ref(true);
const payloadExpanded = ref(true);

const loadRequest = async () => {
    try {
        request.value = await apiRequest<RequestDetailsResponse>('get', '/requests/' + route.params.id);
    } catch (error) {
        console.error('Failed to load request:', error);
    } finally {
        loading.value = false;
    }
};

const modelType = computed(() => request.value?.model_type || 'text');

const modelTypeLabel = computed(() => {
    const labels: Record<string, string> = { text: 'Text', embedding: 'Embedding', image: 'Image', video: 'Video', tts: 'Text-to-Speech', stt: 'Speech-to-Text' };
    return labels[modelType.value] || modelType.value;
});

const metricsComponent = computed(() => {
    const map: Record<string, Component> = { text: TokenMetrics, embedding: TokenMetrics, image: ImageMetrics, tts: TtsMetrics, stt: SttMetrics, video: VideoMetrics };
    return map[modelType.value] || TokenMetrics;
});

const copiedField = ref<string | null>(null);
const copyToClipboard = async (text: string, field: string) => {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
    }
    copiedField.value = field;
    setTimeout(() => copiedField.value = null, 2000);
};

// The same rule as the API: no 2xx status, or an error on a 2xx answer.
const isFailed = computed(() => !request.value?.status_code || request.value.status_code < 200 || request.value.status_code >= 300 || !!request.value.error_type);

const finishReasonClass = computed(() => {
    const reason = request.value?.finish_reason;
    if (['length', 'max_tokens', 'max_output_tokens', 'incomplete'].includes(reason as string)) return 'badge-warning';
    if (['stop', 'end_turn', 'STOP', 'completed'].includes(reason as string)) return 'badge-success';
    if (['refusal', 'content_filter', 'failed', 'SAFETY'].includes(reason as string)) return 'badge-danger';
    return 'badge-secondary';
});

const formatToolName = (type: string) => String(type).replace(/_/g, ' ');

const breadcrumbs = computed(() => [
    { label: 'Requests', to: { name: 'requests' } },
    { label: request.value?.model || `Request #${route.params.id}` },
]);

onMounted(() => {
    loadRequest();
});

// Moving between calls of a trace reuses this page, so load the new call in place.
watch(() => route.params.id, (id, previous) => {
    if (id && id !== previous) {
        loadRequest();
    }
});

// The tab picked, unless this call has nothing to show there.
const shownTab = computed(() => {
    if (activeTab.value === 'response' && !request.value?.response) return 'request';
    if (activeTab.value === 'request' && !request.value?.request && !request.value?.prompt) return 'response';
    return activeTab.value;
});

// A Playground run names the call it started from.
const playgroundSource = computed(() => {
    const source = request.value?.metadata?.playground_source;

    return typeof source === 'string' ? source : null;
});

const showTrace = computed(() => (request.value?.trace?.request_count ?? 0) > 1);
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
            <div class="card"><div class="card-body"><Skeleton variant="chart" height="160px" /></div></div>
        </div>

        <div v-else-if="request">
            <!-- Header -->
            <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                <div class="flex min-w-0 items-start gap-3.5">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-gray-200 bg-white shadow-card dark:border-gray-800 dark:bg-gray-900">
                        <ProviderLogo
                            :provider="request.provider"
                            class="inline-flex h-5 w-5 items-center text-gray-700 dark:text-gray-200 [&>svg]:h-full [&>svg]:w-full"
                        />
                    </div>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="truncate text-xl font-semibold tracking-tight text-gray-900 dark:text-white" v-tooltip.truncated="request.model">{{ request.model }}</h1>
                            <span class="badge badge-secondary">{{ modelTypeLabel }}</span>
                            <span v-if="request.is_streaming" class="badge badge-purple">Streamed</span>
                            <span v-if="request.pricing_tier && request.pricing_tier !== 'standard'" class="badge badge-info capitalize">{{ request.pricing_tier }}</span>
                            <span v-if="request.is_reasoning" class="badge badge-secondary">Reasoning{{ request.reasoning_effort ? ' · ' + request.reasoning_effort : '' }}</span>
                        </div>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ request.provider_display_name || request.provider }}
                            <template v-if="request.snapshot && request.snapshot !== request.model"> · <span class="font-mono text-xs">{{ request.snapshot }}</span></template>
                            · {{ request.formatted_created_at }}
                        </p>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-3 self-start">
                    <router-link v-if="request.can_open_in_playground" :to="{ name: 'playground-call', params: { id: request.id } }" class="btn btn-secondary">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" /></svg>
                        Open in Playground
                    </router-link>
                    <StatusCode :code="request.status_code" :error="request.error_type" />
                </div>
            </div>

            <!-- Summary -->
            <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="stat-card">
                    <div class="stat-label">Cost</div>
                    <div class="stat-value text-2xl!"><Money :cents="request.total_cost_in_cents" /></div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        <template v-if="request.prompt_cost || request.completion_cost">
                            In <Money :cents="request.prompt_cost" /> · Out <Money :cents="request.completion_cost" />
                        </template>
                        <template v-else>{{ request.currency || 'USD' }}<span v-if="request.pricing_tier"> · {{ request.pricing_tier }} tier</span></template>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Latency</div>
                    <div class="stat-value text-2xl!">{{ formatDuration(request.latency_ms) }}</div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        <template v-if="request.time_to_first_token_ms != null">First token in {{ formatDuration(request.time_to_first_token_ms) }}</template>
                        <template v-else>End to end</template>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Tokens</div>
                    <div class="stat-value text-2xl!">{{ formatNumber(request.total_tokens) }}</div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ formatNumber(request.prompt_tokens) }} in · {{ formatNumber(request.completion_tokens) }} out
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Throughput</div>
                    <div class="stat-value text-2xl!">
                        <template v-if="request.tokens_per_second != null">{{ request.tokens_per_second }}<span class="ml-1 text-sm font-medium text-gray-400">tok/s</span></template>
                        <template v-else>—</template>
                    </div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ request.is_streaming ? 'Measured from the stream' : 'Streamed requests only' }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div class="min-w-0 space-y-6 lg:col-span-2">
                    <!-- Error -->
                    <div v-if="request.error_message || isFailed" class="card overflow-hidden border-red-200 dark:border-red-900/60">
                        <div class="card-header border-red-100 bg-red-50/70 dark:border-red-900/60 dark:bg-red-950/30">
                            <h3 class="flex items-center gap-2 text-red-700! dark:text-red-300!">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                                Request failed
                            </h3>
                            <span v-if="request.error_type" class="truncate font-mono text-xs text-red-600/80 dark:text-red-400/80">{{ request.error_type }}</span>
                        </div>
                        <CodeBlock v-if="request.error_message" :content="request.error_message" variant="error" max-height="16rem" />
                        <div v-else class="card-body text-sm text-gray-600 dark:text-gray-400">The provider returned HTTP {{ request.status_code ?? '—' }} without a message.</div>
                        <div v-if="request.error_fingerprint" class="border-t border-red-100 px-5 py-2.5 text-xs dark:border-red-900/60">
                            <router-link :to="{ name: 'requests', query: { error_fingerprint: request.error_fingerprint, period: 'all' } }" class="font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400">View similar failures</router-link>
                        </div>
                    </div>

                    <!-- Other AI calls of the same request, job, or command -->
                    <TraceTimeline v-if="showTrace" :trace="request.trace!" />
                    <div v-else-if="request.trace_migrated === false && request.trace_id" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                        This call's trace is drawn once the trace migrations run. Run <code class="font-mono">php artisan spectra:install</code> to publish and run them.
                    </div>

                    <!-- Model-specific metrics -->
                    <component :is="metricsComponent" :request="request" :expanded="metricsExpanded" @toggle="metricsExpanded = !metricsExpanded" />

                    <!-- Payload -->
                    <div v-if="request.response || request.request || request.prompt" class="card overflow-hidden">
                        <div class="card-header">
                            <button type="button" class="flex items-center gap-2" @click="payloadExpanded = !payloadExpanded">
                                <svg class="h-4 w-4 text-gray-400 transition-transform duration-200" :class="{ 'rotate-90': payloadExpanded }" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" /></svg>
                                <h3>Payload</h3>
                            </button>
                            <div v-if="payloadExpanded" class="segmented">
                                <button v-if="request.response" type="button" :class="{ 'is-active': shownTab === 'response' }" @click="activeTab = 'response'">Response</button>
                                <button v-if="request.request || request.prompt" type="button" :class="{ 'is-active': shownTab === 'request' }" @click="activeTab = 'request'">Request</button>
                            </div>
                        </div>
                        <template v-if="payloadExpanded">
                            <div v-if="shownTab === 'response'">
                                <JsonViewer :data="request.response" max-height="32rem" />
                            </div>
                            <div v-if="shownTab === 'request'">
                                <JsonViewer v-if="request.request" :data="request.request" max-height="32rem" />
                                <CodeBlock v-else-if="request.prompt" :content="request.prompt" max-height="32rem" />
                            </div>
                        </template>
                    </div>
                    <div v-else class="card">
                        <div class="card-header"><h3>Payload</h3></div>
                        <p class="card-body text-sm text-gray-500 dark:text-gray-400">
                            Not stored. This call's request and response bodies were sampled out, or the storage settings don't keep them. Its tokens, cost and latency are recorded either way.
                        </p>
                    </div>
                </div>

                <!-- Details -->
                <aside class="min-w-0 space-y-6">
                    <div class="card">
                        <div class="card-header"><h3>Details</h3></div>
                        <dl class="kv px-5 py-1">
                            <div>
                                <dt>Request ID</dt>
                                <dd class="font-mono text-xs" v-tooltip="request.id">{{ request.id }}</dd>
                            </div>
                            <div v-if="request.response_id">
                                <dt>Response ID</dt>
                                <dd class="flex items-center justify-end gap-1.5">
                                    <span class="truncate font-mono text-xs" v-tooltip.truncated="request.response_id">{{ request.response_id }}</span>
                                    <button
                                        type="button"
                                        class="shrink-0 rounded-sm p-0.5 transition-colors"
                                        :class="copiedField === 'response_id' ? 'text-emerald-500' : 'text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                                        v-tooltip="copiedField === 'response_id' ? 'Copied' : 'Copy'"
                                        @click="copyToClipboard(request.response_id, 'response_id')"
                                    >
                                        <svg v-if="copiedField !== 'response_id'" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" /></svg>
                                        <svg v-else class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                    </button>
                                </dd>
                            </div>
                            <div v-if="request.endpoint">
                                <dt>Endpoint</dt>
                                <dd class="font-mono text-xs" v-tooltip="request.endpoint">{{ request.endpoint }}</dd>
                            </div>
                            <div v-if="request.finish_reason">
                                <dt>Finish reason</dt>
                                <dd><span class="badge" :class="finishReasonClass">{{ request.finish_reason }}</span></dd>
                            </div>
                            <div v-if="request.pricing_tier">
                                <dt>Pricing tier</dt>
                                <dd class="capitalize">{{ request.pricing_tier }}</dd>
                            </div>
                            <div v-if="request.expires_at">
                                <dt>Expires</dt>
                                <dd>
                                    <ExpiryCountdown :key="request.id" :expires-at="request.expires_at" :formatted-expires-at="request.formatted_expires_at" @expired="() => {}" />
                                </dd>
                            </div>
                            <div v-if="request.trace_id">
                                <dt>Trace</dt>
                                <dd>
                                    <router-link :to="{ name: 'trace-detail', params: { traceId: request.trace_id } }" class="font-mono text-xs text-primary-600 hover:underline dark:text-primary-400" v-tooltip="request.trace_id">{{ request.trace_id }}</router-link>
                                </dd>
                            </div>
                            <div v-if="request.conversation_id">
                                <dt>Conversation</dt>
                                <dd class="font-mono text-xs"><router-link :to="{ name: 'conversation-detail', params: { conversationId: request.conversation_id } }" class="text-primary-600 hover:underline dark:text-primary-400" v-tooltip="request.conversation_id">{{ request.conversation_id }}</router-link><span v-if="request.conversation_turn" class="text-gray-400"> · turn {{ request.conversation_turn }}</span></dd>
                            </div>
                            <div v-if="request.prompt_name">
                                <dt>Prompt</dt>
                                <dd class="font-mono text-xs"><router-link :to="{ name: 'prompts' }" class="text-primary-600 hover:underline dark:text-primary-400">{{ request.prompt_name }}</router-link><span v-if="request.prompt_version" class="text-gray-400"> · {{ request.prompt_version }}</span></dd>
                            </div>
                            <div v-if="playgroundSource">
                                <dt>Started from</dt>
                                <dd class="font-mono text-xs"><router-link :to="{ name: 'request-preview', params: { id: playgroundSource } }" class="text-primary-600 hover:underline dark:text-primary-400" v-tooltip="playgroundSource">{{ playgroundSource }}</router-link></dd>
                            </div>
                            <div v-if="request.request_source">
                                <dt>Source</dt>
                                <dd class="capitalize">{{ request.request_source }}</dd>
                            </div>
                            <div v-if="request.ip_address">
                                <dt>IP address</dt>
                                <dd class="font-mono text-xs">{{ request.ip_address }}</dd>
                            </div>
                            <div v-if="request.user_agent">
                                <dt>User agent</dt>
                                <dd class="text-xs" v-tooltip="request.user_agent">{{ request.user_agent }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div v-if="request.tool_call_counts && Object.keys(request.tool_call_counts).length" class="card">
                        <div class="card-header"><h3>Tool calls</h3></div>
                        <ul class="kv px-5 py-1">
                            <li v-for="(count, type) in request.tool_call_counts" :key="type" class="flex items-center justify-between py-2.5 text-sm">
                                <span class="capitalize text-gray-600 dark:text-gray-300">{{ formatToolName(type) }}</span>
                                <span class="tabular-nums font-medium text-gray-900 dark:text-white">{{ count }}</span>
                            </li>
                        </ul>
                    </div>

                    <ScoresCard v-if="request.scores?.length" :scores="request.scores" :current-request-id="request.id" />

                    <AddToDataset v-if="modelType === 'text' && request.request" :request-id="request.id" />

                    <div v-if="request.tags?.length" class="card">
                        <div class="card-header">
                            <div class="-my-0.5 flex items-center gap-2">
                                <h3>Tags</h3>
                                <DocsLink page="tags" label="What are tags? Read the docs" />
                            </div>
                        </div>
                        <div class="card-body flex flex-wrap gap-1.5">
                            <router-link
                                v-for="tag in request.tags"
                                :key="tag"
                                :to="{ name: 'requests', query: { tag } }"
                                class="badge badge-secondary hover:bg-gray-100 dark:hover:bg-gray-800"
                            >#{{ tag }}</router-link>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</template>
