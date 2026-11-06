<script setup lang="ts">
import { computed, ref } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import CodeBlock from '@/components/CodeBlock.vue';
import JsonViewer from '@/components/JsonViewer.vue';
import Money from '@/components/Money.vue';
import type { JsonObject, PlaygroundCall } from '@/types/api';

// One answer in the Playground, the original call's or a run's: the reply or
// the provider's error, and its cost, tokens and latency. A run shows how each
// number moved against the original.
const props = withDefaults(defineProps<{
    title: string;
    model: string;
    succeeded: boolean;
    statusCode?: number | null;
    errorType?: string | null;
    errorMessage?: string | null;
    reply?: string | null;
    hasToolCalls?: boolean;
    finishReason?: string | null;
    promptTokens: number;
    completionTokens: number;
    costInCents: number;
    latencyMs?: number | null;
    response?: JsonObject | null;
    requestId?: string | null;
    compareWith?: PlaygroundCall | null;
    highlighted?: boolean;
}>(), {
    statusCode: null,
    errorType: null,
    errorMessage: null,
    reply: null,
    hasToolCalls: false,
    finishReason: null,
    latencyMs: null,
    response: null,
    requestId: null,
    compareWith: null,
    highlighted: false,
});

const { formatNumber, formatDuration, formatCurrency } = useHelpers();

const showResponse = ref(false);
const totalTokens = computed(() => props.promptTokens + props.completionTokens);

// Failed calls have no usage worth comparing: a failed run isn't 100% cheaper.
const isComparable = computed(() => {
    const original = props.compareWith;

    return props.succeeded && !!original && !original.error_type && !!original.status_code && original.status_code >= 200 && original.status_code < 300;
});

// The change against the original, or nothing when it can't compare or nothing moved.
const change = (value: number | null | undefined, original: number | null | undefined, format: (amount: number) => string) => {
    if (!isComparable.value || value == null || original == null || value === original) return null;

    const difference = value - original;
    const percent = original !== 0 ? ` (${difference > 0 ? '+' : ''}${Math.round((difference / original) * 100)}%)` : '';

    return { text: `${difference > 0 ? '+' : '−'}${format(Math.abs(difference))}${percent}`, isLower: difference < 0 };
};

const costChange = computed(() => change(props.costInCents, props.compareWith?.total_cost_in_cents, (amount) => String(formatCurrency(amount))));
const tokensChange = computed(() => change(totalTokens.value, props.compareWith ? props.compareWith.prompt_tokens + props.compareWith.completion_tokens : null, (amount) => String(formatNumber(amount))));
const latencyChange = computed(() => change(props.latencyMs, props.compareWith?.latency_ms, (amount) => String(formatDuration(amount))));
</script>

<template>
    <div class="card overflow-hidden" :class="{ 'ring-1 ring-primary-500/40': highlighted }">
        <div class="card-header">
            <div class="min-w-0">
                <h3>{{ title }}</h3>
                <p class="mt-0.5 truncate font-mono text-xs text-gray-500 dark:text-gray-400" v-tooltip.truncated="model">{{ model }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <span v-if="finishReason" class="badge badge-secondary">{{ finishReason }}</span>
                <span class="badge" :class="succeeded ? 'badge-success' : 'badge-danger'">{{ statusCode ?? 'No response' }}</span>
            </div>
        </div>

        <CodeBlock v-if="!succeeded && errorMessage" :content="errorMessage" variant="error" max-height="16rem" />
        <div v-else-if="reply" class="max-h-80 overflow-y-auto whitespace-pre-wrap break-words px-5 py-4 text-sm leading-relaxed text-gray-800 dark:text-gray-200">{{ reply }}</div>
        <p v-else class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400">
            {{ hasToolCalls ? 'The model asked for a tool instead of replying.' : 'No reply text was stored for this call.' }}
        </p>

        <dl class="grid grid-cols-3 divide-x divide-gray-100 border-t border-gray-100 text-center dark:divide-gray-800 dark:border-gray-800">
            <div class="px-2 py-3">
                <dt class="text-xs text-gray-500 dark:text-gray-400">Cost</dt>
                <dd class="mt-0.5 text-sm font-semibold tabular-nums text-gray-900 dark:text-white"><Money :cents="costInCents" /></dd>
                <dd v-if="costChange" class="mt-0.5 text-xs tabular-nums" :class="costChange.isLower ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">{{ costChange.text }}</dd>
            </div>
            <div class="px-2 py-3">
                <dt class="text-xs text-gray-500 dark:text-gray-400">Tokens</dt>
                <dd class="mt-0.5 text-sm font-semibold tabular-nums text-gray-900 dark:text-white" v-tooltip="`${formatNumber(promptTokens)} in · ${formatNumber(completionTokens)} out`">{{ formatNumber(totalTokens) }}</dd>
                <dd v-if="tokensChange" class="mt-0.5 text-xs tabular-nums" :class="tokensChange.isLower ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">{{ tokensChange.text }}</dd>
            </div>
            <div class="px-2 py-3">
                <dt class="text-xs text-gray-500 dark:text-gray-400">Latency</dt>
                <dd class="mt-0.5 text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ formatDuration(latencyMs) }}</dd>
                <dd v-if="latencyChange" class="mt-0.5 text-xs tabular-nums" :class="latencyChange.isLower ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">{{ latencyChange.text }}</dd>
            </div>
        </dl>

        <div v-if="response || requestId" class="flex items-center justify-between border-t border-gray-100 px-5 py-2.5 text-xs dark:border-gray-800">
            <button v-if="response" type="button" class="font-medium text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200" @click="showResponse = !showResponse">
                {{ showResponse ? 'Hide' : 'Show' }} the response
            </button>
            <router-link v-if="requestId" :to="{ name: 'request-preview', params: { id: requestId } }" class="ml-auto font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400">View the call</router-link>
        </div>
        <JsonViewer v-if="showResponse && response" :data="response" max-height="24rem" />
    </div>
</template>
