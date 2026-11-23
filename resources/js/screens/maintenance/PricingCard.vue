<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import { useProviders } from '@/composables/useProviders';
import type { MaintenanceQueuedResponse, PricingStatusResponse } from '@/types/api';

// Shows what Spectra bills with, and lets a manager price the stored requests
// again from the catalog. The button is always shown: without the
// manageSpectra gate the card says why it can't be used.
const { formatNumber, formatCurrency, apiRequest, alertSuccess } = useHelpers();
const { loadProviders, providerDisplayName } = useProviders();

const status = ref<PricingStatusResponse | null>(null);

const bundled = computed(() => status.value?.bundled || null);
const file = computed(() => status.value?.file || null);
const fileProviders = computed(() => Object.entries(file.value?.providers || {}));

const canManage = computed(() => status.value?.can_manage === true);
const pending = computed(() => status.value?.pending === true);
const runsOnQueue = computed(() => status.value?.runs_on_queue === true);
const last = computed(() => status.value?.last_recalculation || null);
const confirming = ref(false);
const busy = ref(false);
let pollTimer: ReturnType<typeof setTimeout> | null = null;

const load = async () => {
    try {
        status.value = await apiRequest<PricingStatusResponse>('get', '/maintenance/pricing');
        schedulePoll();
    } catch (error) {
        console.error('Failed to load pricing status:', error);
    }
};

// While a queued recalculation hasn't reported back, refresh every few seconds.
const schedulePoll = () => {
    clearTimeout(pollTimer!);
    if (pending.value) {
        pollTimer = setTimeout(load, 3000);
    }
};

const recalculate = async () => {
    if (!confirming.value) {
        confirming.value = true;
        return;
    }

    busy.value = true;
    try {
        const response = await apiRequest<MaintenanceQueuedResponse>('post', '/maintenance/pricing/recalculate');
        // Run during the request, the result is already on the card.
        if (response.queued) {
            alertSuccess('Recalculation queued. This card updates when it finishes.');
        }
        confirming.value = false;
        await load();
    } catch {
        // apiRequest has shown the error.
    } finally {
        busy.value = false;
    }
};

// The change in the total, with its sign.
const formatDifference = (cents: number) => `${cents < 0 ? '−' : '+'}${formatCurrency(Math.abs(cents))}`;

const formatDateTime = (value: string | null) => {
    if (!value) return '—';

    // A plain date has no time to show, and read as UTC it can land on the day before.
    const day = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value));
    if (day) return new Date(+day[1], +day[2] - 1, +day[3]).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);

    return date.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
};

onMounted(() => {
    loadProviders();
    load();
});

onUnmounted(() => clearTimeout(pollTimer!));
</script>

<template>
    <div class="card">
        <div class="card-header">
            <h2 class="flex items-center gap-1.5">
                Prices
                <a
                    href="https://spectraphp.com/pricing"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                    title="How Spectra prices requests"
                    aria-label="How Spectra prices requests (opens the docs)"
                >
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="10" cy="10" r="7.5" />
                        <path d="M7.9 7.8a2.2 2.2 0 1 1 3.3 1.9c-.7.4-1.2.9-1.2 1.7" />
                        <path d="M10 14.1v.1" />
                    </svg>
                </a>
            </h2>
            <span v-if="pending" class="badge badge-info"><span class="spinner h-2.5 w-2.5 border-[1.5px]" aria-hidden="true"></span> Queued</span>
            <span v-else-if="status" :class="file ? 'badge badge-info' : 'badge badge-secondary'">
                {{ file ? 'Bundled + your prices' : 'Bundled prices' }}
            </span>
        </div>
        <div class="card-body space-y-4">
            <div v-if="bundled && bundled.error" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-300">
                The bundled catalog can't be read: {{ bundled.error }}
            </div>

            <template v-if="file">
                <div v-if="file.error" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-300">
                    Your pricing file can't be read: {{ file.error }}
                </div>

                <div v-else-if="!file.exists" class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                    <code class="font-mono text-xs">SPECTRA_PRICING_PATH</code> points at <span class="font-mono">{{ file.path }}</span>, which doesn't exist, so every model uses its bundled price.
                </div>

                <!-- One line, no grid: what the deployed file holds. -->
                <p v-else-if="fileProviders.length" class="text-xs text-gray-500 dark:text-gray-400">
                    <span class="font-mono">{{ file.path }}</span>:
                    <span v-for="([provider, section], index) in fileProviders" :key="provider">{{ index ? ', ' : '' }}{{ providerDisplayName(provider) }} {{ formatNumber(section.models) }} {{ section.models === 1 ? 'model' : 'models' }}<template v-if="section.scraped_at"> (scraped {{ formatDateTime(section.scraped_at) }})</template></span>.
                </p>
            </template>

            <div v-if="status" class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        :class="['btn', confirming ? 'btn-primary' : 'btn-secondary']"
                        :disabled="!canManage || busy || pending"
                        @click="recalculate"
                    >
                        <span v-if="busy" class="spinner spinner-sm"></span>
                        {{ busy && !runsOnQueue ? 'Recalculating…' : confirming ? 'Yes, recalculate' : 'Recalculate costs…' }}
                    </button>
                    <button v-if="confirming" type="button" class="btn btn-ghost" :disabled="busy" @click="confirming = false">Cancel</button>
                </div>
                <p v-if="confirming" class="text-sm text-gray-600 dark:text-gray-300">
                    This prices every stored request again from the catalog and replaces its cost. It can't be undone.
                </p>
                <!-- Always said, so nobody has to guess where the work happens. -->
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    <template v-if="runsOnQueue">
                        Runs on Spectra's queue<template v-if="status.queue_name"> (<span class="font-mono">{{ status.queue_name }}</span>)</template>, so it needs a queue worker.
                    </template>
                    <template v-else>
                        Runs without a queue, during the request, because <code class="font-mono text-xs">spectra.queue.enabled</code> is off. With many stored requests, keep this page open until it finishes.
                    </template>
                </p>
                <p v-if="!canManage" class="text-xs text-gray-500 dark:text-gray-400">
                    Recalculating costs needs the <code class="font-mono text-xs">manageSpectra</code> gate, which you don't have.
                </p>
                <p v-if="last" class="text-xs text-gray-500 dark:text-gray-400">
                    Last recalculated {{ formatDateTime(last.at) }}:
                    <template v-if="last.updated">{{ formatNumber(last.updated) }} of {{ formatNumber(last.requests) }} {{ last.requests === 1 ? 'request' : 'requests' }} changed, {{ formatDifference(last.difference) }} in total.</template>
                    <template v-else>no cost changed in {{ formatNumber(last.requests) }} {{ last.requests === 1 ? 'request' : 'requests' }}.</template>
                </p>
            </div>

            <p v-if="status" class="text-xs text-gray-500 dark:text-gray-400">
                Catalog cache: {{ status.cache.enabled ? `on (${status.cache.store || 'default'} store)` : 'off' }}. The index is rebuilt when a pricing file changes.
            </p>
        </div>
    </div>
</template>
