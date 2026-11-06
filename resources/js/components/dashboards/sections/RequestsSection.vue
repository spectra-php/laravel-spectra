<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useViewer } from '@/composables/useViewer';
import DashboardHeading, { dashboardPeriods } from '../DashboardHeading.vue';
import ListPager from '../ListPager.vue';
import SectionFailed from '../SectionFailed.vue';
import RecentRequestsCard from '@/components/widgets/RecentRequestsCard.vue';
import type { DashboardCallMessages, DashboardRequest, PaginatedResponse } from '@/types/api';

// The viewer's calls, in the Overview's own list. A call opens only on a
// dashboard that shows messages.
const { config, period, fetchData } = useViewer();

const page = ref<PaginatedResponse<DashboardRequest> | null>(null);
const loading = ref(true);
const failed = ref(false);
const opened = ref<DashboardCallMessages | null>(null);

const subtitle = computed(() => [config.trackableName, dashboardPeriods.find((option) => option.value === period.value)?.label].filter(Boolean).join(' · '));

const load = async (number = 1) => {
    loading.value = true;
    failed.value = false;

    try {
        page.value = await fetchData<PaginatedResponse<DashboardRequest>>('/requests', { period: period.value, page: number });
    } catch (error) {
        failed.value = true;
    } finally {
        loading.value = false;
    }
};

const open = async (id: string) => {
    opened.value = await fetchData<DashboardCallMessages>(`/requests/${id}`);
};

onMounted(() => load());
watch(period, () => load());
</script>

<template>
    <div>
        <DashboardHeading title="Requests" :subtitle="subtitle" :period="period" @update:period="period = $event" />
        <SectionFailed v-if="failed" @retry="load()" />
        <RecentRequestsCard
            v-else
            title="All requests"
            :description="page ? `${page.total} in this period` : 'Loading'"
            :requests="page?.data ?? []"
            :clickable="config.showMessages"
            :loading="loading"
            @select="open"
        >
            <template #footer>
                <ListPager v-if="page" :page="page.current_page" :last-page="page.last_page" @change="load" />
            </template>
        </RecentRequestsCard>

        <div v-if="opened" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4 backdrop-blur-xs" @click="opened = null">
            <div class="flex max-h-[85vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-pop dark:border-gray-800 dark:bg-gray-900" @click.stop>
                <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ opened.model }}</h3>
                    <button type="button" class="btn-ghost inline-flex h-8 w-8 items-center justify-center rounded-lg" @click="opened = null">
                        <span class="sr-only">Close</span>
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>
                <div class="space-y-3 overflow-auto px-5 py-4">
                    <div v-if="opened.prompt" class="flex justify-end">
                        <div class="max-w-[85%] whitespace-pre-wrap wrap-break-word rounded-2xl rounded-br-md bg-primary-600 px-4 py-2.5 text-sm text-white">{{ opened.prompt }}</div>
                    </div>
                    <div v-if="opened.reply" class="flex justify-start">
                        <div class="max-w-[85%] whitespace-pre-wrap wrap-break-word rounded-2xl rounded-bl-md bg-gray-100 px-4 py-2.5 text-sm text-gray-900 dark:bg-gray-800 dark:text-gray-100">{{ opened.reply }}</div>
                    </div>
                    <p v-if="!opened.prompt && !opened.reply" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">No message text was stored for this call.</p>
                </div>
            </div>
        </div>
    </div>
</template>
