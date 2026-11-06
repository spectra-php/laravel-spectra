<script setup lang="ts">
import { ref, onMounted } from 'vue';
import type { Ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { useLoading } from '@/composables/useLoading';
import { useEvalFormat } from '@/composables/useEvalFormat';
import type { DatasetExample, DatasetResponse } from '@/types/api';
import Skeleton from '@/components/Skeleton.vue';
import DatasetForm from './DatasetForm.vue';
import ExampleForm from './ExampleForm.vue';

// One dataset: its examples and the evaluation runs over it. The annotateSpectra
// gate lets users change or delete the dataset and its examples.
const route = useRoute();
const router = useRouter();
const { formatNumber, apiRequest, alertError } = useHelpers();
const { text, summaryText } = useEvalFormat();
const loading = useLoading();

loading.value = true;
// The cast skips Ref's deep unwrap, which can't resolve the recursive JsonValue here.
const data = ref<DatasetResponse | null>(null) as Ref<DatasetResponse | null>;

const adding = ref(false);
const editing = ref<string | null>(null);
const confirmingDelete = ref<string | null>(null);
const editingDataset = ref(false);
const confirmingDatasetDelete = ref(false);

const formatDate = (iso: string | null) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '');
const exampleOrigin = (example: DatasetExample) => (example.metadata.source === 'dashboard' ? 'Dashboard' : 'Code');

const load = async () => {
    try {
        data.value = await apiRequest<DatasetResponse>('get', `/datasets/${encodeURIComponent(route.params.id as string)}`);
    } catch (error) {
        console.error('Failed to load the dataset:', error);
    } finally {
        loading.value = false;
    }
};

const startEditing = (example: DatasetExample) => {
    confirmingDelete.value = null;
    editing.value = example.id;
};

const saved = async () => {
    editing.value = null;
    await load();
};

const remove = async (example: DatasetExample) => {
    try {
        await apiRequest('delete', `/datasets/${encodeURIComponent(route.params.id as string)}/examples/${encodeURIComponent(example.id)}`);
        confirmingDelete.value = null;
        await load();
    } catch {
        alertError('The example could not be deleted.');
    }
};

const datasetSaved = async () => {
    editingDataset.value = false;
    await load();
};

const askToDeleteDataset = () => {
    editingDataset.value = false;
    confirmingDatasetDelete.value = true;
};

const removeDataset = async () => {
    try {
        await apiRequest('delete', `/datasets/${encodeURIComponent(route.params.id as string)}`);
        await router.push({ name: 'datasets' });
    } catch {
        alertError('The dataset could not be deleted.');
    }
};

onMounted(async () => {
    await load();
    // A new dataset has nothing to show yet, so start with the form.
    adding.value = data.value !== null && data.value.can_curate && !data.value.examples.length;
});
</script>

<template>
    <div>
        <div v-if="loading && !data" class="card"><Skeleton variant="table-row" :lines="8" /></div>

        <template v-else-if="data">
            <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        <router-link :to="{ name: 'datasets' }" class="hover:text-primary-600 dark:hover:text-primary-400">Datasets</router-link>
                    </p>
                    <h1 class="mt-1 break-all font-mono text-xl font-semibold text-gray-900 dark:text-white">{{ data.dataset.name }}</h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ data.dataset.description || `${formatNumber(data.dataset.example_count)} examples` }}</p>
                </div>
                <div v-if="data.can_curate && confirmingDatasetDelete" class="flex flex-wrap items-center gap-2">
                    <span class="text-xs text-gray-500 dark:text-gray-400">Delete it and its {{ formatNumber(data.dataset.example_count) }} {{ data.dataset.example_count === 1 ? 'example' : 'examples' }}? Past runs stay.</span>
                    <button type="button" class="btn btn-secondary" @click="confirmingDatasetDelete = false">Keep</button>
                    <button type="button" class="btn btn-primary" @click="removeDataset">Delete</button>
                </div>
                <div v-else-if="data.can_curate" class="flex items-center gap-2">
                    <button type="button" class="btn btn-secondary" :aria-expanded="editingDataset" @click="editingDataset = !editingDataset">{{ editingDataset ? 'Close' : 'Edit' }}</button>
                    <button type="button" class="btn btn-secondary" @click="askToDeleteDataset">Delete</button>
                </div>
            </div>

            <DatasetForm v-if="editingDataset" :dataset="data.dataset" @saved="datasetSaved" @cancel="editingDataset = false" />

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
                <div class="card-header">
                    <h3>Examples</h3>
                    <button v-if="data.can_curate" type="button" class="btn btn-secondary" :aria-expanded="adding" @click="adding = !adding">{{ adding ? 'Close' : 'Add example' }}</button>
                </div>
                <ExampleForm v-if="adding" :dataset-id="data.dataset.id" :latest-input="data.latest_input" @saved="load" />
                <div v-if="!data.examples.length && !adding" class="empty-state">
                    <p class="empty-state-title">No examples</p>
                    <p class="empty-state-description">Add some with <code class="font-mono">Spectra::dataset('{{ data.dataset.name }}')->add($input, $expected)</code>, or from a call's page.</p>
                </div>
                <div v-else-if="data.examples.length" class="overflow-x-auto">
                    <table class="table table-fixed">
                        <colgroup>
                            <col class="w-[5%]" />
                            <col class="w-[38%]" />
                            <col class="w-[34%]" />
                            <col class="w-[9%]" />
                            <col v-if="data.can_curate" class="w-[14%]" />
                        </colgroup>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Input</th>
                                <th>Expected</th>
                                <th class="text-right">From</th>
                                <th v-if="data.can_curate"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="(example, index) in data.examples" :key="example.id">
                                <tr v-if="editing === example.id">
                                    <td colspan="5" class="!p-0">
                                        <ExampleForm :dataset-id="data.dataset.id" :example="example" @saved="saved" @cancel="editing = null" />
                                    </td>
                                </tr>
                                <tr v-else class="align-top">
                                    <td class="tabular-nums text-gray-400">{{ index + 1 }}</td>
                                    <td><p class="line-clamp-4 whitespace-pre-wrap wrap-break-word text-sm" v-tooltip.truncated="text(example.input)">{{ text(example.input) }}</p></td>
                                    <td><p class="line-clamp-4 whitespace-pre-wrap wrap-break-word text-sm text-gray-600 dark:text-gray-300">{{ text(example.expected) || '—' }}</p></td>
                                    <td class="text-right text-xs">
                                        <router-link v-if="example.source_request_id" :to="{ name: 'request-preview', params: { id: example.source_request_id } }" class="text-primary-600 hover:underline dark:text-primary-400">A call</router-link>
                                        <span v-else class="text-gray-400">{{ exampleOrigin(example) }}</span>
                                    </td>
                                    <td v-if="data.can_curate" class="whitespace-nowrap text-right text-xs font-medium">
                                        <div v-if="confirmingDelete === example.id" class="inline-flex items-center gap-3">
                                            <button type="button" class="text-primary-600 hover:underline dark:text-primary-400" @click="confirmingDelete = null">Keep</button>
                                            <button type="button" class="text-red-600 hover:underline dark:text-red-400" @click="remove(example)">Delete</button>
                                        </div>
                                        <div v-else class="inline-flex items-center gap-3 text-primary-600 dark:text-primary-400">
                                            <button type="button" class="hover:underline" @click="startEditing(example)">Edit</button>
                                            <button type="button" class="hover:underline" @click="confirmingDelete = example.id">Delete</button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>
</template>
