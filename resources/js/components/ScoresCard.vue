<script setup>
import ScoreValue from '@/components/ScoreValue.vue';

// The scores of a call, trace or conversation: feedback, model judgements
// and reviewer labels, newest first.
const props = defineProps({
    scores: { type: Array, default: () => [] },
    // Link each score given to one call to that call's screen.
    linkRequests: { type: Boolean, default: true },
    currentRequestId: { type: String, default: null },
});

const formatTime = (iso) => {
    if (!iso) return '';
    const date = new Date(iso);

    return Number.isNaN(date.getTime()) ? iso : date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
};
</script>

<template>
    <div class="card overflow-hidden">
        <div class="card-header">
            <h3>Scores</h3>
            <span class="text-xs text-gray-500 dark:text-gray-400">{{ scores.length }}</span>
        </div>
        <ul class="divide-y divide-gray-100 dark:divide-gray-800/80">
            <li v-for="score in scores" :key="score.id" class="flex items-start gap-3 px-5 py-3">
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-medium text-gray-900 dark:text-white" v-tooltip.truncated="score.name">{{ score.name }}</div>
                    <p v-if="score.comment" class="mt-0.5 line-clamp-3 text-sm text-gray-600 dark:text-gray-300">{{ score.comment }}</p>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ score.source_label }} · {{ formatTime(score.created_at) }}
                        <template v-if="linkRequests && score.request_id && score.request_id !== currentRequestId">
                            · <router-link :to="{ name: 'request-preview', params: { id: score.request_id } }" class="font-medium text-primary-600 hover:underline dark:text-primary-400">on a call</router-link>
                        </template>
                    </div>
                </div>
                <ScoreValue :type="score.type" :value="score.value" />
            </li>
        </ul>
    </div>
</template>
