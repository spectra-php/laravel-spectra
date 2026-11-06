<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import { useLoading } from '@/composables/useLoading';
import Skeleton from '@/components/Skeleton.vue';
import type { AnnotationQueueScore, AnnotationQueueSummary, AnnotationQueuesResponse } from '@/types/api';

// The review queues of spectra.annotations.queues: how many traces wait in
// each, and how far the reviewers got.
const { formatNumber, apiRequest } = useHelpers();
const loading = useLoading();

loading.value = true;
const data = ref<AnnotationQueuesResponse>({ queues: [], can_annotate: false, migrated: true });

const done = (queue: AnnotationQueueSummary) => queue.completed_count + queue.skipped_count;
const total = (queue: AnnotationQueueSummary) => done(queue) + queue.pending_count;
const progress = (queue: AnnotationQueueSummary) => (total(queue) ? Math.round((done(queue) / total(queue)) * 100) : 0);
const scoreLabel = (score: AnnotationQueueScore) => (score.type === 'numeric' && score.min !== null && score.max !== null ? `${score.name} ${score.min}–${score.max}` : score.name);

onMounted(async () => {
    try {
        data.value = await apiRequest<AnnotationQueuesResponse>('get', '/annotations');
    } catch (error) {
        console.error('Failed to load review queues:', error);
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div>
        <div class="mb-6">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Review</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Label traces by hand; each label is saved as a score</p>
        </div>

        <div v-if="!loading && !data.migrated" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            Run <code class="font-mono">php artisan spectra:install</code> to create the review queue table. Until then, no trace is queued.
        </div>

        <div v-if="loading" class="card"><Skeleton variant="table-row" :lines="4" /></div>

        <div v-else-if="!data.queues.length" class="card">
            <div class="empty-state">
                <p class="empty-state-title">No review queues</p>
                <p class="empty-state-description">Add a queue under <code class="font-mono">spectra.annotations.queues</code> with the scores reviewers give.</p>
            </div>
        </div>

        <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div v-for="queue in data.queues" :key="queue.name" class="card flex flex-col">
                <div class="card-body flex-1">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="truncate font-mono text-sm font-semibold text-gray-900 dark:text-white">{{ queue.name }}</h3>
                            <p v-if="queue.description" class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ queue.description }}</p>
                        </div>
                        <span class="shrink-0 text-right">
                            <span class="block text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ formatNumber(queue.pending_count) }}</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">waiting</span>
                        </span>
                    </div>

                    <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                        <div class="h-full rounded-full bg-primary-500" :style="{ width: progress(queue) + '%' }"></div>
                    </div>
                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ formatNumber(queue.completed_count) }} reviewed · {{ formatNumber(queue.skipped_count) }} skipped
                        <template v-if="queue.sample"> · samples {{ Math.round(queue.sample * 1000) / 10 }}% of matching traces</template>
                    </p>

                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <span v-for="score in queue.scores" :key="score.name" class="badge badge-secondary">{{ scoreLabel(score) }}</span>
                    </div>
                </div>
                <div class="flex items-center justify-end border-t border-gray-100 px-5 py-3 dark:border-gray-800">
                    <router-link
                        v-if="queue.pending_count && data.can_annotate"
                        :to="{ name: 'review-queue', params: { queue: queue.name } }"
                        class="btn btn-primary"
                    >Start reviewing</router-link>
                    <span v-else-if="!data.can_annotate" class="text-xs text-gray-500 dark:text-gray-400">The annotateSpectra gate doesn't let you review</span>
                    <span v-else class="text-xs text-gray-500 dark:text-gray-400">Nothing waiting</span>
                </div>
            </div>
        </div>
    </div>
</template>
