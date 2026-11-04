<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import { useProviders } from '@/composables/useProviders';
import ProviderLogo from '@/components/ProviderLogo.vue';

const { formatNumber, apiRequest, alertSuccess } = useHelpers();
const { loadProviders, providerDisplayName } = useProviders();

const status = ref(null);
const busy = ref({ check: false, apply: false, dismiss: false });
// "provider|model" => true for the models picked to apply.
const selected = ref({});
let pollTimer = null;

const canManage = computed(() => status.value?.can_manage === true);
const review = computed(() => status.value?.review || null);
const selectedCount = computed(() => Object.values(selected.value).filter(Boolean).length);
const checkDisabled = computed(() => !canManage.value || !status.value?.firecrawl_configured || status.value?.pending || busy.value.check
    || (status.value?.providers_to_check?.providers || []).length === 0);

const key = (provider, model) => `${provider}|${model}`;

const load = async () => {
    try {
        const data = await apiRequest('get', '/maintenance/pricing');
        const reviewChanged = data.review?.at !== status.value?.review?.at;
        status.value = data;
        if (reviewChanged) preselect();
        clearTimeout(pollTimer);
        if (data.pending) pollTimer = setTimeout(load, 3000);
    } catch (error) {
        console.error('Failed to load pricing status:', error);
    }
};

// Pick every model the file doesn't already bill at the scraped prices.
const preselect = () => {
    const picked = {};
    for (const provider of review.value?.providers || []) {
        for (const model of provider.models) {
            if (!model.applied) picked[key(provider.provider, model.id)] = true;
        }
    }
    selected.value = picked;
};

const toggleProvider = (provider, value) => {
    for (const model of provider.models) {
        if (!model.applied) selected.value[key(provider.provider, model.id)] = value;
    }
};

const providerAllSelected = (provider) => {
    const open = provider.models.filter((model) => !model.applied);
    return open.length > 0 && open.every((model) => selected.value[key(provider.provider, model.id)]);
};

const check = async () => {
    busy.value.check = true;
    try {
        await apiRequest('post', '/maintenance/pricing/check');
        alertSuccess('Price check queued. Firecrawl can take a few minutes per page.');
        await load();
    } finally {
        busy.value.check = false;
    }
};

const apply = async () => {
    const models = {};
    for (const [k, on] of Object.entries(selected.value)) {
        if (!on) continue;
        const [provider, model] = k.split('|');
        (models[provider] ||= []).push(model);
    }

    busy.value.apply = true;
    try {
        const data = await apiRequest('post', '/maintenance/pricing/apply', { models });
        alertSuccess(`Wrote ${formatNumber(data.applied)} ${data.applied === 1 ? 'model' : 'models'} to the scraped prices file.`);
        await load();
        preselect();
    } finally {
        busy.value.apply = false;
    }
};

const dismiss = async () => {
    busy.value.dismiss = true;
    try {
        await apiRequest('delete', '/maintenance/pricing/review');
        selected.value = {};
        await load();
    } finally {
        busy.value.dismiss = false;
    }
};

const currencySymbols = { USD: '$', EUR: '€', GBP: '£' };

/** Cents in the provider's currency → "$0.90 /1M tokens", "€0.04 /image". */
const formatPrice = (cents, change, currency) => {
    if (cents === null || cents === undefined) return '—';
    const amount = Number(cents) / 100;
    const symbol = currencySymbols[currency] ?? currency + ' ';
    const text = symbol + amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 6 });

    if (change.field !== 'price_per_unit') return text + (change.unit === 'characters' ? ' /1M chars' : ' /1M tokens');

    return text + ' /' + change.unit;
};

const percentChange = (change) => {
    if (!change.old) return null;
    const pct = ((change.new - change.old) / change.old) * 100;

    return (pct > 0 ? '+' : '') + pct.toFixed(pct > -10 && pct < 10 ? 1 : 0) + '%';
};

const tierLabel = (change) => change.tier + (change.above_input_tokens ? ` > ${formatNumber(change.above_input_tokens)}` : '');

const formatDateTime = (value) => {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);

    return date.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
};

onMounted(() => {
    loadProviders();
    load();
});
onUnmounted(() => clearTimeout(pollTimer));
</script>

