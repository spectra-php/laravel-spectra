<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { useViewer } from '@/composables/useViewer';
import DashboardHeading, { dashboardPeriods } from '../DashboardHeading.vue';
import ListPager from '../ListPager.vue';
import SectionFailed from '../SectionFailed.vue';
import Money from '@/components/Money.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import Skeleton from '@/components/Skeleton.vue';
import type { DashboardListedConversation, PaginatedResponse } from '@/types/api';

// The viewer's conversations. One holding anyone else's calls never comes back.
const router = useRouter();
const { config, period, fetchData, formatWhen } = useViewer();
const { formatNumber } = useHelpers();

const page = ref<PaginatedResponse<DashboardListedConversation> | null>(null);
const loading = ref(true);
const failed = ref(false);

const subtitle = computed(() => [config.trackableName, dashboardPeriods.find((option) => option.value === period.value)?.label].filter(Boolean).join(' · '));

const load = async (number = 1) => {
    loading.value = true;
    failed.value = false;

    try {
        page.value = await fetchData<PaginatedResponse<DashboardListedConversation>>('/conversations', { period: period.value, page: number });
    } catch (error) {
        failed.value = true;
    } finally {
        loading.value = false;
    }
};

onMounted(() => load());
watch(period, () => load());

</script>

<template>
    <div>
        <DashboardHeading title="Conversations" :subtitle="subtitle" :period="period" @update:period="period = $event" />
        <SectionFailed v-if="failed" @retry="load()" />
        <div v-else class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Started</th>
                            <th>Models</th>
                            <th class="text-right">Turns</th>
                            <th class="text-right">Calls</th>
                            <th class="text-right">Tokens</th>
                            <th v-if="config.showCosts" class="text-right">Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        <Skeleton v-if="loading" variant="table-row" :lines="6" />
                        <template v-else>
                            <tr
                                v-for="row in page?.data ?? []"
                                :key="row.conversation_id"
                                class="cursor-pointer"
                                @click="router.push({ name: 'conversation', params: { conversationId: row.conversation_id } })"
                            >
                                <td class="whitespace-nowrap font-medium text-gray-900 dark:text-white">{{ formatWhen(row.started_at) }}</td>
                                <td class="max-w-0 w-[40%]">
                                    <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                                        <span v-for="model in row.models.slice(0, 3)" :key="`${model.provider}|${model.model}`" class="flex min-w-0 items-center gap-1.5">
                                            <ProviderLogo :provider="model.provider" class="inline-flex h-3.5 w-3.5 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full" aria-hidden="true" />
                                            <span class="truncate text-sm" v-tooltip.truncated="model.model ?? ''">{{ model.model }}</span>
                                        </span>
                                        <span v-if="row.models.length > 3" class="text-xs text-gray-500 dark:text-gray-400">+{{ row.models.length - 3 }}</span>
                                    </div>
                                </td>
                                <td class="text-right tabular-nums">{{ formatNumber(row.turn_count) }}</td>
                                <td class="text-right tabular-nums">{{ formatNumber(row.request_count) }}<span v-if="row.failed_count" class="ml-1.5 text-xs text-red-600 dark:text-red-400">{{ row.failed_count }} failed</span></td>
                                <td class="text-right tabular-nums">{{ formatNumber(row.total_tokens) }}</td>
                                <td v-if="config.showCosts" class="text-right tabular-nums font-medium text-gray-900 dark:text-white"><Money :cents="row.total_cost_in_cents ?? 0" /></td>
                            </tr>
                            <tr v-if="!page?.data.length">
                                <td :colspan="config.showCosts ? 6 : 5">
                                    <div class="empty-state"><p class="empty-state-title">No conversations in this period</p></div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <ListPager v-if="page" :page="page.current_page" :last-page="page.last_page" @change="load" />
        </div>
    </div>
</template>
