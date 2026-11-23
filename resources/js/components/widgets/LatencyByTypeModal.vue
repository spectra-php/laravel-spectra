<script setup lang="ts">
import { useHelpers } from '@/composables/useHelpers';
import Modal from '@/components/Modal.vue';
import type { ModelTypeLatency } from '@/types/api';

// The average latency of each model type, opened from an Average latency card:
// on the Overview, a shared dashboard, and the trackables screens.
defineProps<{
    rows: ModelTypeLatency[];
    periodLabel?: string;
}>();

const emit = defineEmits<{ close: [] }>();

const { formatNumber, formatDuration } = useHelpers();
</script>

<template>
    <Modal title="Average latency by model type" :subtitle="periodLabel" @close="emit('close')">
        <table class="table">
            <thead>
                <tr>
                    <th>Model type</th>
                    <th class="text-right">Average</th>
                    <th class="text-right">Requests</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.model_type">
                    <td class="font-medium text-gray-900 dark:text-white">{{ row.label }}</td>
                    <td class="text-right tabular-nums">{{ formatDuration(row.avg_latency) }}</td>
                    <td class="text-right tabular-nums">{{ formatNumber(row.count) }}</td>
                </tr>
            </tbody>
        </table>
    </Modal>
</template>
