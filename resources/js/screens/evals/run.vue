<script setup>
import { ref, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { useLoading } from '@/composables/useLoading';
import { useEvalFormat } from '@/composables/useEvalFormat';
import Skeleton from '@/components/Skeleton.vue';
import Money from '@/components/Money.vue';

// One evaluation run: each scorer over all examples, then every example's
// input, expected and actual output and scores. Compared with an earlier
// run, it shows the change per scorer and that run's output and scores
// under each example.
const route = useRoute();
const router = useRouter();
const { formatNumber, formatDuration, apiRequest } = useHelpers();
const { text, scoreText, scoreClass, summaryText, delta } = useEvalFormat();
const loading = useLoading();

loading.value = true;
const data = ref(null);
const changedOnly = ref(false);

const load = async () => {
    loading.value = true;
    try {
        const query = route.query.compare ? `?compare=${encodeURIComponent(route.query.compare)}` : '';
        data.value = await apiRequest('get', `/evals/runs/${encodeURIComponent(route.params.id)}${query}`);
    } catch (error) {
        console.error('Failed to load the run:', error);
    } finally {
        loading.value = false;
    }
};

watch(() => [route.params.id, route.query.compare], load, { immediate: true });

const compareWith = (event) => {
    router.replace({ query: event.target.value ? { compare: event.target.value } : {} });
};

const scoreNames = computed(() => Object.keys(data.value?.run.summary || {}));
const changed = (result) => result.baseline && (text(result.output) !== text(result.baseline.output) || JSON.stringify(result.scores) !== JSON.stringify(result.baseline.scores) || !!result.error !== !!result.baseline.error);
const results = computed(() => (data.value?.results || []).filter((result) => !changedOnly.value || changed(result)));
const scoreChanged = (result, name) => result.baseline && result.baseline.scores[name] !== undefined && result.baseline.scores[name] !== result.scores[name];
const deltaClass = (value) => (value > 0 ? 'text-emerald-600 dark:text-emerald-400' : value < 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-400');
const formatDate = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '');
</script>

<template>
    <div>
        <div v-if="loading && !data" class="card"><Skeleton variant="table-row" :lines="8" /></div>

        <template v-else-if="data">
            <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        <router-link :to="{ name: 'evals' }" class="hover:text-primary-600 dark:hover:text-primary-400">Evals</router-link>
                        <span class="mx-1.5">/</span>
                        <span class="font-mono">{{ data.run.evaluation }}</span>
                    </p>
                    <h1 class="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{{ formatDate(data.run.started_at) }}</h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ formatNumber(data.run.example_count) }} examples of <span class="font-mono">{{ data.run.dataset }}</span>
                        · {{ formatNumber(data.run.error_count) }} errors
                        <template v-if="data.run.duration_ms !== null"> · took {{ formatDuration(data.run.duration_ms) }}</template>
                        · <Money :cents="data.run.total_cost_in_cents" />
                    </p>
                </div>
                <div v-if="data.runs.length" class="flex items-center gap-2">
                    <label for="compare-run" class="text-xs text-gray-500 dark:text-gray-400">Compare with</label>
                    <select id="compare-run" class="form-select form-select-sm" :value="route.query.compare || ''" @change="compareWith">
                        <option value="">No run</option>
                        <option v-for="run in data.runs" :key="run.id" :value="run.id">{{ formatDate(run.started_at) }}</option>
                    </select>
                </div>
            </div>

            <!-- Each scorer over the run, and its change from the compared run -->
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div v-for="name in scoreNames" :key="name" class="card px-5 py-4">
                    <p class="truncate font-mono text-xs text-gray-500 dark:text-gray-400">{{ name }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ summaryText(data.run.summary[name]) }}</p>
                    <p v-if="data.baseline" class="mt-0.5 text-xs tabular-nums">
                        <template v-if="delta(data.run.summary[name], data.baseline.summary[name]) !== null">
                            <span :class="deltaClass(delta(data.run.summary[name], data.baseline.summary[name]))">
                                {{ delta(data.run.summary[name], data.baseline.summary[name]) > 0 ? '+' : '' }}{{ delta(data.run.summary[name], data.baseline.summary[name]) }}{{ data.run.summary[name].type === 'boolean' ? ' pts' : '' }}
                            </span>
                            <span class="text-gray-400"> vs {{ summaryText(data.baseline.summary[name]) }}</span>
                        </template>
                        <span v-else class="text-gray-400">was {{ summaryText(data.baseline.summary[name]) }}</span>
                    </p>
                </div>
            </div>

            <div class="card mt-4 overflow-hidden">
                <div class="card-header">
                    <div>
                        <h3>Examples</h3>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            <template v-if="data.baseline">The compared run's output and scores are under each example</template>
                            <template v-else>Each example ran in a trace of its own</template>
                        </p>
                    </div>
                    <label v-if="data.baseline" class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                        <input v-model="changedOnly" type="checkbox" class="rounded border-gray-300 dark:border-gray-600" />
                        Changed only
                    </label>
                </div>
                <div class="overflow-x-auto">
                    <table class="table table-fixed">
                        <colgroup>
                            <col class="w-[4%]" />
                            <col class="w-[22%]" />
                            <col class="w-[20%]" />
                            <col class="w-[26%]" />
                            <col class="w-[16%]" />
                            <col class="w-[12%]" />
                        </colgroup>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Input</th>
                                <th>Expected</th>
                                <th>Output</th>
                                <th>Scores</th>
                                <th class="text-right">Latency · cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(result, index) in results" :key="result.id" class="align-top">
                                <td class="tabular-nums text-gray-400">{{ index + 1 }}</td>
                                <td><p class="line-clamp-4 whitespace-pre-wrap break-words text-sm" v-tooltip.truncated="text(result.input)">{{ text(result.input) }}</p></td>
                                <td><p class="line-clamp-4 whitespace-pre-wrap break-words text-sm text-gray-600 dark:text-gray-300" v-tooltip.truncated="text(result.expected)">{{ text(result.expected) || '—' }}</p></td>
                                <td>
                                    <p v-if="result.error" class="line-clamp-4 break-words font-mono text-xs text-red-600 dark:text-red-400">{{ result.error }}</p>
                                    <p v-else class="line-clamp-4 whitespace-pre-wrap break-words text-sm text-gray-900 dark:text-gray-100" v-tooltip.truncated="text(result.output)">{{ text(result.output) }}</p>
                                    <p v-if="result.baseline" class="mt-2 line-clamp-3 whitespace-pre-wrap break-words border-l-2 border-gray-200 pl-2 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                        {{ result.baseline.error || text(result.baseline.output) }}
                                    </p>
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-1">
                                        <span
                                            v-for="(value, name) in result.scores"
                                            :key="name"
                                            class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px]"
                                            :class="[scoreClass(value), scoreChanged(result, name) ? 'ring-1 ring-amber-400' : '']"
                                            v-tooltip="scoreChanged(result, name) ? `${name}: was ${scoreText(result.baseline.scores[name])}` : name"
                                        >
                                            <span class="font-mono opacity-70">{{ name }}</span> {{ scoreText(value) }}
                                        </span>
                                    </div>
                                </td>
                                <td class="text-right text-xs tabular-nums text-gray-500 dark:text-gray-400">
                                    {{ formatDuration(result.latency_ms) }}
                                    <span class="block"><Money :cents="result.total_cost_in_cents" /></span>
                                    <router-link :to="{ name: 'trace-detail', params: { traceId: result.trace_id } }" class="block text-primary-600 hover:underline dark:text-primary-400">Trace</router-link>
                                </td>
                            </tr>
                            <tr v-if="!results.length">
                                <td colspan="6" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">No example changed from the compared run.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>
</template>
