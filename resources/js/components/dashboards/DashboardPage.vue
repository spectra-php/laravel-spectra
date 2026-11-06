<script setup lang="ts">
import { onMounted, ref } from 'vue';
import axios from 'axios';
import DashboardView from './DashboardView.vue';
import type { ApiError } from '@/types/app';
import type { DashboardResponse } from '@/types/api';

// The page a shared dashboard's viewer opens, on its own bundle. It reads only
// the dashboard's own data URL, because viewers can't reach the dashboard API.
const { dataUrl, period: defaultPeriod } = window.SpectraViewer;

const dashboard = ref<DashboardResponse | null>(null);
const period = ref(defaultPeriod);
const loading = ref(true);
const failed = ref(false);

const load = async () => {
    loading.value = true;
    failed.value = false;

    try {
        const response = await axios.get<DashboardResponse>(dataUrl, {
            params: { period: period.value },
            headers: { Accept: 'application/json' },
        });
        dashboard.value = response.data;
    } catch (error) {
        // The session ended while the page was open: reloading goes through the login.
        if ((error as ApiError).response?.status === 401) {
            window.location.reload();
            return;
        }

        failed.value = true;
    } finally {
        loading.value = false;
    }
};

const changePeriod = (next: string) => {
    period.value = next;
    load();
};

onMounted(load);
</script>

<template>
    <div class="min-h-screen bg-gray-50 text-gray-900 dark:bg-gray-950 dark:text-gray-100">
        <main class="mx-auto max-w-shell px-4 py-8 sm:px-6 lg:px-8">
            <div v-if="failed" class="card">
                <div class="empty-state">
                    <p class="empty-state-title">This dashboard could not be loaded</p>
                    <p class="empty-state-description">Please try again in a moment.</p>
                    <button type="button" class="btn btn-secondary btn-sm mt-4" @click="load">Try again</button>
                </div>
            </div>
            <DashboardView v-else :dashboard="dashboard" :period="period" :loading="loading" @update:period="changePeriod" />
        </main>
    </div>
</template>
