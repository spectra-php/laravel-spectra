<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { useLoading } from '@/composables/useLoading';
import Breadcrumb from '@/components/Breadcrumb.vue';
import Skeleton from '@/components/Skeleton.vue';
import DashboardView from '@/components/dashboards/DashboardView.vue';
import type { DashboardResponse, DashboardSummary, DashboardsResponse } from '@/types/api';

// Exactly what a viewer gets, drawn by the viewer's own component. A dashboard
// for every trackable of a type has no owner, so the admin picks one to preview.
const route = useRoute();
const { apiRequest } = useHelpers();
const loading = useLoading();

loading.value = true;
const summary = ref<DashboardSummary | null>(null);
const preview = ref<DashboardResponse | null>(null);
const period = ref('month');
const trackableId = ref('');
const refreshing = ref(false);

const needsTrackable = computed(() => summary.value?.trackable_id === null);

const load = async () => {
    if (!summary.value || (needsTrackable.value && !trackableId.value.trim())) return;

    refreshing.value = true;

    const params = new URLSearchParams({ period: period.value });
    if (needsTrackable.value) params.set('trackable_id', trackableId.value.trim());

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
            <form v-if="needsTrackable" class="card card-body mb-6 flex flex-wrap items-end gap-3" @submit.prevent="load">
                <div class="min-w-48 flex-1">
                    <label for="preview-id" class="stat-label mb-1.5 block">Preview as trackable ID</label>
                    <input id="preview-id" v-model="trackableId" type="text" required class="form-input" />
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Show</button>
            </form>

            <div v-if="needsTrackable && !preview" class="card">
                <div class="empty-state">
                    <p class="empty-state-title">Choose whose data to preview</p>
                    <p class="empty-state-description">Every viewer of this dashboard sees only their own data.</p>
                </div>
            </div>
            <DashboardView v-else :dashboard="preview" :period="period" :loading="refreshing" @update:period="changePeriod" />
        </template>
    </div>
</template>
