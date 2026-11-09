<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useCharts } from '@/composables/useCharts';
import type { ThemedChart } from '@/composables/useCharts';
import { useHelpers } from '@/composables/useHelpers';
import ProviderLogo from '@/components/ProviderLogo.vue';

// The share of requests by provider or by model type, on the Overview and on a
// shared dashboard. Each page brings its own provider names, because a viewer
// can't load them.
const props = withDefaults(defineProps<{
    providers: { provider: string | null; label: string | null; count: number }[];
    modelTypes?: { model_type: string; label: string; count: number }[];
    description?: string;
    // How a row's value reads: a count by default, an amount on the costs page.
    formatValue?: (value: number) => string;
    showTypes?: boolean;
    loading?: boolean;
}>(), {
    description: 'Share of requests',
    formatValue: undefined,
    modelTypes: () => [],
    showTypes: true,
    loading: false,
});

const { formatNumber, providerChartColor, seriesColor } = useHelpers();
const { createDoughnutChart } = useCharts();

const tab = ref<'provider' | 'model-type'>('provider');
const providerCanvas = ref<HTMLCanvasElement | null>(null);
const typeCanvas = ref<HTMLCanvasElement | null>(null);
let providerChart: ThemedChart | null = null;
let typeChart: ThemedChart | null = null;

const render = () => {
    providerChart?.destroy();
    typeChart?.destroy();
    providerChart = null;
    typeChart = null;

    if (props.loading) return;

    if (providerCanvas.value) {
        providerChart = createDoughnutChart(providerCanvas.value, {
            labels: props.providers.map((row) => row.label),
            values: props.providers.map((row) => row.count),
            colors: () => props.providers.map((row) => providerChartColor(row.provider)),
        }, { legend: false, chartOptions: { animation: false } });
    }

    if (typeCanvas.value) {
        typeChart = createDoughnutChart(typeCanvas.value, {
            labels: props.modelTypes.map((row) => row.label),
            values: props.modelTypes.map((row) => row.count),
            colors: () => props.modelTypes.map((_, index) => seriesColor(index)),
        }, { legend: false, chartOptions: { animation: false } });
    }
};

// Legend rows: label, count, share and color.
const rows = computed(() => {
    const byProvider = tab.value === 'provider';
    const source = byProvider
        ? props.providers.map((row) => ({ key: row.provider, label: row.label, provider: row.provider, count: Number(row.count || 0) }))
        : props.modelTypes.map((row) => ({ key: row.model_type, label: row.label, provider: null, count: Number(row.count || 0) }));
    const total = source.reduce((sum, row) => sum + row.count, 0) || 1;

    return source.map((row, index) => ({
        ...row,
        share: (row.count / total) * 100,
        color: byProvider ? providerChartColor(row.provider) : seriesColor(index),
    }));
});

onMounted(render);
// After the DOM updates, so the canvases are visible and get a real size.
watch(() => [props.providers, props.modelTypes, props.loading, tab.value], async () => {
    await nextTick();
    render();
});
onUnmounted(() => {
    providerChart?.destroy();
    typeChart?.destroy();
});
</script>

<template>
    <div class="card flex h-full flex-col">
        <div class="card-header flex-wrap gap-y-2.5">
            <div>
                <h3>Breakdown</h3>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ description }}</p>
            </div>
            <div class="card-actions">
                <div class="segmented">
                    <button type="button" :class="{ 'is-active': tab === 'provider' }" @click="tab = 'provider'">Provider</button>
                    <button v-if="showTypes" type="button" :class="{ 'is-active': tab === 'model-type' }" @click="tab = 'model-type'">Type</button>
                </div>
                <slot name="action" />
            </div>
        </div>
        <div class="card-body flex flex-1 flex-col gap-5">
            <div v-if="loading" class="flex h-40 items-center justify-center">
                <div class="h-32 w-32 animate-pulse rounded-full bg-gray-100 dark:bg-gray-800"></div>
            </div>
            <div v-show="!loading" class="mx-auto w-40">
                <div v-show="tab === 'provider'"><canvas ref="providerCanvas"></canvas></div>
                <div v-show="tab === 'model-type'"><canvas ref="typeCanvas"></canvas></div>
            </div>
            <ul v-if="!loading" class="space-y-2 text-sm">
                <li v-for="row in rows" :key="row.key as string" class="flex items-center gap-2.5">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-xs" :style="{ backgroundColor: row.color }" aria-hidden="true"></span>
                    <ProviderLogo
                        v-if="row.provider"
                        :provider="row.provider"
                        class="inline-flex h-3.5 w-3.5 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full"
                        aria-hidden="true"
                    />
                    <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-300">{{ row.label }}</span>
                    <span class="tabular-nums text-gray-900 dark:text-white">{{ formatValue ? formatValue(row.count) : formatNumber(row.count) }}</span>
                    <span class="w-12 text-right tabular-nums text-xs text-gray-500 dark:text-gray-400">{{ row.share.toFixed(1) }}%</span>
                </li>
                <li v-if="!rows.length" class="py-6 text-center text-gray-500 dark:text-gray-400">No requests in this period</li>
            </ul>
        </div>
    </div>
</template>
