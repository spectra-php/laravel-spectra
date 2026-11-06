<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { useViewer } from '@/composables/useViewer';
import DashboardHeading from '../DashboardHeading.vue';
import SectionFailed from '../SectionFailed.vue';
import Money from '@/components/Money.vue';
import Skeleton from '@/components/Skeleton.vue';
import StatCard from '@/components/StatCard.vue';
import TraceWaterfall from '@/components/TraceWaterfall.vue';
import type { ApiError } from '@/types/app';
import type { DashboardTrace } from '@/types/api';

// One of the viewer's traces, in the dashboard's own timeline, read-only.
const route = useRoute();
const { config, fetchData, formatWhen } = useViewer();
const { formatNumber, formatCompact, formatDuration } = useHelpers();

const trace = ref<DashboardTrace | null>(null);
const missing = ref(false);
const failed = ref(false);

const subtitle = computed(() => (trace.value ? formatWhen(trace.value.started_at) : ''));

const load = async () => {
    failed.value = false;

    try {
        trace.value = await fetchData<DashboardTrace>(`/traces/${encodeURIComponent(route.params.traceId as string)}`);
    } catch (error) {
        if ((error as ApiError).response?.status === 404) {
            missing.value = true;
        } else {
            failed.value = true;
        }
    }
};

onMounted(load);
</script>

<template>
    <div>
        <router-link :to="{ name: 'traces' }" class="mb-4 inline-block text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">← Traces</router-link>
        <div v-if="missing" class="card">
            <div class="empty-state"><p class="empty-state-title">This trace isn't available</p></div>
        </div>
        <SectionFailed v-else-if="failed" @retry="load" />
        <div v-else-if="!trace" class="card"><Skeleton variant="table-row" :lines="6" /></div>
        <template v-else>
            <DashboardHeading title="Trace" :subtitle="subtitle" />
            <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2" :class="config.showCosts ? 'xl:grid-cols-4' : 'xl:grid-cols-3'">
                <StatCard label="Calls">
                    <div class="stat-value">{{ formatNumber(trace.request_count) }}</div>
                    <template #footer><div v-if="trace.failed_count" class="mt-3 text-xs text-red-600 dark:text-red-400">{{ trace.failed_count }} failed</div></template>
                </StatCard>
                <StatCard label="Duration"><div class="stat-value">{{ formatDuration(trace.duration_ms) }}</div></StatCard>
                <StatCard label="Tokens"><div class="stat-value">{{ formatCompact(trace.total_tokens) }}</div></StatCard>
                <StatCard v-if="trace.total_cost_in_cents !== null" label="Cost"><div class="stat-value"><Money :cents="trace.total_cost_in_cents" /></div></StatCard>
            </div>
            <div class="card overflow-hidden">
                <div class="card-header">
                    <div>
                        <h3>Timeline</h3>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ trace.truncated ? 'The first calls of this trace' : 'Every step of this trace' }}</p>
                    </div>
                </div>
                <TraceWaterfall :requests="trace.requests" :spans="trace.spans" :duration-ms="trace.timeline_ms" read-only />
            </div>
        </template>
    </div>
</template>
