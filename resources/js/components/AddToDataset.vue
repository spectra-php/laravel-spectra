<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import DocsLink from '@/components/DocsLink.vue';
import type { AddDatasetExampleResponse, DatasetsResponse } from '@/types/api';
import type { ApiError } from '@/types/app';

// Save a call as an example of a dataset, to run evaluations over: its last
// user message as the input, and its reply as the expected output if asked.
const props = defineProps<{
    requestId: string;
}>();

const { apiRequest } = useHelpers();

const available = ref(false);
const datasets = ref<string[]>([]);
const name = ref('');
const replyIsExpected = ref(true);
const saving = ref(false);
const added = ref<{ name: string; id: string } | null>(null);
const error = ref('');

onMounted(async () => {
    try {
        const data = await apiRequest<DatasetsResponse>('get', '/datasets');
        available.value = data.migrated && data.can_curate;
        datasets.value = data.datasets.map((dataset) => dataset.name);
        name.value = datasets.value[0] || '';
    } catch {
        available.value = false;
    }
});

const add = async () => {
    if (!name.value.trim() || saving.value) return;
    saving.value = true;
    error.value = '';
    try {
        const result = await apiRequest<AddDatasetExampleResponse>('post', '/datasets/examples', { dataset: name.value.trim(), request_id: props.requestId, reply_is_expected: replyIsExpected.value });
        added.value = { name: name.value.trim(), id: result.dataset_id };
    } catch (e) {
        error.value = (e as ApiError)?.response?.data?.message || 'The call could not be added.';
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <div v-if="available" class="card">
        <div class="card-header">
            <div class="-my-0.5 flex items-center gap-2">
                <h3>Add to dataset</h3>
                <DocsLink page="datasets" label="What is a dataset? Read the docs" />
            </div>
        </div>
        <form class="card-body space-y-3" @submit.prevent="add">
            <div>
                <label for="dataset-name" class="mb-1 block text-xs text-gray-500 dark:text-gray-400">Dataset, new or existing</label>
                <input id="dataset-name" v-model="name" list="dataset-names" maxlength="100" class="form-input w-full" placeholder="refund-questions" />
                <datalist id="dataset-names">
                    <option v-for="dataset in datasets" :key="dataset" :value="dataset" />
                </datalist>
            </div>
            <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                <input v-model="replyIsExpected" type="checkbox" class="rounded-sm border-gray-300 dark:border-gray-600" />
                Use the reply as the expected output
            </label>
            <p v-if="error" class="text-xs text-red-600 dark:text-red-400">{{ error }}</p>
            <p v-if="added" class="text-xs text-gray-600 dark:text-gray-300">
                Added to <router-link :to="{ name: 'dataset-detail', params: { id: added.id } }" class="font-mono text-primary-600 hover:underline dark:text-primary-400">{{ added.name }}</router-link>.
            </p>
            <button type="submit" class="btn btn-secondary w-full" :disabled="saving || !name.trim()">Add the call</button>
        </form>
    </div>
</template>
