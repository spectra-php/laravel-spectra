<script setup>
import { ref, computed, onMounted } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import { usePeriodQuery } from '@/composables/usePeriodQuery';
import { useLoading } from '@/composables/useLoading';
import PageRangeHeader from '@/components/PageRangeHeader.vue';
import Skeleton from '@/components/Skeleton.vue';
import Money from '@/components/Money.vue';
import ScoreValue from '@/components/ScoreValue.vue';

// Prompt templates and their versions: calls, errors, cost, tokens, latency
// and scores per version, to see whether a new version did better.
const { formatNumber, formatDuration, apiRequest } = useHelpers();
const { period, startDate, endDate, periodLabel, applyQueryState, buildQueryParams, syncQueryToRouter } = usePeriodQuery();
const loading = useLoading();

loading.value = true;
const versions = ref([]);
const migrated = ref(true);

const prompts = computed(() => {
    const groups = new Map();
    for (const version of versions.value) {
        if (!groups.has(version.prompt_name)) groups.set(version.prompt_name, []);
        groups.get(version.prompt_name).push(version);
    }

    return [...groups.entries()].map(([name, rows]) => ({ name, rows }));
});

const load = async () => {
    loading.value = true;
    try {
        const data = await apiRequest('get', '/prompts?' + buildQueryParams().toString());
        versions.value = data.versions || [];
        migrated.value = data.migrated !== false;
    } catch (error) {
        console.error('Failed to load prompts:', error);
    } finally {
        loading.value = false;
    }
};

const onRangeChange = async () => {
    await syncQueryToRouter();
    await load();
};

const errorRate = (rate) => `${(Number(rate || 0) * 100).toLocaleString('en-US', { maximumFractionDigits: 1 })}%`;
const errorClass = (rate) => (rate >= 0.05 ? 'text-red-600 dark:text-red-400' : rate >= 0.01 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-500 dark:text-gray-400');
const scoreAverage = (score) => (score.type === 'boolean' ? `${Math.round((score.average ?? 0) * 100)}% yes` : Number(score.average ?? 0).toLocaleString('en-US', { maximumFractionDigits: 2 }));
const formatDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : '');

onMounted(async () => {
    applyQueryState();
    await syncQueryToRouter(null, true);
    await load();
});
</script>

<template>
    <div>
        <PageRangeHeader
            title="Prompts"
            docs="prompts"
            :subtitle="`Compare prompt versions · ${periodLabel}`"
            v-model:period="period"
            v-model:start-date="startDate"
            v-model:end-date="endDate"
            :loading="loading"
            @change="onRangeChange"
        />

        <div v-if="loading" class="card"><Skeleton variant="table-row" :lines="6" /></div>

        <!-- Upgrades: the prompt columns are missing until the migration runs -->
        <div v-else-if="!migrated" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            Calls are tagged with their prompt once the prompt migration runs. Run <code class="font-mono">php artisan spectra:install</code> to publish and run it.
        </div>

        <div v-else-if="!prompts.length" class="card">
            <div class="empty-state">
                <p class="empty-state-title">No prompt versions in this period</p>
                <p class="empty-state-description">
                    Wrap calls in <code class="font-mono">Spectra::withPrompt('support-answer', 'v3', fn () => ...)</code> to compare prompt versions here.
                </p>
                <a href="https://spectraphp.com/prompts" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300">
                    Read the docs
                    <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.22 14.78a.75.75 0 0 0 1.06 0l7.22-7.22v5.69a.75.75 0 0 0 1.5 0v-7.5a.75.75 0 0 0-.75-.75h-7.5a.75.75 0 0 0 0 1.5h5.69l-7.22 7.22a.75.75 0 0 0 0 1.06Z" clip-rule="evenodd" /></svg>
                </a>
            </div>
        </div>

        <div v-else class="space-y-4">
            <div v-for="prompt in prompts" :key="prompt.name" class="card overflow-hidden">
                <div class="card-header">
                    <div>
                        <h3 class="font-mono">{{ prompt.name }}</h3>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ prompt.rows.length }} {{ prompt.rows.length === 1 ? 'version' : 'versions' }}, newest first</p>
                    </div>
                    <router-link :to="{ name: 'requests', query: { prompt_name: prompt.name, period } }" class="text-xs font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400">View calls</router-link>
                </div>
                <div class="overflow-x-auto">
                    <table class="table table-fixed">
                        <colgroup>
                            <col class="w-[16%]" />
                            <col class="w-[9%]" />
                            <col class="w-[10%]" />
                            <col class="w-[10%]" />
                            <col class="w-[11%]" />
                            <col class="w-[11%]" />
                            <col class="w-[23%]" />
                            <col class="w-[10%]" />
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Version</th>
                                <th class="text-right">Calls</th>
                                <th class="text-right">Error rate</th>
                                <th class="text-right">Avg cost</th>
                                <th class="text-right">Avg tokens</th>
                                <th class="text-right">Avg latency</th>
                                <th>Scores</th>
                                <th class="text-right">Last used</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in prompt.rows" :key="row.prompt_version ?? '∅'">
                                <td>
                                    <router-link
                                        :to="{ name: 'requests', query: { prompt_name: prompt.name, prompt_version: row.prompt_version ?? undefined, period } }"
                                        class="font-mono font-medium text-gray-900 hover:text-primary-600 dark:text-white dark:hover:text-primary-400"
                                    >{{ row.prompt_version ?? 'unversioned' }}</router-link>
                                </td>
                                <td class="text-right tabular-nums">{{ formatNumber(row.request_count) }}</td>
                                <td class="text-right tabular-nums font-medium" :class="errorClass(row.error_rate)">{{ errorRate(row.error_rate) }}</td>
                                <td class="text-right tabular-nums"><Money :cents="row.avg_cost_in_cents" /></td>
                                <td class="text-right tabular-nums">{{ formatNumber(row.avg_tokens) }}</td>
                                <td class="text-right tabular-nums">{{ formatDuration(row.avg_latency_ms) }}</td>
                                <td>
                                    <div class="flex flex-wrap gap-1.5">
                                        <span v-for="score in row.scores" :key="score.name" class="inline-flex items-center gap-1 text-xs text-gray-600 dark:text-gray-300" v-tooltip="`${formatNumber(score.count)} scores`">
                                            <span class="text-gray-500 dark:text-gray-400">{{ score.name }}</span>
                                            <span class="font-medium tabular-nums text-gray-900 dark:text-white">{{ scoreAverage(score) }}</span>
                                        </span>
                                        <span v-if="!row.scores.length" class="text-xs text-gray-400 dark:text-gray-500">—</span>
                                    </div>
                                </td>
                                <td class="text-right text-xs text-gray-500 dark:text-gray-400">{{ formatDate(row.last_seen_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
