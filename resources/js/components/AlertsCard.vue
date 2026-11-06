<script setup lang="ts">
import type { AlertEntry } from '@/types/api';

// The overview's Alerts card: each rule from spectra.alerts.rules with its
// value now and whether it is firing (as of the last spectra:check-alerts).
withDefaults(defineProps<{
    alerts?: AlertEntry[];
    error?: string | null;
}>(), {
    alerts: () => [],
    error: null,
});

const formatTime = (iso: string | null) => {
    if (!iso) return '';
    const date = new Date(iso);

    return Number.isNaN(date.getTime()) ? iso : date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
};
</script>

<template>
    <div class="card overflow-hidden">
        <div class="card-header">
            <div>
                <h3>Alerts</h3>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Firing as of the last <code class="font-mono">spectra:check-alerts</code></p>
            </div>
            <span v-if="alerts.some((alert) => alert.firing)" class="badge badge-danger">{{ alerts.filter((alert) => alert.firing).length }} firing</span>
        </div>
        <p v-if="error" class="px-5 py-4 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
        <ul v-else class="grid grid-cols-1 gap-px bg-gray-100 sm:grid-cols-2 lg:grid-cols-3 dark:bg-gray-800/80">
            <li v-for="alert in alerts" :key="alert.name" class="bg-white px-5 py-4 dark:bg-gray-900">
                <div class="flex items-start justify-between gap-3">
                    <span class="truncate text-sm font-medium text-gray-900 dark:text-white" v-tooltip.truncated="alert.name">{{ alert.name }}</span>
                    <span class="badge shrink-0" :class="alert.firing ? 'badge-danger' : 'badge-success'">{{ alert.firing ? 'Firing' : 'OK' }}</span>
                </div>
                <div class="mt-2 text-2xl font-semibold tabular-nums" :class="alert.firing ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white'">{{ alert.value ?? '—' }}</div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ alert.rule }}</p>
                <p v-if="alert.firing && alert.since" class="mt-1 text-xs text-red-600 dark:text-red-400">Since {{ formatTime(alert.since) }}</p>
                <p v-else-if="alert.value === null" class="mt-1 text-xs text-gray-400 dark:text-gray-500">Not enough calls in the window to measure</p>
            </li>
        </ul>
    </div>
</template>
