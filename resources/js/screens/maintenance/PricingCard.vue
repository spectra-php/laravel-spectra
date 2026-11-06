<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import { useProviders } from '@/composables/useProviders';
import type { PricingStatusResponse } from '@/types/api';

// Read-only: prices are checked and written with spectra:pricing-scrape where you
// develop, then committed and deployed. The dashboard runs in production, so it
// never writes a pricing file.
const { formatNumber, apiRequest } = useHelpers();
const { loadProviders, providerDisplayName } = useProviders();

const status = ref<PricingStatusResponse | null>(null);

const bundled = computed(() => status.value?.bundled || null);
const file = computed(() => status.value?.file || null);
const fileProviders = computed(() => Object.entries(file.value?.providers || {}));
const bundledProviders = computed(() => Object.keys(bundled.value?.providers || {}).length);

const load = async () => {
    try {
        status.value = await apiRequest<PricingStatusResponse>('get', '/maintenance/pricing');
    } catch (error) {
        console.error('Failed to load pricing status:', error);
    }
};

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
</script>

<template>
    <div class="card">
        <div class="card-header">
            <h2>Prices</h2>
            <span v-if="status" :class="file ? 'badge badge-info' : 'badge badge-secondary'">
                {{ file ? 'Bundled + your prices' : 'Bundled prices' }}
            </span>
        </div>
        <div class="card-body space-y-4">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Spectra bills with the price catalog bundled in the package<template v-if="bundled && !bundled.error">: {{ formatNumber(bundled.models) }} models across {{ bundledProviders }} providers, generated {{ formatDateTime(bundled.generated_at) }}</template>.
                To pick up a price change or a new model before the next release, set <code class="font-mono text-xs">SPECTRA_PRICING_PATH</code> and run
                <code class="font-mono text-xs">php artisan spectra:pricing-scrape</code> where you develop: it reads the pricing pages with Firecrawl,
                shows each change with the page line it was confirmed on, and writes your pricing file. Commit the file and deploy it.
            </p>

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

            <p v-if="status" class="text-xs text-gray-500 dark:text-gray-400">
                Catalog cache: {{ status.cache.enabled ? `on (${status.cache.store || 'default'} store)` : 'off' }}. The index is rebuilt when a pricing file changes.
            </p>
        </div>
    </div>
</template>
