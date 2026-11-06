<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { useViewer } from '@/composables/useViewer';
import DashboardView from '../DashboardView.vue';
import SectionFailed from '../SectionFailed.vue';
import type { DashboardResponse } from '@/types/api';

// The widgets the admin chose.
const { period, fetchData } = useViewer();

const dashboard = ref<DashboardResponse | null>(null);
const loading = ref(true);
const failed = ref(false);

const load = async () => {
    loading.value = true;
    failed.value = false;

    try {
        dashboard.value = await fetchData<DashboardResponse>('', { period: period.value });
    } catch (error) {
        failed.value = true;
    } finally {
        loading.value = false;
    }
};

onMounted(load);
watch(period, load);
</script>

<template>
    <SectionFailed v-if="failed" @retry="load" />
    <DashboardView v-else :dashboard="dashboard" :period="period" :loading="loading" @update:period="period = $event" />
</template>
