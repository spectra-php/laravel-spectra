<script setup>
import { ref, onMounted, computed } from 'vue';
import { useRoute } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { useLoading } from '@/composables/useLoading';
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
import Breadcrumb from '@/components/Breadcrumb.vue';

const route = useRoute();
const { spectra, formatNumber, formatCurrency, formatDuration, statusClass, providerClass, apiRequest } = useHelpers();
const loading = useLoading();

const request = ref(null);
loading.value = true;
const activeTab = ref('response');
const metricsExpanded = ref(true);
const payloadExpanded = ref(false);
const metadataExpanded = ref(false);

const loadRequest = async () => {
    try {
        request.value = await apiRequest('get', '/requests/' + route.params.id);
    } catch (error) {
        console.error('Failed to load request:', error);
    } finally {
        loading.value = false;
    }
};

const modelType = computed(() => request.value?.model_type || 'text');

const modelTypeLabel = computed(() => {
    const labels = { text: 'Text', embedding: 'Embedding', image: 'Image', video: 'Video', tts: 'Text-to-Speech', stt: 'Speech-to-Text' };
    return labels[modelType.value] || modelType.value;
});

const metricsComponent = computed(() => {
    const map = { text: TokenMetrics, embedding: TokenMetrics, image: ImageMetrics, tts: TtsMetrics, stt: SttMetrics, video: VideoMetrics };
    return map[modelType.value] || TokenMetrics;
});

