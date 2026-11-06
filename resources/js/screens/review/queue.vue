<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRoute } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { useLoading } from '@/composables/useLoading';
import Skeleton from '@/components/Skeleton.vue';
import StatusCode from '@/components/StatusCode.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import Money from '@/components/Money.vue';
import ScoresCard from '@/components/ScoresCard.vue';
import type { AnnotationItemResponse, AnnotationQueueScore, AnnotationQueueSummary, AnnotationReviewItem, ScoreValue } from '@/types/api';
import type { ApiError } from '@/types/app';

// One review queue: the trace waiting longest, what each of its calls was
// asked and answered, and the queue's scores to give. Submitting saves the
// scores and opens the next trace.
const route = useRoute();
const { formatNumber, formatDuration, apiRequest } = useHelpers();
const loading = useLoading();

loading.value = true;
const queue = ref<AnnotationQueueSummary | null>(null);
const item = ref<AnnotationReviewItem | null>(null);
const canAnnotate = ref(false);
const values = ref<Record<string, ScoreValue>>({});
const comment = ref('');
const saving = ref(false);
const error = ref('');
const reviewed = ref(0);
const loadFailed = ref(false);

const resetForm = () => {
    values.value = Object.fromEntries((queue.value?.scores || []).map((score) => [score.name, null]));
    comment.value = '';
    error.value = '';
};

const load = async () => {
    loading.value = true;
    loadFailed.value = false;
    try {
        const data = await apiRequest<AnnotationItemResponse>('get', `/annotations/${encodeURIComponent(route.params.queue as string)}/next`);
        queue.value = data.queue;
        item.value = data.item;
        canAnnotate.value = data.can_annotate;
        resetForm();
        window.scrollTo({ top: 0 });
    } catch (e) {
        console.error('Failed to load the next trace:', e);
        loadFailed.value = true;
    } finally {
        loading.value = false;
    }
};

// Whole numbers in a short range get one button each; anything else a number field.
const steps = (score: AnnotationQueueScore) => {
    if (score.min === null || score.max === null || !Number.isInteger(score.min) || !Number.isInteger(score.max) || score.max - score.min > 10) return null;
    return Array.from({ length: score.max - score.min + 1 }, (_, index) => score.min! + index);
};

const choose = (score: AnnotationQueueScore, value: ScoreValue) => {
    values.value[score.name] = values.value[score.name] === value ? null : value;
};

const hasInput = computed(() => Object.values(values.value).some((value) => value !== null && value !== '') || comment.value.trim() !== '');

const send = async (action: 'review' | 'skip') => {
    if (!item.value || saving.value) return;
    if (action === 'review' && !hasInput.value) {
        error.value = 'Give at least one score or a comment, or skip this trace.';
        return;
    }

    saving.value = true;
    error.value = '';
    try {
        await apiRequest('post', `/annotations/items/${item.value.id}`, { action, scores: values.value, comment: comment.value || null });
        if (action === 'review') reviewed.value++;
        await load();
    } catch (e) {
        error.value = (e as ApiError)?.response?.data?.message || 'The review could not be saved.';
    } finally {
        saving.value = false;
    }
};

const onKeydown = (event: KeyboardEvent) => {
    if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
        event.preventDefault();
        send('review');
    }
};

