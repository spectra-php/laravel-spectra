<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useCharts } from '@/composables/useCharts';
import type { ThemedChart } from '@/composables/useCharts';
import { useHelpers } from '@/composables/useHelpers';
import Skeleton from '@/components/Skeleton.vue';

// A chart of one value per day, on the Overview and on a shared dashboard. Cost
// values are cents, and the chart plots dollars.
const props = withDefaults(defineProps<{
    title: string;
    description: string;
    kind: 'cost' | 'requests';
    labels: string[];
    values: number[];
    loading?: boolean;
}>(), {
    loading: false,
});

const { formatShortDate, formatCurrency } = useHelpers();
const { createBarChart, createLineChart } = useCharts();

const canvas = ref<HTMLCanvasElement | null>(null);
let chart: ThemedChart | null = null;

const render = () => {
    chart?.destroy();
    chart = null;

    if (!canvas.value || props.loading) return;

    const labels = props.labels.map((date) => formatShortDate(date));

    chart = props.kind === 'cost'
        ? createBarChart(canvas.value, { labels, values: props.values.map((cents) => cents / 100) }, {
            label: 'Cost',
            yTicks: { callback: (value: string | number) => formatCurrency(Number(value) * 100) },
            tooltipLabel: (dollars) => formatCurrency(dollars * 100),
            chartOptions: { aspectRatio: 2.6 },
        })
        : createLineChart(canvas.value, { labels, values: props.values }, {
            label: 'Requests',
            chartOptions: { aspectRatio: 2.6 },
        });
};

onMounted(render);
// After the DOM updates, so the canvas is visible and gets a real size.
watch(() => [props.labels, props.values, props.loading], async () => {
    await nextTick();
    render();
});
onUnmounted(() => chart?.destroy());
</script>

<template>
    <div class="card">
        <div class="card-header">
            <div>
                <h3>{{ title }}</h3>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ description }}</p>
            </div>
        </div>
        <div class="card-body">
            <Skeleton v-if="loading" variant="chart" height="240px" />
            <canvas v-show="!loading" ref="canvas"></canvas>
        </div>
    </div>
</template>