const copiedField = ref(null);
const copyToClipboard = async (text, field) => {
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

const breadcrumbs = computed(() => [
    { label: 'Requests', to: { name: 'requests' } },
    { label: request.value?.model || `Request #${route.params.id}` },
]);

onMounted(() => {
    loadRequest();
});
</script>

<template>
    <div class="">
        <!-- Breadcrumb -->
        <Breadcrumb :items="breadcrumbs" />

        <!-- Header -->
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-xl font-bold text-gray-900 dark:text-white">Request Details</h1>
            <div v-if="loading">
                <div class="h-6 w-20 bg-gray-200 dark:bg-gray-700 rounded-full animate-pulse"></div>
            </div>
            <div v-else-if="request">
                <StatusCode :code="request.status_code" />
            </div>
        </div>

        <!-- Loading State -->
        <div v-if="loading">
            <!-- Stats skeleton -->
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-4 mb-6">
                <div v-for="i in 4" :key="i" class="stat-card">
                    <div class="h-3 w-16 bg-gray-200 dark:bg-gray-700 rounded animate-pulse mb-3"></div>
                    <div class="h-7 w-28 bg-gray-200 dark:bg-gray-700 rounded animate-pulse"></div>
                </div>
            </div>
            <!-- Content skeleton -->
            <div class="card mb-6">
                <div class="card-header">
                    <div class="h-4 w-24 bg-gray-200 dark:bg-gray-700 rounded animate-pulse"></div>
                </div>
                <div class="card-body">
                    <Skeleton variant="chart" height="150px" />
                </div>
            </div>
        </div>

        <div v-else-if="request" class="space-y-6">
            <!-- Overview -->
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
                <div class="stat-card">
                    <div class="stat-label">Provider</div>
                    <div class="mt-1 flex items-center gap-2">
                        <span
                            v-if="request.provider_logo_svg"
                            v-html="request.provider_logo_svg"
                            class="inline-flex items-center w-5 h-5 [&>svg]:w-full [&>svg]:h-full text-gray-500 dark:text-gray-400 shrink-0"
                        ></span>
                        <span class="stat-value text-lg">{{ request.provider_display_name || request.provider }}</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Model</div>
                    <div class="mt-1 flex items-center gap-2 min-w-0">
                        <span
                            class="stat-value text-lg flex-1 min-w-0 truncate"
                            :title="request.model"
                        >{{ request.model }}</span>
                        <span
                            v-if="request.model_type"
                            class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium uppercase bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400 shrink-0"
                        >{{ modelTypeLabel }}</span>
                        <span
                            v-if="request.is_streaming"
                            class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium uppercase bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-400 shrink-0"
                            title="Streaming"
                        >
                            <svg class="w-3 h-3 mr-0.5" fill="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path d="M6.26,19.089A9.625,9.625,0,0,1,6.234,4.911C6.709,4.475,6,3.769,5.527,4.2A10.516,10.516,0,0,0,5.553,19.8c.475.433,1.184-.273.707-.707Z"/>
                                <path d="M8.84,15.706a5.024,5.024,0,0,1-.014-7.412c.474-.437-.234-1.143-.707-.707a6.028,6.028,0,0,0,.014,8.826c.474.434,1.183-.272.707-.707Z"/>
                                <circle cx="12" cy="12" r="1.244"/>
                                <path d="M17.74,4.911a9.625,9.625,0,0,1,.026,14.178c-.475.436.234,1.142.707.707A10.516,10.516,0,0,0,18.447,4.2c-.475-.433-1.184.273-.707.707Z"/>
                                <path d="M15.16,8.294a5.024,5.024,0,0,1,.014,7.412c-.474.437.234,1.143.707.707a6.028,6.028,0,0,0-.014-8.826c-.474-.434-1.183.272-.707.707Z"/>
                            </svg>
                            Stream
                        </span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Cost</div>
                    <div class="mt-1 flex items-center gap-2">
                        <span class="stat-value text-lg">{{ formatCurrency(request.total_cost_in_cents) }}</span>
                        <span
                            v-if="request.pricing_tier"
                            class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium uppercase bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400"
                        >{{ request.pricing_tier }}</span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Latency</div>
                    <div class="mt-1 flex items-center gap-1.5 flex-wrap">
                        <span class="stat-value text-lg">{{ formatDuration(request.latency_ms) }}</span>
                        <template v-if="request.tokens_per_second != null">
                            <span class="text-gray-300 dark:text-gray-600">/</span>
                            <span class="text-sm text-gray-600 dark:text-gray-400" :title="'Tokens per second'">
                                <span class="font-medium">{{ request.tokens_per_second }}</span><span class="text-xs text-gray-400 dark:text-gray-500 ml-0.5">tok/s</span>
                            </span>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Model-specific Metrics -->
            <component :is="metricsComponent" :request="request" :expanded="metricsExpanded" @toggle="metricsExpanded = !metricsExpanded" />

            <!-- Request/Response Tabs -->
            <div class="card" v-if="request.response || request.request || request.prompt">
                <div
                    class="card-header cursor-pointer select-none"
                    :class="{ 'border-b-0 pb-0': payloadExpanded }"
                    @click="payloadExpanded = !payloadExpanded"
                >
                    <div class="flex items-center justify-between w-full">
                        <div class="flex items-center gap-2">
                            <svg
                                class="w-4 h-4 text-gray-400 transition-transform duration-200"
                                :class="{ 'rotate-90': payloadExpanded }"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                            <h3 class="text-sm font-medium">Payload</h3>
                        </div>
                    </div>
                </div>
                <template v-if="payloadExpanded">
                    <div class="px-4 pt-3 pb-2">
                        <div class="inline-flex items-center p-1 rounded-lg bg-gray-100 dark:bg-gray-700/60">
                            <button
                                v-if="request.response"
                                @click="activeTab = 'response'"
                                :class="[
                                    'px-3 py-1.5 text-xs font-semibold rounded-md transition-colors',
                                    activeTab === 'response'
                                        ? 'bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm'
                                        : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200',
                                ]"
                            >
                                Response
                            </button>
                            <button
                                v-if="request.request || request.prompt"
                                @click="activeTab = 'request'"
                                :class="[
                                    'px-3 py-1.5 text-xs font-semibold rounded-md transition-colors',
                                    activeTab === 'request'
                                        ? 'bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm'
                                        : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200',
                                ]"
                            >
                                Request
                            </button>
                        </div>
                    </div>
                    <div class="border-t border-gray-100 dark:border-gray-700">
                        <!-- Response Tab Content -->
                        <div v-if="activeTab === 'response'" class="p-0">
                            <JsonViewer :data="request.response" max-height="32rem" />
                        </div>
                        <!-- Request Tab Content -->
                        <div v-if="activeTab === 'request'" class="p-0">
                            <JsonViewer v-if="request.request" :data="request.request" max-height="32rem" />
                            <CodeBlock v-else-if="request.prompt" :content="request.prompt" max-height="32rem" />
                        </div>
                    </div>
                </template>
            </div>

            <!-- Error -->
            <div class="card border-red-200 dark:border-red-800" v-if="request.error_message">
                <div class="card-header bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800">
                    <h3 class="text-sm font-medium text-red-800 dark:text-red-400 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                        Error
                    </h3>
                </div>
                <div class="p-0">
                    <CodeBlock :content="request.error_message" variant="error" max-height="16rem" />
                </div>
            </div>

            <!-- Metadata -->
            <div class="card">
                <div
                    class="card-header cursor-pointer select-none"
                    :class="{ 'border-b-0': !metadataExpanded }"
                    @click="metadataExpanded = !metadataExpanded"
                >
                    <div class="flex items-center justify-between w-full">
                        <div class="flex items-center gap-2">
                            <svg
                                class="w-4 h-4 text-gray-400 transition-transform duration-200"
                                :class="{ 'rotate-90': metadataExpanded }"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                            <h3 class="text-sm font-medium">Metadata</h3>
                        </div>
                    </div>
                </div>
                <div v-if="metadataExpanded" class="card-body">
                    <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                        <div class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-700/50">
                            <dt class="text-sm text-gray-500 dark:text-gray-400">Request ID</dt>
                            <dd class="text-sm font-mono text-gray-900 dark:text-white">{{ request.id }}</dd>
                        </div>
                        <div v-if="request.response_id" class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-700/50">
                            <dt class="text-sm text-gray-500 dark:text-gray-400">Response ID</dt>
                            <dd class="flex items-center gap-1.5 text-sm font-mono text-gray-900 dark:text-white">
                                {{ request.response_id }}
                                <button
                                    @click="copyToClipboard(request.response_id, 'response_id')"
                                    class="inline-flex items-center p-0.5 rounded transition-colors"
                                    :class="copiedField === 'response_id'
                                        ? 'text-green-500 dark:text-green-400'
                                        : 'text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300'"
                                >
                                    <svg v-if="copiedField !== 'response_id'" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                    </svg>
                                    <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                </button>
                            </dd>
                        </div>
                        <div v-if="request.endpoint" class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-700/50">
                            <dt class="text-sm text-gray-500 dark:text-gray-400">Endpoint</dt>
                            <dd class="text-sm font-mono text-gray-900 dark:text-white">{{ request.endpoint }}</dd>
                        </div>
                        <div class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-700/50">
                            <dt class="text-sm text-gray-500 dark:text-gray-400">Created At</dt>
                            <dd class="text-sm text-gray-900 dark:text-white">{{ request.formatted_created_at }}</dd>
                        </div>
                        <div v-if="request.expires_at" class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-700/50">
                            <dt class="text-sm text-gray-500 dark:text-gray-400">Expires At</dt>
                            <dd class="text-sm text-gray-900 dark:text-white">
                                <ExpiryCountdown
                                    :expires-at="request.expires_at"
                                    :formatted-expires-at="request.formatted_expires_at"
                                    @expired="() => {}"
                                />
                            </dd>
                        </div>
                        <div v-if="request.trace_id" class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-700/50">
                            <dt class="text-sm text-gray-500 dark:text-gray-400">Trace ID</dt>
                            <dd class="text-sm font-mono">
                                <router-link
                                    :to="{ name: 'requests', query: { trace_id: request.trace_id } }"
                                    class="text-primary-600 dark:text-primary-400 hover:underline"
                                >{{ request.trace_id }}</router-link>
                            </dd>
                        </div>
                        <div v-if="request.user_id" class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-700/50">
                            <dt class="text-sm text-gray-500 dark:text-gray-400">User ID</dt>
                            <dd class="text-sm text-gray-900 dark:text-white">{{ request.user_id }}</dd>
                        </div>
                        <div v-if="request.conversation_id" class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-700/50">
                            <dt class="text-sm text-gray-500 dark:text-gray-400">Conversation ID</dt>
                            <dd class="text-sm font-mono text-gray-900 dark:text-white">{{ request.conversation_id }}</dd>
                        </div>
                        <div v-if="request.finish_reason" class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-700/50">
                            <dt class="text-sm text-gray-500 dark:text-gray-400">Finish Reason</dt>
                            <dd class="text-sm">
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                                    :class="request.finish_reason === 'length' || request.finish_reason === 'max_tokens'
                                        ? 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'
                                        : request.finish_reason === 'stop' || request.finish_reason === 'end_turn' || request.finish_reason === 'STOP'
                                            ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                            : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300'"
                                >{{ request.finish_reason }}</span>
                            </dd>
                        </div>
                        <div v-if="request.tool_call_counts && Object.keys(request.tool_call_counts).length" class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-700/50 md:col-span-2">
                            <dt class="text-sm text-gray-500 dark:text-gray-400">Tool Calls</dt>
                            <dd class="flex flex-wrap gap-1.5">
                                <span
                                    v-for="(count, type) in request.tool_call_counts"
                                    :key="type"
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400"
                                >{{ type.replace(/_/g, ' ') }} <span class="ml-1 font-bold tabular-nums">{{ count }}</span></span>
                            </dd>
                        </div>
                        <div v-if="request.ip_address" class="flex justify-between py-2 border-b border-gray-100 dark:border-gray-700/50">
                            <dt class="text-sm text-gray-500 dark:text-gray-400">IP Address</dt>
                            <dd class="text-sm font-mono text-gray-900 dark:text-white">{{ request.ip_address }}</dd>
                        </div>
                    </dl>
                    <div v-if="request.tags?.length" class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700/50">
                        <dt class="text-sm text-gray-500 dark:text-gray-400 mb-2">Tags</dt>
                        <dd class="flex flex-wrap gap-2">
                            <span
                                v-for="tag in request.tags"
                                :key="tag"
                                class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300"
                            >
                                <svg class="w-3 h-3 mr-1 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                                </svg>
                                {{ tag }}
                            </span>
                        </dd>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
