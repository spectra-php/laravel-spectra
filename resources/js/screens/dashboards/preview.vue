<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { useLoading } from '@/composables/useLoading';
import Breadcrumb from '@/components/Breadcrumb.vue';
import Skeleton from '@/components/Skeleton.vue';
import DashboardView from '@/components/dashboards/DashboardView.vue';
import type { DashboardResponse, DashboardSummary, DashboardsResponse } from '@/types/api';

// Exactly what the viewer gets, drawn by the viewer's own component.
const route = useRoute();
const { apiRequest } = useHelpers();
const loading = useLoading();

loading.value = true;
const summary = ref<DashboardSummary | null>(null);
const preview = ref<DashboardResponse | null>(null);
const period = ref('month');
const refreshing = ref(false);

const load = async () => {
    if (!summary.value) return;

    refreshing.value = true;

    const params = new URLSearchParams({ period: period.value });

    try {
        preview.value = await apiRequest<DashboardResponse>('get', `/dashboards/${summary.value.id}/preview?${params.toString()}`);
    } catch (error) {
        console.error('Failed to load the preview:', error);
    } finally {
        refreshing.value = false;
    }
};

const changePeriod = (next: string) => {
    period.value = next;
    load();
};

onMounted(async () => {
    try {
        const screen = await apiRequest<DashboardsResponse>('get', '/dashboards');
        summary.value = screen.dashboards.find((dashboard) => dashboard.id === route.params.id) ?? null;
        period.value = summary.value?.default_period ?? 'month';
        await load();
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div>
        <Breadcrumb :items="[{ label: 'Dashboards', to: { name: 'dashboards' } }, { label: 'Preview' }]" />

        <div v-if="loading" class="card"><Skeleton variant="chart" height="240px" /></div>

        <div v-else-if="!summary" class="card">
            <div class="empty-state"><p class="empty-state-title">This dashboard doesn't exist</p></div>
        </div>

        <template v-else>
            <DashboardView :dashboard="preview" :period="period" :loading="refreshing" @update:period="changePeriod" />
        </template>
    </div>
</template>
