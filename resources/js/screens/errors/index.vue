<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import { usePeriodQuery } from '@/composables/usePeriodQuery';
import { useLoading } from '@/composables/useLoading';
import { useCardRefresh } from '@/composables/useCardRefresh';
import type { ErrorGroup, ErrorsResponse } from '@/types/api';
import PageRangeHeader from '@/components/PageRangeHeader.vue';
import Skeleton from '@/components/Skeleton.vue';
import StatusCode from '@/components/StatusCode.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import RefreshButton from '@/components/RefreshButton.vue';

// Failed calls grouped by cause: the same provider, status, error type and
// message (ids and numbers aside) make one group, so one rate limit is one
// row however often it hit.
const { formatNumber, apiRequest } = useHelpers();
const { period, startDate, endDate, periodLabel, applyQueryState, buildQueryParams, syncQueryToRouter } = usePeriodQuery();
const loading = useLoading();
const { isRefreshing, refresh } = useCardRefresh();

loading.value = true;
const data = ref<ErrorsResponse>({ request_count: 0, failed_count: 0, error_rate: 0, ungrouped_count: 0, migrated: true, groups: [] });

const fetchErrors = async () => {
    try {
        data.value = await apiRequest<ErrorsResponse>('get', '/errors?' + buildQueryParams().toString());
    } catch (error) {
        console.error('Failed to load errors:', error);
    }
};

const load = async () => {
    loading.value = true;
    await fetchErrors();
    loading.value = false;
};

const onRangeChange = async () => {
    await syncQueryToRouter();
    await load();
};

const percent = (share: number) => `${(Number(share || 0) * 100).toLocaleString('en-US', { maximumFractionDigits: 1 })}%`;
const rateClass = (rate: number) => (rate >= 0.05 ? 'text-red-600 dark:text-red-400' : rate >= 0.01 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-white');

const relative = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
const ago = (iso: string | null) => {
    if (!iso) return '';
    const seconds = (new Date(iso).getTime() - Date.now()) / 1000;
    const units: [Intl.RelativeTimeFormatUnit, number][] = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]];
    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) return relative.format(Math.round(seconds / size), unit);
    }
    return 'just now';
};
const formatDate = (iso: string | null) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '');

// Bars from a zero baseline, so a quiet day looks quiet.
const bars = (trend: number[]) => {
    const max = Math.max(1, ...trend);
    return trend.map((count) => ({ count, height: count === 0 ? 0 : Math.max(12, Math.round((count / max) * 100)) }));
};

const callsLink = (group: ErrorGroup) => ({ name: 'requests', query: { error_fingerprint: group.fingerprint, period: period.value } });

onMounted(async () => {
    applyQueryState();
    await syncQueryToRouter(null, true);
    await load();
});
</script>

