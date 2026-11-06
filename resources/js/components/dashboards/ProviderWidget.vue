<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useCharts } from '@/composables/useCharts';
import type { ThemedChart } from '@/composables/useCharts';
import { useHelpers } from '@/composables/useHelpers';
import Money from '@/components/Money.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import type { DashboardUsageRow } from '@/types/api';

const props = defineProps<{ rows: DashboardUsageRow[]; showCosts: boolean }>();

const { formatNumber, providerChartColor } = useHelpers();
const { createDoughnutChart } = useCharts();

const canvas = ref<HTMLCanvasElement | null>(null);
let chart: ThemedChart | null = null;

const legend = computed(() => {
    const total = props.rows.reduce((sum, row) => sum + row.requests, 0) || 1;

    return props.rows.map((row) => ({
        ...row,
        share: (row.requests / total) * 100,
        color: providerChartColor(row.provider),
    }));
});

const render = () => {
    chart?.destroy();
    chart = null;

    if (!canvas.value || !props.rows.length) return;

    chart = createDoughnutChart(canvas.value, {
        labels: props.rows.map((row) => row.provider_name),
        values: props.rows.map((row) => row.requests),
        colors: () => props.rows.map((row) => providerChartColor(row.provider)),
    }, {
        legend: false,
        chartOptions: { animation: false },
    });
};

onMounted(render);
watch(() => props.rows, async () => {
    await nextTick();
    render();
});
onUnmounted(() => chart?.destroy());
</script>

<template>
    <div class="card flex h-full flex-col">
        <div class="card-header">
            <h3>Requests by provider</h3>
        </div>
        <div class="card-body flex flex-1 flex-col gap-5">
            <div v-show="!rows.length" class="empty-state py-12">
                <p class="empty-state-description">Nothing in this period</p>
            </div>
            <div v-show="rows.length" class="mx-auto w-40">
                <canvas ref="canvas"></canvas>
            </div>
            <ul class="space-y-2 text-sm">
                <li v-for="row in legend" :key="row.provider ?? ''" class="flex items-center gap-2.5">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-xs" :style="{ backgroundColor: row.color }" aria-hidden="true"></span>
                    <ProviderLogo
                        :provider="row.provider"
                        class="inline-flex h-3.5 w-3.5 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full"
                        aria-hidden="true"
                    />
                    <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-300">{{ row.provider_name }}</span>
                    <span class="tabular-nums text-gray-900 dark:text-white">{{ formatNumber(row.requests) }}</span>
                    <span class="w-12 text-right text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ row.share.toFixed(1) }}%</span>
                    <span v-if="showCosts" class="w-20 text-right tabular-nums text-gray-900 dark:text-white"><Money :cents="row.cost ?? 0" /></span>
                </li>
            </ul>
        </div>
    </div>
</template>
