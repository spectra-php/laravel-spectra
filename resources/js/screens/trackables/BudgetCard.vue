<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import { useCardRefresh } from '@/composables/useCardRefresh';
import BudgetLimitBars from '@/components/widgets/BudgetLimitBars.vue';
import DocsLink from '@/components/DocsLink.vue';
import RefreshButton from '@/components/RefreshButton.vue';
import Skeleton from '@/components/Skeleton.vue';
import type { BudgetLimit, TrackableBudgetResponse } from '@/types/api';
import type { ApiError } from '@/types/app';

// A trackable's budget: its limits with what is used against them, and, for a
// user who may manage Spectra, the form that sets them. It is the same budget
// the app sets with configureAiBudget(), so either side sees the other's.
const props = defineProps<{ type: string; id: string }>();

type Metric = BudgetLimit['metric'];
type Period = BudgetLimit['period'];

interface LimitRow {
    metric: Metric;
    period: Period;
    // What the user typed: currency units for spend, a whole number otherwise.
    amount: string;
}

const { apiRequest, currencySymbol } = useHelpers();
const { isRefreshing, refresh } = useCardRefresh();

const metrics: { value: Metric; label: string }[] = [
    { value: 'cost', label: `Spend (${window.Spectra.currency || 'USD'})` },
    { value: 'tokens', label: 'Tokens' },
    { value: 'requests', label: 'Requests' },
];
const periods: { value: Period; label: string }[] = [
    { value: 'daily', label: 'Per day' },
    { value: 'weekly', label: 'Per week' },
    { value: 'monthly', label: 'Per month' },
    { value: 'total', label: 'All time' },
];

const budget = ref<TrackableBudgetResponse | null>(null);
const loading = ref(true);
const editing = ref(false);
const rows = ref<LimitRow[]>([]);
const hardLimit = ref(true);
const active = ref(true);
const saving = ref(false);
const confirmingRemove = ref(false);
const error = ref('');

const endpoint = computed(() => `/trackables/view/${encodeURIComponent(props.id)}/budget`);
const query = computed(() => '?' + new URLSearchParams({ type: props.type }).toString());

const load = async () => {
    try {
        budget.value = await apiRequest<TrackableBudgetResponse>('get', endpoint.value + query.value);
    } catch (e) {
        console.error('Failed to load the budget:', e);
    } finally {
        loading.value = false;
    }
};

onMounted(load);

// Requests have no all-time limit.
const isOffered = (metric: Metric, period: Period) => !(metric === 'requests' && period === 'total');
const isTaken = (metric: Metric, period: Period, except: LimitRow) => rows.value.some((row) => row !== except && row.metric === metric && row.period === period);
const freePeriod = (metric: Metric, except: LimitRow) => periods.find((period) => isOffered(metric, period.value) && !isTaken(metric, period.value, except))?.value;

const nextFreeRow = (): LimitRow | null => {
    const blank: LimitRow = { metric: 'cost', period: 'monthly', amount: '' };

    // A type that has no limit yet comes first, per month; after that, the first period still free.
    const unused = metrics.find((metric) => !rows.value.some((row) => row.metric === metric.value));

    if (unused) return { ...blank, metric: unused.value };

    for (const metric of metrics) {
        const period = freePeriod(metric.value, blank);

        if (period) return { ...blank, metric: metric.value, period };
    }

    return null;
};

const canAddRow = computed(() => nextFreeRow() !== null);

const addRow = () => {
    const row = nextFreeRow();

    if (row) rows.value.push(row);
};

// Changing a row's type can land on a period another row already has.
const onMetricChange = (row: LimitRow) => {
    if (isOffered(row.metric, row.period) && !isTaken(row.metric, row.period, row)) return;

    row.period = freePeriod(row.metric, row) ?? row.period;
};

// Spend is typed in currency units and saved in cents; tokens and requests are whole numbers.
const toLimit = (row: LimitRow) => {
    const value = Number(row.amount);

    if (!Number.isFinite(value) || value <= 0) return null;

    return row.metric === 'cost' ? Math.round(value * 100) : Number.isInteger(value) ? value : null;
};

const formValid = computed(() => rows.value.length > 0 && rows.value.every((row) => {
    const limit = toLimit(row);

    return limit !== null && limit >= 1 && isOffered(row.metric, row.period) && !isTaken(row.metric, row.period, row);
}));

const startEditing = () => {
    rows.value = (budget.value?.limits ?? []).map((limit) => ({
        metric: limit.metric,
        period: limit.period,
        amount: String(limit.metric === 'cost' ? limit.limit / 100 : limit.limit),
    }));
    if (!rows.value.length) addRow();
    hardLimit.value = budget.value?.hard_limit ?? true;
    // A new budget starts switched on.
    active.value = budget.value?.exists ? budget.value.is_active : true;
    error.value = '';
    confirmingRemove.value = false;
    editing.value = true;
};

const save = async () => {
    if (!formValid.value || saving.value) return;
    saving.value = true;
    error.value = '';
    try {
        await apiRequest('put', endpoint.value, {
            type: props.type,
            limits: rows.value.map((row) => ({ metric: row.metric, period: row.period, limit: toLimit(row) })),
            hard_limit: hardLimit.value,
            is_active: active.value,
        });
        await load();
        editing.value = false;
    } catch (e) {
        error.value = (e as ApiError)?.response?.data?.message || 'The budget could not be saved.';
    } finally {
        saving.value = false;
    }
};

const remove = async () => {
    if (!confirmingRemove.value) {
        confirmingRemove.value = true;
        return;
    }

    saving.value = true;
    error.value = '';
    try {
        await apiRequest('delete', endpoint.value + query.value);
        await load();
        editing.value = false;
    } catch (e) {
        error.value = (e as ApiError)?.response?.data?.message || 'The budget could not be removed.';
    } finally {
        saving.value = false;
        confirmingRemove.value = false;
    }
};
</script>

