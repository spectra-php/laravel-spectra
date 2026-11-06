<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import type { StoreDatasetResponse } from '@/types/api';
import type { ApiError } from '@/types/app';

// Create a dataset, or change one's name and description. Renaming warns about
// code, which finds a dataset by name.
const props = defineProps<{
    dataset?: { id: string; name: string; description: string | null };
}>();

const emit = defineEmits<{ saved: [id: string]; cancel: [] }>();

const { apiRequest } = useHelpers();

const nameInput = ref<HTMLInputElement | null>(null);
const name = ref(props.dataset?.name ?? '');
const description = ref(props.dataset?.description ?? '');
const saving = ref(false);
const error = ref('');

const renaming = computed(() => props.dataset !== undefined && name.value.trim() !== props.dataset.name);

onMounted(() => nameInput.value?.focus());

const save = async () => {
    if (!name.value.trim() || saving.value) return;
    saving.value = true;
    error.value = '';
    const body = { name: name.value.trim(), description: description.value.trim() || null };
    try {
        if (props.dataset) {
            await apiRequest('put', `/datasets/${encodeURIComponent(props.dataset.id)}`, body);
            emit('saved', props.dataset.id);
        } else {
            const created = await apiRequest<StoreDatasetResponse>('post', '/datasets', body);
            emit('saved', created.id);
        }
    } catch (e) {
        error.value = (e as ApiError)?.response?.data?.message || 'The dataset could not be saved.';
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <form class="card card-body mb-4 space-y-3" @submit.prevent="save">
        <div class="grid gap-3 md:grid-cols-2">
            <div>
                <label for="dataset-name-field" class="mb-1 block text-xs text-gray-500 dark:text-gray-400">Name</label>
                <input id="dataset-name-field" ref="nameInput" v-model="name" maxlength="100" class="form-input w-full font-mono" placeholder="refund-questions" />
            </div>
            <div>
                <label for="dataset-description-field" class="mb-1 block text-xs text-gray-500 dark:text-gray-400">Description, optional</label>
                <input id="dataset-description-field" v-model="description" maxlength="1000" class="form-input w-full" placeholder="Refund questions customers asked" />
            </div>
        </div>
        <p v-if="renaming" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            Your code finds a dataset by name. Change it in any <code class="font-mono">Spectra::dataset()</code> call and evaluation's <code class="font-mono">dataset()</code> too, or evaluations will fail and new examples will go to a new dataset. Past runs move to the new name.
        </p>
        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary" :disabled="saving || !name.trim()">{{ dataset ? 'Save' : 'Create dataset' }}</button>
            <button v-if="dataset" type="button" class="btn btn-secondary" @click="emit('cancel')">Cancel</button>
            <p v-if="error" class="text-xs text-red-600 dark:text-red-400">{{ error }}</p>
        </div>
    </form>
</template>
