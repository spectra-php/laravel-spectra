<script setup lang="ts">
import { reactive, ref, onMounted } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import type { DatasetExample, JsonValue } from '@/types/api';
import type { ApiError } from '@/types/app';
import JsonEditor from '../playground/JsonEditor.vue';
import { blankInput, textShape } from './exampleInput';

// Write a new example, or change one. Each box holds text or, switched over,
// JSON. A new input starts in the shape of the dataset's newest example.
const props = defineProps<{
    datasetId: string;
    example?: DatasetExample;
    latestInput?: JsonValue;
}>();

const emit = defineEmits<{ saved: []; cancel: [] }>();

const { apiRequest } = useHelpers();

interface Field {
    json: boolean;
    text: string;
    key: string | null;
    error: string;
}

const pretty = (value: JsonValue) => JSON.stringify(value, null, 2);

// The box for a value that's already stored.
const storedField = (value: JsonValue, shape: { key: string | null } | null): Field => {
    if (value === null) return { json: false, text: '', key: shape?.key ?? null, error: '' };
    if (shape === null) return { json: true, text: pretty(value), key: null, error: '' };
    const text = shape.key === null ? (value as string) : (value as Record<string, string>)[shape.key];
    return { json: false, text, key: shape.key, error: '' };
};

// The box for a new input, shaped like the input it follows.
const newInputField = (latest: JsonValue | undefined): Field => {
    if (latest === null || latest === undefined) return { json: false, text: '', key: 'prompt', error: '' };
    const shape = textShape(latest);
    if (shape === null) return { json: true, text: pretty(blankInput(latest)), key: null, error: '' };
    return { json: false, text: '', key: shape.key, error: '' };
};

const expectedShape = (value: JsonValue) => (typeof value === 'string' ? { key: null } : null);

const input = reactive<Field>(props.example ? storedField(props.example.input, textShape(props.example.input)) : newInputField(props.latestInput));
const expected = reactive<Field>(storedField(props.example?.expected ?? null, expectedShape(props.example?.expected ?? null)));

const fields = [
    { name: 'input', label: 'Input', field: input, shape: textShape, placeholder: 'How long does a refund take?' },
    { name: 'expected', label: 'Expected output, optional', field: expected, shape: expectedShape, placeholder: 'Five to ten business days.' },
];

const domId = (name: string) => `example-${props.example?.id ?? 'new'}-${name}`;
const focusInput = () => document.getElementById(domId('input'))?.focus();

const saving = ref(false);
const added = ref(false);
const error = ref('');

onMounted(focusInput);

// The box's value; null when it's empty. A JSON box that doesn't parse throws.
const valueOf = (field: Field): JsonValue => {
    if (field.text.trim() === '') return null;
    if (field.json) return JSON.parse(field.text) as JsonValue;
    return field.key === null ? field.text : { [field.key]: field.text };
};

const toggleMode = (field: Field, shape: (value: JsonValue) => { key: string | null } | null) => {
    field.error = '';

    try {
        const value = valueOf(field);

        if (!field.json) {
            field.text = value === null ? '' : pretty(value);
            field.json = true;
            return;
        }

        const fits = value === null ? { key: field.key } : shape(value);

        if (fits === null) {
            field.error = 'This value has more than text in it, so it stays as JSON.';
            return;
        }

        Object.assign(field, storedField(value, fits), { json: false });
    } catch {
        field.error = 'Fix the JSON before switching to text.';
    }
};

const save = async () => {
    if (saving.value) return;
    added.value = false;
    error.value = '';
    input.error = '';
    expected.error = '';

    let values: { input: JsonValue; expected: JsonValue };

    try {
        values = { input: valueOf(input), expected: null };
    } catch {
        input.error = 'This isn\'t valid JSON.';
        return;
    }

    try {
        values.expected = valueOf(expected);
    } catch {
        expected.error = 'This isn\'t valid JSON.';
        return;
    }

    if (values.input === null) {
        input.error = 'Write the input first.';
        return;
    }

    saving.value = true;

    try {
        const base = `/datasets/${encodeURIComponent(props.datasetId)}/examples`;

        if (props.example) {
            await apiRequest('put', `${base}/${encodeURIComponent(props.example.id)}`, values);
        } else {
            await apiRequest('post', base, values);
            // The next example follows the one just added.
            Object.assign(input, newInputField(values.input));
            Object.assign(expected, storedField(null, null));
            added.value = true;
            focusInput();
        }

        emit('saved');
    } catch (e) {
        error.value = (e as ApiError)?.response?.data?.message || 'The example could not be saved.';
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <form class="space-y-3 border-b border-gray-100 px-5 py-4 text-left dark:border-gray-800" @submit.prevent="save">
        <div class="grid gap-3 md:grid-cols-2">
            <div v-for="box in fields" :key="box.name" class="min-w-0">
                <div class="mb-1 flex items-center justify-between gap-2">
                    <label :for="domId(box.name)" class="text-xs text-gray-500 dark:text-gray-400">
                        {{ box.label }}<span v-if="!box.field.json && box.field.key" class="ml-1 font-mono">({{ box.field.key }})</span>
                    </label>
                    <button type="button" class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400" @click="toggleMode(box.field, box.shape)">{{ box.field.json ? 'Use text' : 'Use JSON' }}</button>
                </div>
                <JsonEditor v-if="box.field.json" :id="domId(box.name)" v-model="box.field.text" style="height: 10rem" />
                <textarea v-else :id="domId(box.name)" v-model="box.field.text" rows="3" class="form-input h-auto w-full py-2" :placeholder="box.placeholder"></textarea>
                <p v-if="box.field.error" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ box.field.error }}</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary" :disabled="saving">{{ example ? 'Save' : 'Add example' }}</button>
            <button v-if="example" type="button" class="btn btn-secondary" @click="emit('cancel')">Cancel</button>
            <p v-if="error" class="text-xs text-red-600 dark:text-red-400">{{ error }}</p>
            <p v-else-if="added" class="text-xs text-gray-600 dark:text-gray-300">Added. Your next run will include it.</p>
        </div>
    </form>
</template>