<template>
    <div class="card">
        <div class="card-header">
            <h2>Prices</h2>
            <span v-if="status?.pending" class="badge badge-info"><span class="spinner h-2.5 w-2.5 border-[1.5px]" aria-hidden="true"></span> Checking</span>
        </div>
        <div class="card-body space-y-4">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Reads the providers' pricing pages with Firecrawl and compares them with Spectra's built-in prices. Nothing changes until you pick the models to apply: they are written to the scraped prices file, which overrides the built-in prices for those models.
            </p>

            <div v-if="status && !status.uses_scraped_prices" class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                Applied prices are saved to the file but not billed until you set <code class="font-mono text-xs">SPECTRA_USE_SCRAPED_PRICES=true</code>.
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="button" class="btn btn-secondary" :disabled="checkDisabled" @click="check">
                    <span v-if="busy.check" class="spinner spinner-sm"></span>
                    Check pricing pages
                </button>
                <span v-if="status && !status.firecrawl_configured" class="text-xs text-gray-500 dark:text-gray-400">
                    Set <code class="font-mono">SPECTRA_FIRECRAWL_API_KEY</code> to check the pricing pages.
                </span>
                <span v-else-if="status && status.providers_to_check.providers.length" class="text-xs text-gray-500 dark:text-gray-400">
                    Uses Firecrawl credits for {{ status.providers_to_check.providers.map(providerDisplayName).join(', ') }}
                    ({{ status.providers_to_check.source === 'config' ? 'SPECTRA_SCRAPE_PROVIDERS' : 'the providers you have tracked' }}).
                </span>
                <span v-else-if="status" class="text-xs text-gray-500 dark:text-gray-400">
                    No provider to check yet: set SPECTRA_SCRAPE_PROVIDERS, or track a request first.
                </span>
            </div>

            <!-- Last check, for review -->
            <div v-if="review" class="space-y-4 border-t border-gray-100 pt-4 dark:border-gray-800">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="text-sm text-gray-700 dark:text-gray-300">
                        Checked {{ formatDateTime(review.at) }}
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" class="btn btn-ghost" :disabled="!canManage || busy.dismiss" @click="dismiss">Dismiss</button>
                        <button type="button" class="btn btn-primary" :disabled="!canManage || selectedCount === 0 || busy.apply" @click="apply">
                            <span v-if="busy.apply" class="spinner spinner-sm"></span>
                            Apply {{ selectedCount }} {{ selectedCount === 1 ? 'model' : 'models' }}
                        </button>
                    </div>
                </div>

                <div v-for="provider in review.providers" :key="provider.provider" class="rounded-lg border border-gray-200 dark:border-gray-800">
                    <div class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-4 py-2.5 dark:border-gray-800">
                        <input
                            v-if="provider.models.some((model) => !model.applied)"
                            type="checkbox"
                            class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900"
                            :checked="providerAllSelected(provider)"
                            :disabled="!canManage"
                            :aria-label="`Select every ${providerDisplayName(provider.provider)} model`"
                            @change="toggleProvider(provider, $event.target.checked)"
                        />
                        <ProviderLogo :provider="provider.provider" class="inline-flex h-4 w-4 items-center text-gray-700 dark:text-gray-200 [&>svg]:h-full [&>svg]:w-full" />
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ providerDisplayName(provider.provider) }}</span>
                        <span v-if="provider.error" class="badge badge-danger">Not read</span>
                        <span v-else class="text-xs text-gray-500 dark:text-gray-400">
                            {{ formatNumber(provider.rows) }} rows · {{ formatNumber(provider.matched_models) }} catalog models found ·
                            {{ provider.models.length }} {{ provider.models.length === 1 ? 'model differs' : 'models differ' }}
                        </span>
                    </div>

                    <div v-if="provider.error" class="px-4 py-3 text-sm text-red-700 dark:text-red-400">{{ provider.error }}</div>
                    <div v-else-if="provider.models.length === 0" class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">The pricing pages match the built-in prices.</div>

                    <table v-else class="table">
                        <thead>
                            <tr>
                                <th class="w-8"></th>
                                <th>Model</th>
                                <th>Tier</th>
                                <th>Price</th>
                                <th class="text-right">Built-in</th>
                                <th class="text-right">Scraped</th>
                                <th class="text-right">Change</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="model in provider.models" :key="model.id">
                                <tr v-for="(change, index) in model.changes" :key="index" :class="model.applied ? 'opacity-60' : ''">
                                    <td v-if="index === 0" :rowspan="model.changes.length" class="align-top">
                                        <input
                                            v-if="!model.applied"
                                            v-model="selected[key(provider.provider, model.id)]"
                                            type="checkbox"
                                            class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900"
                                            :disabled="!canManage"
                                            :aria-label="`Apply ${model.id}`"
                                        />
                                    </td>
                                    <td v-if="index === 0" :rowspan="model.changes.length" class="align-top">
                                        <div class="font-mono text-xs text-gray-900 dark:text-white">{{ model.id }}</div>
                                        <div class="mt-1 flex gap-1">
                                            <span v-if="model.is_new" class="badge badge-success">New</span>
                                            <span v-if="model.applied" class="badge badge-secondary">In file</span>
                                        </div>
                                    </td>
                                    <td class="text-xs text-gray-600 dark:text-gray-300">{{ tierLabel(change) }}</td>
                                    <td class="text-xs text-gray-600 dark:text-gray-300">{{ change.label }}</td>
                                    <td class="text-right text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ formatPrice(change.old, change, provider.currency) }}</td>
                                    <td class="text-right text-xs tabular-nums text-gray-900 dark:text-white">{{ formatPrice(change.new, change, provider.currency) }}</td>
                                    <td
                                        class="text-right text-xs tabular-nums"
                                        :class="change.old && change.new > change.old ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'"
                                    >
                                        {{ percentChange(change) ?? '—' }}
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>

                    <details v-if="provider.skipped.length" class="border-t border-gray-100 px-4 py-2.5 text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        <summary class="cursor-pointer">{{ provider.skipped.length }} scraped {{ provider.skipped.length === 1 ? 'row was' : 'rows were' }} not used</summary>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            <li v-for="(reason, index) in provider.skipped" :key="index">{{ reason }}</li>
                        </ul>
                    </details>
                </div>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Firecrawl reads the pages with an LLM and can misread them; check each change against the page before you apply it.
                    The file is written on this server: commit it so every server and deploy uses it, and restart queue workers to pick up new prices.
                </p>
            </div>

            <div v-else-if="status?.last_apply" class="text-xs text-gray-500 dark:text-gray-400">
                Last applied {{ formatDateTime(status.last_apply.at) }}: {{ formatNumber(status.last_apply.models) }} models.
            </div>
        </div>
    </div>
</template>
