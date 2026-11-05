<script setup>
import { ref, computed, onMounted } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import { useProviders } from '@/composables/useProviders';

// Read-only: prices are checked and written with spectra:pricing-scrape where you
// develop, then committed and deployed. The dashboard runs in production, so it
// never writes the prices file.
const { formatNumber, apiRequest } = useHelpers();
const { loadProviders, providerDisplayName } = useProviders();

const status = ref(null);

const file = computed(() => status.value?.file || null);
const fileProviders = computed(() => Object.entries(file.value?.providers || {}));

const load = async () => {
    try {
        status.value = await apiRequest('get', '/maintenance/pricing');
    } catch (error) {
        console.error('Failed to load pricing status:', error);
    }
};

const formatDateTime = (value) => {
    if (!value) return '—';

    // A plain date (a provider's scraped_at) has no time to show, and read as UTC it can land on the day before.
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
            <span v-if="status" :class="status.uses_scraped_prices ? 'badge badge-info' : 'badge badge-secondary'">
                {{ status.uses_scraped_prices ? 'Built-in + scraped prices' : 'Built-in prices' }}
            </span>
        </div>
        <div class="card-body space-y-4">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Spectra bills with its built-in price catalog. To pick up a price change or a new model before the next release, run
                <code class="font-mono text-xs">php artisan spectra:pricing-scrape</code> where you develop: it reads the pricing pages with Firecrawl,
                shows each change with the page line it was confirmed on, and writes the scraped prices file. Commit the file and deploy it.
            </p>

            <template v-if="file">
                <div v-if="file.error" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-300">
                    The scraped prices file can't be read: {{ file.error }}
                </div>

                <div v-else-if="file.exists && !status.uses_scraped_prices" class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                    The scraped prices file is deployed but not billed until you set <code class="font-mono text-xs">SPECTRA_USE_SCRAPED_PRICES=true</code>.
                </div>

                <div v-else-if="!file.exists && status.uses_scraped_prices" class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                    <code class="font-mono text-xs">SPECTRA_USE_SCRAPED_PRICES</code> is on, but there is no scraped prices file, so every model uses its built-in price.
                </div>

                <!-- One line, no grid: what the deployed file holds. -->
                <p v-if="!file.error && fileProviders.length" class="text-xs text-gray-500 dark:text-gray-400">
                    <span class="font-mono">{{ file.path }}</span>:
                    <span v-for="([provider, section], index) in fileProviders" :key="provider">{{ index ? ', ' : '' }}{{ providerDisplayName(provider) }} {{ formatNumber(section.models) }} {{ section.models === 1 ? 'model' : 'models' }} (scraped {{ formatDateTime(section.scraped_at) }})</span>.
                </p>
            </template>
        </div>
    </div>
</template>
