<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import { useViewer } from '@/composables/useViewer';
import DashboardHeading, { dashboardPeriods } from '../DashboardHeading.vue';
import SectionFailed from '../SectionFailed.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import Skeleton from '@/components/Skeleton.vue';
import StatCard from '@/components/StatCard.vue';
import StatusCode from '@/components/StatusCode.vue';
import type { DashboardErrors } from '@/types/api';

// The viewer's failed calls, grouped by cause. The message shows only on a
// dashboard that shows messages.
const { config, period, fetchData, formatWhen } = useViewer();
const { formatNumber } = useHelpers();

const errors = ref<DashboardErrors | null>(null);
const failed = ref(false);

const subtitle = computed(() => [config.trackableName, dashboardPeriods.find((option) => option.value === period.value)?.label].filter(Boolean).join(' · '));
const percent = (share: number) => `${(share * 100).toLocaleString('en-US', { maximumFractionDigits: 1 })}%`;
const bars = (trend: number[]) => {
    const max = Math.max(1, ...trend);

    return trend.map((count) => ({ count, height: Math.max(12, (count / max) * 100) }));
};

const load = async () => {
    failed.value = false;

    try {
        errors.value = await fetchData<DashboardErrors>('/errors', { period: period.value });
    } catch (error) {
        failed.value = true;
    }
};

onMounted(load);
watch(period, load);
</script>

<template>
    <div>
        <DashboardHeading title="Errors" :subtitle="subtitle" :period="period" @update:period="period = $event" />
        <SectionFailed v-if="failed" @retry="load" />
        <div v-else-if="!errors" class="card"><Skeleton variant="table-row" :lines="6" /></div>
        <template v-else>
            <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                <StatCard label="Failed calls">
                    <div class="stat-value">{{ formatNumber(errors.failed_count) }}</div>
                    <template #footer><div class="mt-3 text-xs text-gray-500 dark:text-gray-400">of {{ formatNumber(errors.request_count) }} calls</div></template>
                </StatCard>
                <StatCard label="Error rate">
                    <div class="stat-value">{{ percent(errors.error_rate) }}</div>
                    <template #footer><div class="mt-3 text-xs text-gray-500 dark:text-gray-400">Calls that didn't succeed</div></template>
                </StatCard>
                <StatCard label="Groups">
                    <div class="stat-value">{{ formatNumber(errors.groups.length) }}</div>
                    <template #footer><div class="mt-3 text-xs text-gray-500 dark:text-gray-400">Distinct causes</div></template>
                </StatCard>
            </div>
            <div class="card overflow-hidden">
                <div class="card-header">
                    <div>
                        <h3>Error groups</h3>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Most failures first</p>
                    </div>
                </div>
                <div v-if="!errors.groups.length" class="empty-state">
                    <p class="empty-state-title">No failed calls in this period</p>
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
                            <tr v-for="group in errors.groups" :key="group.fingerprint" class="align-top">
                                <td>
                                    <span class="flex items-center gap-2">
                                        <ProviderLogo :provider="group.provider" class="inline-flex h-4 w-4 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full" aria-hidden="true" />
                                        <StatusCode :code="group.status_code" :error="group.error_type ?? true" />
                                        <span v-if="group.error_type" class="truncate font-mono text-[11px] text-gray-500 dark:text-gray-400">{{ group.error_type }}</span>
                                    </span>
                                    <span v-if="group.message" class="mt-1.5 line-clamp-2 wrap-break-word text-sm text-gray-900 dark:text-gray-100">{{ group.message }}</span>
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
                                        <span v-for="model in group.models" :key="model.model" class="truncate text-xs text-gray-600 dark:text-gray-300" v-tooltip.truncated="model.model">{{ model.model }}</span>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap text-right text-xs text-gray-500 dark:text-gray-400">{{ formatWhen(group.last_seen_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>
</template>
