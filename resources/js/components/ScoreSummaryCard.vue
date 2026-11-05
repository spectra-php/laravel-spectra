<script setup>
import { useHelpers } from '@/composables/useHelpers';

// The overview's Scores card: each score name given in the period, how often,
// and its average, the share of yes, or its most common labels. One tile per
// score, so a name stays next to its value on wide screens.
defineProps({
    scores: { type: Array, default: () => [] },
});

const { formatNumber } = useHelpers();

const number = (value) => Number(value ?? 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
const percent = (score) => Math.round(Number(score.average ?? 0) * 100);
</script>

<template>
    <div class="card overflow-hidden">
        <div class="card-header">
            <div>
                <h3>Scores</h3>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Feedback and evaluations given in this period</p>
            </div>
        </div>
        <ul class="grid grid-cols-1 gap-px bg-gray-100 sm:grid-cols-2 lg:grid-cols-3 dark:bg-gray-800/80">
            <li v-for="score in scores" :key="score.name + score.type" class="bg-white px-5 py-4 dark:bg-gray-900">
                <div class="flex items-baseline justify-between gap-3">
                    <span class="truncate text-sm font-medium text-gray-900 dark:text-white" v-tooltip.truncated="score.name">{{ score.name }}</span>
                    <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ formatNumber(score.count) }} {{ score.count === 1 ? 'score' : 'scores' }}</span>
                </div>

                <!-- Yes/no: the share of yes, as a bar -->
                <div v-if="score.type === 'boolean'" class="mt-3">
                    <div class="flex items-baseline gap-1.5">
                        <span class="text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ percent(score) }}%</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">yes</span>
                    </div>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-red-100 dark:bg-red-950/50" v-tooltip="`${percent(score)}% yes, ${100 - percent(score)}% no`">
                        <div class="h-full rounded-full bg-emerald-500" :style="{ width: percent(score) + '%' }"></div>
                    </div>
                </div>

                <!-- Numeric: the average and the range -->
                <div v-else-if="score.type === 'numeric'" class="mt-3 flex items-baseline gap-1.5">
                    <span class="text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ number(score.average) }}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">average · {{ number(score.minimum) }}–{{ number(score.maximum) }}</span>
                </div>

                <!-- Categories: the most common labels -->
                <div v-else class="mt-3 flex flex-wrap gap-1.5">
                    <span v-for="label in score.labels" :key="label.label" class="badge badge-secondary">
                        {{ label.label }}<span class="ml-1 tabular-nums text-gray-400">{{ formatNumber(label.count) }}</span>
                    </span>
                </div>
            </li>
        </ul>
    </div>
</template>
