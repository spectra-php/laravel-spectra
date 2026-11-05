<script setup>
import { ref, computed, onMounted } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import { useLoading } from '@/composables/useLoading';
import { useEvalFormat } from '@/composables/useEvalFormat';
import Skeleton from '@/components/Skeleton.vue';

// Evaluations and their runs, and the datasets they run over.
const { formatNumber, formatDuration, apiRequest } = useHelpers();
const { summaryText } = useEvalFormat();
const loading = useLoading();

loading.value = true;
const data = ref({ evaluations: [], datasets: [], migrated: true });

const scoreNames = (evaluation) => [...new Set(evaluation.runs.flatMap((run) => Object.keys(run.summary || {})))];
const formatDate = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '');
const isEmpty = computed(() => !data.value.evaluations.length && !data.value.datasets.length);

onMounted(async () => {
    try {
        data.value = await apiRequest('get', '/evals');
    } catch (error) {
        console.error('Failed to load evaluations:', error);
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div>
        <div class="mb-6">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Evals</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Run your AI code over a dataset and compare the runs</p>
        </div>

        <div v-if="!loading && !data.migrated" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            Run <code class="font-mono">php artisan spectra:install</code> to create the dataset and evaluation tables.
        </div>

        <div v-if="loading" class="card"><Skeleton variant="table-row" :lines="6" /></div>

        <div v-else-if="isEmpty" class="card">
            <div class="empty-state">
                <p class="empty-state-title">No evaluations yet</p>
                <p class="empty-state-description">
                    Add examples with <code class="font-mono">Spectra::dataset('refund-questions')->add($input, $expected)</code>, write a class that extends
                    <code class="font-mono">Spectra\Evaluations\Evaluation</code>, and run it with <code class="font-mono">php artisan spectra:eval</code>.
                </p>
            </div>
        </div>

        <div v-else class="space-y-4">
            <div v-for="evaluation in data.evaluations" :key="evaluation.name" class="card overflow-hidden">
                <div class="card-header">
                    <div>
                        <h3 class="font-mono">{{ evaluation.name }}</h3>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            Over <span class="font-mono">{{ evaluation.dataset }}</span> · {{ evaluation.runs.length }} {{ evaluation.runs.length === 1 ? 'run' : 'runs' }}, newest first
                        </p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Run</th>
                                <th class="text-right">Examples</th>
                                <th class="text-right">Errors</th>
                                <th v-for="name in scoreNames(evaluation)" :key="name" class="text-right font-mono normal-case">{{ name }}</th>
                                <th class="text-right">Took</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="run in evaluation.runs" :key="run.id">
                                <td>
                                    <router-link :to="{ name: 'eval-run', params: { id: run.id } }" class="font-medium text-gray-900 hover:text-primary-600 dark:text-white dark:hover:text-primary-400">{{ formatDate(run.started_at) }}</router-link>
                                    <span v-if="run.status !== 'completed'" class="badge ml-2" :class="run.status === 'failed' ? 'badge-danger' : 'badge-warning'">{{ run.status }}</span>
                                </td>
                                <td class="text-right tabular-nums">{{ formatNumber(run.example_count) }}</td>
                                <td class="text-right tabular-nums" :class="run.error_count ? 'text-red-600 dark:text-red-400' : 'text-gray-400'">{{ formatNumber(run.error_count) }}</td>
                                <td v-for="name in scoreNames(evaluation)" :key="name" class="text-right tabular-nums">{{ summaryText(run.summary[name]) }}</td>
                                <td class="text-right tabular-nums text-gray-500 dark:text-gray-400">{{ run.duration_ms !== null ? formatDuration(run.duration_ms) : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="data.datasets.length" class="card overflow-hidden">
                <div class="card-header"><h3>Datasets</h3></div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Dataset</th>
                            <th class="text-right">Examples</th>
                            <th class="text-right">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="dataset in data.datasets" :key="dataset.id">
                            <td>
                                <router-link :to="{ name: 'eval-dataset', params: { id: dataset.id } }" class="font-mono font-medium text-gray-900 hover:text-primary-600 dark:text-white dark:hover:text-primary-400">{{ dataset.name }}</router-link>
                                <p v-if="dataset.description" class="text-xs text-gray-500 dark:text-gray-400">{{ dataset.description }}</p>
                            </td>
                            <td class="text-right tabular-nums">{{ formatNumber(dataset.example_count) }}</td>
                            <td class="text-right text-xs text-gray-500 dark:text-gray-400">{{ formatDate(dataset.updated_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