<template>
    <div>
        <PageRangeHeader
            title="Errors"
            :subtitle="`Failed calls, grouped by cause · ${periodLabel}`"
            v-model:period="period"
            v-model:start-date="startDate"
            v-model:end-date="endDate"
            :loading="loading"
            @change="onRangeChange"
        />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="card px-5 py-4">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Failed calls</p>
                <Skeleton v-if="loading" class="mt-2" width="40%" />
                <p v-else class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ formatNumber(data.failed_count) }}</p>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">of {{ formatNumber(data.request_count) }} calls</p>
            </div>
            <div class="card px-5 py-4">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Error rate</p>
                <Skeleton v-if="loading" class="mt-2" width="30%" />
                <p v-else class="mt-1 text-2xl font-semibold tabular-nums" :class="rateClass(data.error_rate)">{{ percent(data.error_rate) }}</p>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Calls without a 2xx status</p>
            </div>
            <div class="card px-5 py-4">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Groups</p>
                <Skeleton v-if="loading" class="mt-2" width="20%" />
                <p v-else class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-white">{{ formatNumber(data.groups.length) }}</p>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Distinct causes</p>
            </div>
        </div>

        <!-- Upgrades: the column is missing, or failures predate it -->
        <div v-if="!loading && !data.migrated" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            Failures are grouped once the error fingerprint migration runs. Run <code class="font-mono">php artisan spectra:install</code>, then <code class="font-mono">php artisan spectra:group-errors</code> to group the failures saved before it.
        </div>
        <div v-else-if="!loading && data.ungrouped_count > 0" class="mt-4 rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            {{ formatNumber(data.ungrouped_count) }} failed {{ data.ungrouped_count === 1 ? 'call was' : 'calls were' }} saved before failures were grouped. Run <code class="font-mono">php artisan spectra:group-errors</code> to group {{ data.ungrouped_count === 1 ? 'it' : 'them' }}.
        </div>

        <div class="card mt-4 overflow-hidden" :class="{ 'is-refreshing': isRefreshing('groups') }">
            <div class="card-header">
                <div>
                    <h3>Error groups</h3>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Most failures first; the message is the latest one</p>
                </div>
                <RefreshButton v-if="!loading" :refreshing="isRefreshing('groups')" @refresh="refresh('groups', fetchErrors)" />
            </div>

            <Skeleton v-if="loading" variant="table-row" :lines="6" />

            <div v-else-if="!data.groups.length" class="empty-state">
                <p class="empty-state-title">No failed calls in this period</p>
                <p class="empty-state-description">Every call in {{ periodLabel.toLowerCase() }} got a 2xx answer.</p>
            </div>

            <div v-else class="overflow-x-auto">
                <table class="table table-fixed">
                    <colgroup>
                        <col class="w-[50%]" />
                        <col class="w-[14%]" />
                        <col class="w-[10%]" />
                        <col class="w-[14%]" />
                        <col class="w-[12%]" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Error</th>
                            <th>Per day</th>
                            <th class="text-right">Failures</th>
                            <th>Models</th>
                            <th class="text-right">Last seen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="group in data.groups" :key="group.fingerprint" class="align-top">
                            <td>
                                <router-link :to="callsLink(group)" class="group block">
                                    <span class="flex items-center gap-2">
                                        <ProviderLogo :provider="group.provider" class="inline-flex h-4 w-4 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full" />
                                        <StatusCode :code="group.status_code" :error="group.error_type" />
                                        <span v-if="group.error_type" class="truncate font-mono text-[11px] text-gray-500 dark:text-gray-400" v-tooltip.truncated="group.error_type">{{ group.error_type.split('\\').pop() }}</span>
                                    </span>
                                    <span class="mt-1.5 line-clamp-2 wrap-break-word text-sm text-gray-900 group-hover:text-primary-600 dark:text-gray-100 dark:group-hover:text-primary-400" v-tooltip.truncated="group.message || ''">
                                        {{ group.message || `HTTP ${group.status_code ?? 'error'} with no message` }}
                                    </span>
                                </router-link>
                            </td>
                            <td>
                                <div class="flex h-8 items-end gap-[2px]" v-tooltip="`Last ${group.trend.length} days: ${group.trend.join(' · ')}`">
                                    <span
                                        v-for="(bar, index) in bars(group.trend)"
                                        :key="index"
                                        class="w-1.5 shrink-0 rounded-[1px]"
                                        :class="bar.count ? 'bg-red-400 dark:bg-red-400/80' : 'bg-gray-100 dark:bg-gray-800'"
                                        :style="{ height: bar.count ? bar.height + '%' : '2px' }"
                                    ></span>
                                </div>
                            </td>
                            <td class="text-right">
                                <span class="block font-semibold tabular-nums text-gray-900 dark:text-white">{{ formatNumber(group.failure_count) }}</span>
                                <span class="block text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ percent(group.share) }}</span>
                            </td>
                            <td>
                                <div class="flex flex-col gap-0.5">
                                    <span v-for="model in group.models" :key="model.model" class="truncate text-xs text-gray-600 dark:text-gray-300" v-tooltip.truncated="`${model.model}: ${formatNumber(model.count)}`">
                                        {{ model.model }} <span class="tabular-nums text-gray-400 dark:text-gray-500">{{ formatNumber(model.count) }}</span>
                                    </span>
                                </div>
                            </td>
                            <td class="text-right text-xs text-gray-500 dark:text-gray-400">
                                <router-link :to="{ name: 'request-preview', params: { id: group.latest_request_id } }" class="whitespace-nowrap hover:text-primary-600 dark:hover:text-primary-400" v-tooltip="`${formatDate(group.last_seen_at)}: open the latest failure`">{{ ago(group.last_seen_at) }}</router-link>
                                <span class="block whitespace-nowrap text-[11px] text-gray-400 dark:text-gray-500" v-tooltip="formatDate(group.first_seen_at)">since {{ ago(group.first_seen_at) }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
