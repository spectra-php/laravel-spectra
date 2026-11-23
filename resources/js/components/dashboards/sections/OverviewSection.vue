<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { useViewer } from '@/composables/useViewer';
import { useLatest } from '@/composables/useLatest';
import DashboardView from '../DashboardView.vue';
import SectionFailed from '../SectionFailed.vue';
import type { DashboardResponse } from '@/types/api';

// The widgets the admin chose.
const { period, fetchData } = useViewer();

const dashboard = ref<DashboardResponse | null>(null);
const loading = ref(true);
const failed = ref(false);

// A period changed twice in a row keeps the answer to the second.
const beginLoad = useLatest();

const load = async () => {
    const isLatest = beginLoad();
    loading.value = true;
    failed.value = false;

    try {
        const data = await fetchData<DashboardResponse>('', { period: period.value });
        if (isLatest()) dashboard.value = data;
    } catch (error) {
        if (isLatest()) failed.value = true;
    } finally {
        if (isLatest()) loading.value = false;
    }
};

onMounted(load);
watch(period, load);
</script>

<template>
    <SectionFailed v-if="failed" @retry="load" />
    <DashboardView v-else :dashboard="dashboard" :period="period" :loading="loading" @update:period="period = $event" />
</template>