onMounted(async () => {
    window.addEventListener('keydown', onKeydown);
    await load();
});
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div>
        <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    <router-link :to="{ name: 'review' }" class="hover:text-primary-600 dark:hover:text-primary-400">Review</router-link>
                    <span class="mx-1.5">/</span>
                    <span class="font-mono">{{ route.params.queue }}</span>
                </p>
                <h1 class="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{{ queue?.description || route.params.queue }}</h1>
            </div>
            <p v-if="queue" class="text-sm text-gray-500 dark:text-gray-400">
                <span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ formatNumber(queue.pending_count) }}</span> waiting
                <template v-if="reviewed"> · {{ formatNumber(reviewed) }} reviewed now</template>
            </p>
        </div>

        <div v-if="loading && !item" class="card"><Skeleton variant="table-row" :lines="6" /></div>

        <div v-else-if="loadFailed" class="card">
            <div class="empty-state">
                <p class="empty-state-title">The next trace could not be loaded</p>
                <p class="empty-state-description">Refresh to try again, or check the queue still exists. <router-link :to="{ name: 'review' }" class="text-primary-600 hover:underline dark:text-primary-400">Back to the queues</router-link></p>
            </div>
        </div>

        <div v-else-if="!item" class="card">
            <div class="empty-state">
                <p class="empty-state-title">Nothing left to review</p>
                <p class="empty-state-description">Every trace in this queue was reviewed or skipped. <router-link :to="{ name: 'review' }" class="text-primary-600 hover:underline dark:text-primary-400">Back to the queues</router-link></p>
            </div>
        </div>

        <div v-else class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- The trace: what each call was asked and answered -->
            <div class="min-w-0 space-y-4 lg:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                    <span class="font-mono" v-tooltip="item.origin || ''">{{ item.origin || 'No origin recorded' }}</span>
                    <router-link :to="{ name: 'trace-detail', params: { traceId: item.trace_id } }" class="font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400">Open the trace</router-link>
                </div>

                <div
                    v-for="(call, index) in item.calls"
                    :key="call.id"
                    class="card overflow-hidden"
                    :class="call.is_queued_call ? 'ring-1 ring-primary-300 dark:ring-primary-500/40' : ''"
                >
                    <div class="card-header">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="text-xs tabular-nums text-gray-400">{{ index + 1 }}</span>
                            <ProviderLogo :provider="call.provider" class="inline-flex h-4 w-4 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full" />
                            <router-link :to="{ name: 'request-preview', params: { id: call.id } }" class="truncate text-sm font-medium text-gray-900 hover:text-primary-600 dark:text-white dark:hover:text-primary-400">{{ call.model }}</router-link>
                            <StatusCode :code="call.status_code" />
                        </div>
                        <span class="shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ formatDuration(call.latency_ms) }} · <Money :cents="call.total_cost_in_cents" /></span>
                    </div>
                    <div class="card-body space-y-3 text-sm">
                        <div v-if="call.prompt">
                            <p class="mb-1 text-[11px] font-medium uppercase tracking-wide text-gray-400">Asked</p>
                            <p class="whitespace-pre-wrap wrap-break-word text-gray-700 dark:text-gray-300">{{ call.prompt }}</p>
                        </div>
                        <div v-if="call.reply">
                            <p class="mb-1 text-[11px] font-medium uppercase tracking-wide text-gray-400">Answered</p>
                            <p class="whitespace-pre-wrap wrap-break-word text-gray-900 dark:text-gray-100">{{ call.reply }}</p>
                        </div>
                        <p v-if="call.error_message" class="whitespace-pre-wrap wrap-break-word font-mono text-xs text-red-600 dark:text-red-400">{{ call.error_message }}</p>
                        <p v-if="!call.prompt && !call.reply && !call.error_message" class="text-xs text-gray-500 dark:text-gray-400">No text was stored for this call (store_prompts, store_responses or payload sampling).</p>
                    </div>
                </div>

                <ScoresCard v-if="item.scores.length" :scores="item.scores" />
            </div>

            <!-- The labels to give -->
            <div class="lg:sticky lg:top-20 lg:self-start">
                <form class="card" @submit.prevent="send('review')">
                    <div class="card-header"><h3>Your review</h3></div>
                    <div class="card-body space-y-5">
                        <div v-for="score in queue!.scores" :key="score.name">
                            <p class="mb-2 font-mono text-xs font-medium text-gray-700 dark:text-gray-300">{{ score.name }}</p>

                            <div v-if="score.type === 'boolean'" class="grid grid-cols-2 gap-2">
                                <button
                                    v-for="option in [{ value: true, label: 'Yes' }, { value: false, label: 'No' }]"
                                    :key="option.label"
                                    type="button"
                                    class="rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors"
                                    :class="values[score.name] === option.value
                                        ? (option.value ? 'border-emerald-500 bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'border-red-500 bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300')
                                        : 'border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/4'"
                                    :aria-pressed="values[score.name] === option.value"
                                    @click="choose(score, option.value)"
                                >{{ option.label }}</button>
                            </div>

                            <div v-else-if="score.type === 'numeric' && steps(score)" class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="step in steps(score)"
                                    :key="step"
                                    type="button"
                                    class="h-8 min-w-8 rounded-lg border px-2 text-sm font-medium tabular-nums transition-colors"
                                    :class="values[score.name] === step ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300' : 'border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/4'"
                                    :aria-pressed="values[score.name] === step"
                                    @click="choose(score, step)"
                                >{{ step }}</button>
                            </div>

                            <input
                                v-else-if="score.type === 'numeric'"
                                v-model.number="values[score.name]"
                                type="number"
                                step="any"
                                :min="score.min ?? undefined"
                                :max="score.max ?? undefined"
                                class="form-input w-full"
                            />

                            <div v-else class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="option in score.options"
                                    :key="option"
                                    type="button"
                                    class="rounded-full border px-2.5 py-1 text-xs font-medium transition-colors"
                                    :class="values[score.name] === option ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300' : 'border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/4'"
                                    :aria-pressed="values[score.name] === option"
                                    @click="choose(score, option)"
                                >{{ option }}</button>
                            </div>
                        </div>

                        <div>
                            <label for="review-comment" class="mb-2 block text-xs font-medium text-gray-700 dark:text-gray-300">Comment</label>
                            <textarea id="review-comment" v-model="comment" rows="3" class="form-input w-full" placeholder="What was good or wrong?"></textarea>
                        </div>

                        <p v-if="error" class="text-xs text-red-600 dark:text-red-400">{{ error }}</p>
                    </div>
                    <div class="flex items-center justify-between gap-2 border-t border-gray-100 px-5 py-3 dark:border-gray-800">
                        <button type="button" class="btn btn-secondary" :disabled="saving || !canAnnotate" @click="send('skip')">Skip</button>
                        <button type="submit" class="btn btn-primary" :disabled="saving || !canAnnotate" aria-keyshortcuts="Meta+Enter Control+Enter">Submit and next</button>
                    </div>
                    <p class="px-5 pb-3 text-right text-[11px] text-gray-400 dark:text-gray-500">⌘/Ctrl + Enter submits</p>
                </form>
            </div>
        </div>
    </div>
</template>