<template>
    <div class="card mb-4" :class="{ 'is-refreshing': isRefreshing('budget') }">
        <div class="card-header">
            <div>
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-semibold">Budget</h3>
                    <span v-if="budget?.exists" class="badge" :class="budget.is_active ? 'badge-success' : 'badge-secondary'">{{ budget.is_active ? 'On' : 'Off' }}</span>
                </div>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Limits on spend, tokens and requests. Its periods are its own, whatever range the page shows.</p>
            </div>
            <div class="card-actions">
                <button v-if="budget?.can_manage && !editing && !loading" type="button" class="btn btn-secondary h-8 px-3 text-xs" @click="startEditing">
                    {{ budget.exists ? 'Edit' : 'Set budget' }}
                </button>
                <DocsLink page="budgets#dashboard" label="How budgets work" />
                <RefreshButton v-if="!loading && !editing" :refreshing="isRefreshing('budget')" @refresh="refresh('budget', load)" />
            </div>
        </div>

        <div v-if="loading" class="card-body">
            <Skeleton :lines="2" />
        </div>

        <!-- The form -->
        <form v-else-if="editing" class="card-body space-y-4" @submit.prevent="save">
            <div class="space-y-2">
                <div v-for="(row, index) in rows" :key="index" class="flex flex-wrap items-center gap-2">
                    <select v-model="row.metric" class="form-select w-44" :aria-label="`Limit ${index + 1} type`" @change="onMetricChange(row)">
                        <option v-for="metric in metrics" :key="metric.value" :value="metric.value">{{ metric.label }}</option>
                    </select>
                    <select v-model="row.period" class="form-select w-36" :aria-label="`Limit ${index + 1} period`">
                        <option
                            v-for="period in periods"
                            :key="period.value"
                            :value="period.value"
                            :disabled="!isOffered(row.metric, period.value) || isTaken(row.metric, period.value, row)"
                        >{{ period.label }}</option>
                    </select>
                    <div class="relative w-40">
                        <span v-if="row.metric === 'cost'" class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-gray-500 dark:text-gray-400">{{ currencySymbol }}</span>
                        <input
                            v-model="row.amount"
                            type="number"
                            inputmode="decimal"
                            min="0"
                            :step="row.metric === 'cost' ? '0.01' : '1'"
                            class="form-input tabular-nums"
                            :class="{ 'pl-7': row.metric === 'cost' }"
                            :placeholder="row.metric === 'cost' ? '20.00' : row.metric === 'tokens' ? '1000000' : '500'"
                            :aria-label="`Limit ${index + 1} amount`"
                        />
                    </div>
                    <button type="button" class="text-xs font-medium text-gray-500 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400" @click="rows.splice(index, 1)">Remove</button>
                </div>
                <button type="button" class="text-xs font-medium text-primary-600 hover:underline disabled:cursor-not-allowed disabled:opacity-50 disabled:no-underline dark:text-primary-400" :disabled="!canAddRow" @click="addRow">Add limit</button>
            </div>

            <div class="space-y-2">
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="hardLimit" type="checkbox" class="mt-0.5" />
                    <span>Block requests when a limit is reached<span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">On routes that use the <code class="font-mono">spectra.budget</code> middleware. Without it, reaching a limit is only reported.</span></span>
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="active" type="checkbox" class="mt-0.5" />
                    <span>Budget is on<span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">Switched off, its limits are kept but nothing is checked.</span></span>
                </label>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="btn btn-primary" :disabled="saving || !formValid">Save</button>
                <button type="button" class="btn btn-secondary" :disabled="saving" @click="editing = false">Cancel</button>
                <p v-if="!rows.length" class="text-xs text-gray-500 dark:text-gray-400">A budget needs at least one limit.</p>
                <p v-if="error" class="text-xs text-red-600 dark:text-red-400">{{ error }}</p>
                <div v-if="budget?.exists" class="ml-auto inline-flex items-center gap-2">
                    <template v-if="confirmingRemove">
                        <span class="text-xs text-gray-500 dark:text-gray-400">Nothing will limit this trackable.</span>
                        <button type="button" class="btn btn-secondary h-8 px-3 text-xs" @click="confirmingRemove = false">Keep</button>
                        <button type="button" class="btn btn-primary h-8 px-3 text-xs" :disabled="saving" @click="remove">Remove</button>
                    </template>
                    <button v-else type="button" class="text-xs font-medium text-gray-500 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400" @click="remove">Remove budget</button>
                </div>
            </div>
        </form>

        <!-- The budget -->
        <div v-else class="card-body">
            <div v-if="!budget?.exists" class="empty-state py-6">
                <p class="empty-state-description">No budget is set</p>
            </div>
            <template v-else>
                <BudgetLimitBars v-if="budget.limits.length" :limits="budget.limits" />
                <p v-else class="text-sm text-gray-500 dark:text-gray-400">This budget sets no limits.</p>
                <div class="mt-4 space-y-1 text-xs text-gray-500 dark:text-gray-400">
                    <p v-if="!budget.is_active">This budget is switched off, so nothing is checked against it.</p>
                    <p v-else-if="budget.limits.length">{{ budget.hard_limit ? 'Requests are blocked once a limit is reached.' : 'Reaching a limit is reported; requests are not blocked.' }}</p>
                    <p v-if="budget.allowed_providers.length">Only these providers are allowed: {{ budget.allowed_providers.join(', ') }}.</p>
                    <p v-if="budget.allowed_models.length">Only these models are allowed: {{ budget.allowed_models.join(', ') }}.</p>
                </div>
            </template>
        </div>
    </div>
</template>
