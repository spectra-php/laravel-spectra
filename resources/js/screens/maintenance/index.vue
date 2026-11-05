<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue';
import { useRoute } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { useLoading } from '@/composables/useLoading';
import StatCard from '@/components/StatCard.vue';
import PricingCard from './PricingCard.vue';

const { formatNumber, apiRequest, alertSuccess } = useHelpers();
const loading = useLoading();

const retentionOptions = [
    { hours: 24, label: '24 hours' },
    { hours: 168, label: '7 days' },
    { hours: 720, label: '30 days' },
    { hours: 2160, label: '90 days' },
    { hours: 8760, label: '1 year' },
];
const statsRetentionOptions = [90, 180, 365, 730];

const status = ref(null);
const hours = ref(168);
const pruneStats = ref(false);
const statsDays = ref(365);
const confirmingPrune = ref(false);
const busy = ref({ prune: false, rebuild: false, recording: false });
let pollTimer = null;

const canManage = computed(() => status.value?.can_manage === true);
const preview = computed(() => status.value?.preview || {});
const totals = computed(() => status.value?.totals || {});
const pending = computed(() => status.value?.pending || {});
const nothingToPrune = computed(() => !preview.value.requests && !preview.value.spans && !preview.value.scores && !preview.value.review_items && !preview.value.daily_stats);

const queryString = () => {
    const params = new URLSearchParams({ hours: String(hours.value) });
    if (pruneStats.value) params.set('stats_days', String(statsDays.value));

    return params.toString();
};

const retentionPayload = () => ({
    hours: hours.value,
    stats_days: pruneStats.value ? statsDays.value : null,
});

const loadStatus = async ({ quiet = false } = {}) => {
    if (!quiet) loading.value = true;
    try {
        status.value = await apiRequest('get', '/maintenance?' + queryString());
        schedulePoll();
    } catch (error) {
        console.error('Failed to load maintenance status:', error);
    } finally {
        loading.value = false;
    }
};

// While a queued prune or rebuild hasn't reported back, refresh every few seconds.
const schedulePoll = () => {
    clearTimeout(pollTimer);
    if (pending.value.prune || pending.value.rebuild_stats) {
        pollTimer = setTimeout(() => loadStatus({ quiet: true }), 3000);
    }
};

watch([hours, pruneStats, statsDays], () => {
    confirmingPrune.value = false;
    loadStatus({ quiet: true });
});

const prune = async () => {
    if (!confirmingPrune.value) {
        confirmingPrune.value = true;
        return;
    }

    busy.value.prune = true;
    try {
        await apiRequest('post', '/maintenance/prune', retentionPayload());
        alertSuccess('Prune queued. The numbers below update when it finishes.');
        confirmingPrune.value = false;
        await loadStatus({ quiet: true });
    } finally {
        busy.value.prune = false;
    }
};

const rebuildStats = async () => {
    busy.value.rebuild = true;
    try {
        await apiRequest('post', '/maintenance/rebuild-stats');
        alertSuccess('Stats rebuild queued.');
        await loadStatus({ quiet: true });
    } finally {
        busy.value.rebuild = false;
    }
};

const toggleRecording = async () => {
    busy.value.recording = true;
    try {
        const data = await apiRequest('post', '/maintenance/recording', { paused: !status.value.recording_paused });
        status.value.recording_paused = data.recording_paused;
        alertSuccess(data.recording_paused ? 'Recording paused.' : 'Recording resumed.');
    } finally {
        busy.value.recording = false;
    }
};

const formatDateTime = (value) => {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);

    return date.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
};

const retentionLabel = (value) => retentionOptions.find((option) => option.hours === value)?.label || `${value} hours`;

const pruneSummary = computed(() => {
    const parts = [`${formatNumber(preview.value.requests || 0)} requests`];
    if (preview.value.requests_with_media) parts.push(`the media of ${formatNumber(preview.value.requests_with_media)} of them`);
    parts.push(`${formatNumber(preview.value.spans || 0)} spans`);
    if (preview.value.scores) parts.push(`${formatNumber(preview.value.scores)} scores`);
    if (preview.value.review_items) parts.push(`${formatNumber(preview.value.review_items)} review queue items`);
    if (pruneStats.value) parts.push(`${formatNumber(preview.value.daily_stats || 0)} daily stats`);

    return parts.join(', ');
});

