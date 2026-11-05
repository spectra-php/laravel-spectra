<script setup>
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { useLoading } from '@/composables/useLoading';
import { useEvalFormat } from '@/composables/useEvalFormat';
import Skeleton from '@/components/Skeleton.vue';

// One dataset: its examples, and the evaluation runs over it.
const route = useRoute();
const { formatNumber, apiRequest } = useHelpers();
const { text, summaryText } = useEvalFormat();
const loading = useLoading();

loading.value = true;
const data = ref(null);

const formatDate = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '');

onMounted(async () => {
    try {
        data.value = await apiRequest('get', `/datasets/${encodeURIComponent(route.params.id)}`);
    } catch (error) {
        console.error('Failed to load the dataset:', error);
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div>
        <div v-if="loading && !data" class="card"><Skeleton variant="table-row" :lines="8" /></div>

        <template v-else-if="data">
            <div class="mb-6">
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    <router-link :to="{ name: 'evals' }" class="hover:text-primary-600 dark:hover:text-primary-400">Evals</router-link>
                    <span class="mx-1.5">/</span>Datasets
                </p>
                <h1 class="mt-1 font-mono text-xl font-semibold text-gray-900 dark:text-white">{{ data.dataset.name }}</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ data.dataset.description || `${formatNumber(data.dataset.example_count)} examples` }}</p>
            </div>

            <div v-if="data.runs.length" class="card mb-4 overflow-hidden">
                <div class="card-header"><h3>Runs over this dataset</h3></div>
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    <li v-for="run in data.runs" :key="run.id" class="flex flex-wrap items-center justify-between gap-2 px-5 py-2.5 text-sm">
                        <router-link :to="{ name: 'eval-run', params: { id: run.id } }" class="font-mono text-gray-900 hover:text-primary-600 dark:text-white dark:hover:text-primary-400">{{ run.evaluation }}</router-link>
                        <span class="flex flex-wrap gap-3 text-xs text-gray-500 dark:text-gray-400">
                            <span v-for="(summary, name) in run.summary" :key="name"><span class="font-mono">{{ name }}</span> {{ summaryText(summary) }}</span>
                            <span>{{ formatDate(run.started_at) }}</span>
                        </span>
                    </li>
                </ul>
            </div>

            <div class="card overflow-hidden">
                <div class="card-header"><h3>Examples</h3></div>
                <div v-if="!data.examples.length" class="empty-state">
                    <p class="empty-state-title">No examples</p>
                    <p class="empty-state-description">Add some with <code class="font-mono">Spectra::dataset('{{ data.dataset.name }}')->add($input, $expected)</code>, or from a call's page.</p>
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="table table-fixed">
                        <colgroup>
                            <col class="w-[5%]" />
                            <col class="w-[42%]" />
                            <col class="w-[38%]" />
                            <col class="w-[15%]" />
                        </colgroup>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Input</th>
                                <th>Expected</th>
                                <th class="text-right">From</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(example, index) in data.examples" :key="example.id" class="align-top">
                                <td class="tabular-nums text-gray-400">{{ index + 1 }}</td>
                                <td><p class="line-clamp-4 whitespace-pre-wrap break-words text-sm" :title="text(example.input)">{{ text(example.input) }}</p></td>
                                <td><p class="line-clamp-4 whitespace-pre-wrap break-words text-sm text-gray-600 dark:text-gray-300">{{ text(example.expected) || '—' }}</p></td>
                                <td class="text-right text-xs">
                                    <router-link v-if="example.source_request_id" :to="{ name: 'request-preview', params: { id: example.source_request_id } }" class="text-primary-600 hover:underline dark:text-primary-400">A call</router-link>
                                    <span v-else class="text-gray-400">Code</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>
</template>
