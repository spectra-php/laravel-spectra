<script setup lang="ts">
import { computed } from 'vue';

/**
 * A tiny trend line for stat cards. Shape only — no axes; the card's number
 * carries the value.
 */
const props = withDefaults(defineProps<{
    values?: number[];
    height?: number;
}>(), {
    values: () => [],
    height: 32,
});

const uid = Math.random().toString(36).slice(2, 8);

const points = computed(() => {
    const values = props.values.map((value) => Number(value) || 0);

    if (values.length < 2) return null;

    const max = Math.max(...values);
    const min = Math.min(...values);
    const span = max - min || 1;
    const step = 100 / (values.length - 1);

    return values.map((value, index) => [index * step, 2 + (1 - (value - min) / span) * (props.height - 4)]);
});

const line = computed(() => points.value?.map(([x, y], i) => `${i === 0 ? 'M' : 'L'}${x.toFixed(2)},${y.toFixed(2)}`).join(' '));
const area = computed(() => (line.value ? `${line.value} L100,${props.height} L0,${props.height} Z` : null));
</script>

<template>
    <svg
        v-if="points"
        :viewBox="`0 0 100 ${height}`"
        preserveAspectRatio="none"
        class="block w-full text-primary-500 dark:text-primary-400"
        :style="{ height: height + 'px' }"
        aria-hidden="true"
    >
        <defs>
            <linearGradient :id="`spark-${uid}`" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="currentColor" stop-opacity="0.18" />
                <stop offset="1" stop-color="currentColor" stop-opacity="0" />
            </linearGradient>
        </defs>
        <path :d="area!" :fill="`url(#spark-${uid})`" />
        <path :d="line" fill="none" stroke="currentColor" stroke-width="1.5" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round" />
    </svg>
</template>
