<script setup lang="ts">
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useCharts } from '@/composables/useCharts';
import type { ThemedChart } from '@/composables/useCharts';
import { useHelpers } from '@/composables/useHelpers';

// One value per day. Cost values are cents, and the chart plots dollars.
const props = defineProps<{
    title: string;
    kind: 'cost' | 'requests';
    labels: string[];
    values: number[];
}>();

const { formatShortDate, formatCurrency } = useHelpers();
const { createBarChart, createLineChart } = useCharts();

const canvas = ref<HTMLCanvasElement | null>(null);
let chart: ThemedChart | null = null;

const render = () => {
    chart?.destroy();
    chart = null;

    if (!canvas.value || !props.values.length) return;

    const labels = props.labels.map((date) => formatShortDate(date));

    chart = props.kind === 'cost'
        ? createBarChart(canvas.value, { labels, values: props.values.map((cents) => cents / 100) }, {
            label: 'Cost',
            yTicks: { callback: (value: string | number) => formatCurrency(Number(value) * 100) },
            tooltipLabel: (dollars) => formatCurrency(dollars * 100),
        })
        : createLineChart(canvas.value, { labels, values: props.values }, { label: 'Requests' });
};

onMounted(render);
watch(() => [props.labels, props.values], async () => {
    await nextTick();
    render();
});
onUnmounted(() => chart?.destroy());
</script>

<template>
    <div class="card">
        <div class="card-header">
            <h3>{{ title }}</h3>
        </div>
        <div class="card-body">
            <div v-show="!values.length" class="empty-state py-12">
                <p class="empty-state-description">Nothing in this period</p>
            </div>
            <canvas v-show="values.length" ref="canvas" height="100"></canvas>
        </div>
    </div>
</template>