// The top bar's prune button opens this screen at #prune.
const route = useRoute();
onMounted(async () => {
    await loadStatus();
    if (route.hash === '#prune') {
        await nextTick();
        document.getElementById('prune')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});
onUnmounted(() => clearTimeout(pollTimer));
</script>

<template>
    <div>
        <div class="mb-6">
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Maintenance</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Retention, daily stats and recording</p>
        </div>

        <div
            v-if="status && !canManage"
            class="mb-4 rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900/70 dark:text-gray-300"
        >
            <template v-if="!status.enabled">
                Maintenance actions are turned off (<code class="font-mono text-xs">dashboard.maintenance</code>). Run them with
                <code class="font-mono text-xs">spectra:prune</code>, <code class="font-mono text-xs">spectra:rebuild-stats</code> and
                <code class="font-mono text-xs">spectra:pause</code> instead.
            </template>
            <template v-else>
                You can view this screen, but running these actions needs the <code class="font-mono text-xs">manageSpectra</code> gate.
            </template>
        </div>

        <!-- Stored data -->
        <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Requests stored" :loading="!status">
                <div class="stat-value">{{ formatNumber(totals.requests) }}</div>
            </StatCard>
            <StatCard label="Oldest request" :loading="!status">
                <div class="mt-1.5 text-xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ formatDateTime(totals.oldest_request_at) }}</div>
            </StatCard>
            <StatCard label="Spans stored" :loading="!status">
                <div class="stat-value">{{ formatNumber(totals.spans) }}</div>
            </StatCard>
            <StatCard label="Daily stats rows" :loading="!status">
                <div class="stat-value">{{ formatNumber(totals.daily_stats) }}</div>
            </StatCard>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <!-- Prune -->
            <div id="prune" class="card scroll-mt-24 lg:col-span-2">
                <div class="card-header">
                    <h2>Prune old data</h2>
                    <span v-if="pending.prune" class="badge badge-info"><span class="spinner h-2.5 w-2.5 border-[1.5px]" aria-hidden="true"></span> Queued</span>
                </div>
                <div class="card-body space-y-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Deletes requests, spans, scores and review queue items older than the retention, with the media files stored for them. Daily stats, the history behind the charts, are kept unless you prune them too. This can't be undone.
                    </p>

                    <div class="flex flex-wrap items-center gap-3 text-sm text-gray-700 dark:text-gray-300">
                        <label for="retention">Keep the last</label>
                        <select id="retention" v-model.number="hours" class="form-select form-select-sm" :disabled="!canManage">
                            <option v-for="option in retentionOptions" :key="option.hours" :value="option.hours">{{ option.label }}</option>
                        </select>
                        <span>of requests</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-3 text-sm text-gray-700 dark:text-gray-300">
                        <label class="inline-flex items-center gap-2">
                            <input v-model="pruneStats" type="checkbox" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900" :disabled="!canManage" />
                            Also delete daily stats older than
                        </label>
                        <select v-model.number="statsDays" class="form-select form-select-sm" :disabled="!canManage || !pruneStats">
                            <option v-for="days in statsRetentionOptions" :key="days" :value="days">{{ days }} days</option>
                        </select>
                    </div>

                    <div class="rounded-lg bg-gray-50 px-4 py-3 text-sm dark:bg-white/[0.03]">
                        <span v-if="!status" class="text-gray-400">Counting…</span>
                        <span v-else-if="nothingToPrune" class="text-gray-500 dark:text-gray-400">Nothing is older than {{ retentionLabel(hours) }}.</span>
                        <span v-else class="text-gray-700 dark:text-gray-300">
                            This deletes <strong class="font-semibold text-gray-900 dark:text-white">{{ pruneSummary }}</strong>.
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            :class="['btn', confirmingPrune ? 'bg-red-600 text-white shadow-sm hover:bg-red-500' : 'btn-secondary']"
                            :disabled="!canManage || nothingToPrune || busy.prune || pending.prune"
                            @click="prune"
                        >
                            <span v-if="busy.prune" class="spinner spinner-sm"></span>
                            {{ confirmingPrune ? `Delete ${formatNumber(preview.requests || 0)} requests` : 'Prune…' }}
                        </button>
                        <button v-if="confirmingPrune" type="button" class="btn btn-ghost" @click="confirmingPrune = false">Cancel</button>
                    </div>

                    <div v-if="status?.last_prune" class="border-t border-gray-100 pt-3 text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        Last pruned {{ formatDateTime(status.last_prune.at) }}, keeping {{ retentionLabel(status.last_prune.hours) }}:
                        {{ formatNumber(status.last_prune.requests) }} requests, {{ formatNumber(status.last_prune.spans) }} spans,<template v-if="status.last_prune.scores"> {{ formatNumber(status.last_prune.scores) }} scores,</template><template v-if="status.last_prune.review_items"> {{ formatNumber(status.last_prune.review_items) }} review queue items,</template>
                        {{ formatNumber(status.last_prune.media_files) }} media files and {{ formatNumber(status.last_prune.daily_stats) }} daily stats.
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <!-- Recording -->
                <div class="card">
                    <div class="card-header">
                        <h2>Recording</h2>
                        <span v-if="status" :class="['badge', status.recording_paused ? 'badge-warning' : 'badge-success']">
                            {{ status.recording_paused ? 'Paused' : 'Recording' }}
                        </span>
                    </div>
                    <div class="card-body space-y-4">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            While paused, AI calls keep working but no request, span or media is stored, so their cost doesn't count toward budgets.
                        </p>
                        <button type="button" class="btn btn-secondary" :disabled="!canManage || !status || busy.recording" @click="toggleRecording">
                            <span v-if="busy.recording" class="spinner spinner-sm"></span>
                            {{ status?.recording_paused ? 'Resume recording' : 'Pause recording' }}
                        </button>
                    </div>
                </div>

                <!-- Rebuild stats -->
                <div class="card">
                    <div class="card-header">
                        <h2>Daily stats</h2>
                        <span v-if="pending.rebuild_stats" class="badge badge-info"><span class="spinner h-2.5 w-2.5 border-[1.5px]" aria-hidden="true"></span> Queued</span>
                    </div>
                    <div class="card-body space-y-4">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Recomputes the daily stats of the days whose requests are still stored. Run it when the charts look out of step with the requests list. Days whose requests were pruned keep their stats.
                        </p>
                        <button type="button" class="btn btn-secondary" :disabled="!canManage || busy.rebuild || pending.rebuild_stats" @click="rebuildStats">
                            <span v-if="busy.rebuild" class="spinner spinner-sm"></span>
                            Rebuild stats
                        </button>
                        <div v-if="status?.last_rebuild" class="text-xs text-gray-500 dark:text-gray-400">
                            Last rebuilt {{ formatDateTime(status.last_rebuild.at) }} from {{ formatNumber(status.last_rebuild.requests) }} requests<template v-if="status.last_rebuild.from"> since {{ status.last_rebuild.from }}</template>.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <PricingCard class="mt-4" />
    </div>
</template>
