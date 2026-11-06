<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { useLoading } from '@/composables/useLoading';
import type { DatasetsResponse } from '@/types/api';
import Skeleton from '@/components/Skeleton.vue';
import DocsLink from '@/components/DocsLink.vue';
import DatasetForm from './DatasetForm.vue';

// The datasets evaluations run over, with their number of examples, and a form
// to create one for users the annotateSpectra gate allows.
const router = useRouter();
const { formatNumber, apiRequest } = useHelpers();
const loading = useLoading();

loading.value = true;
const data = ref<DatasetsResponse>({ datasets: [], migrated: true, can_curate: false });

const creating = ref(false);

const formatDate = (iso: string | null) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '');

onMounted(async () => {
    try {
        data.value = await apiRequest<DatasetsResponse>('get', '/datasets');
    } catch (error) {
        console.error('Failed to load datasets:', error);
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div>
        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Datasets</h1>
                    <DocsLink page="datasets" />
                </div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">The test cases your evals run over</p>
            </div>
            <button v-if="data.can_curate" type="button" class="btn btn-secondary" :aria-expanded="creating" @click="creating = !creating">{{ creating ? 'Close' : 'New dataset' }}</button>
        </div>

        <div v-if="!loading && !data.migrated" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            Run <code class="font-mono">php artisan spectra:install</code> to create the dataset and evaluation tables.
        </div>

        <DatasetForm v-if="creating" @saved="(id) => router.push({ name: 'dataset-detail', params: { id } })" />

        <div v-if="loading" class="card"><Skeleton variant="table-row" :lines="6" /></div>

        <div v-else-if="!data.datasets.length && !creating" class="card">
            <div class="empty-state">
                <p class="empty-state-title">No datasets yet</p>
                <p class="empty-state-description">
                    Create one here, add examples with <code class="font-mono">Spectra::dataset('refund-questions')->add($input, $expected)</code>, or save a call from its page.
                </p>
                <a href="https://spectraphp.com/datasets" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300">
                    Read the docs
                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.22 14.78a.75.75 0 0 0 1.06 0l7.22-7.22v5.69a.75.75 0 0 0 1.5 0v-7.5a.75.75 0 0 0-.75-.75h-7.5a.75.75 0 0 0 0 1.5h5.69l-7.22 7.22a.75.75 0 0 0 0 1.06Z" clip-rule="evenodd" /></svg>
                </a>
            </div>
        </div>

        <div v-else-if="data.datasets.length" class="card overflow-hidden">
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
                            <router-link :to="{ name: 'dataset-detail', params: { id: dataset.id } }" class="font-mono font-medium text-gray-900 hover:text-primary-600 dark:text-white dark:hover:text-primary-400">{{ dataset.name }}</router-link>
                            <p v-if="dataset.description" class="text-xs text-gray-500 dark:text-gray-400">{{ dataset.description }}</p>
                        </td>
                        <td class="text-right tabular-nums">{{ formatNumber(dataset.example_count) }}</td>
                        <td class="text-right text-xs text-gray-500 dark:text-gray-400">{{ formatDate(dataset.updated_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
