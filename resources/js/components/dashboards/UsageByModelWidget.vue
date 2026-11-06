<script setup lang="ts">
import { computed, ref } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import Money from '@/components/Money.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import type { DashboardUsageRow } from '@/types/api';

const props = defineProps<{ rows: DashboardUsageRow[]; showCosts: boolean }>();

const { formatNumber } = useHelpers();

const expanded = ref(false);
const visible = computed(() => (expanded.value ? props.rows : props.rows.slice(0, 10)));
</script>

<template>
    <div class="card overflow-hidden">
        <div class="card-header">
            <h3>Usage by model</h3>
            <button
                v-if="rows.length > 10 && !expanded"
                type="button"
                class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                @click="expanded = true"
            >Show all ({{ rows.length }})</button>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Model</th>
                        <th class="text-right">Requests</th>
                        <th class="text-right">Tokens</th>
                        <th v-if="showCosts" class="text-right">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in visible" :key="`${row.provider}|${row.model}`">
                        <td>
                            <div class="flex min-w-0 items-center gap-2">
                                <ProviderLogo
                                    :provider="row.provider"
                                    v-tooltip="row.provider_name ?? ''"
                                    class="inline-flex h-4 w-4 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full"
                                    aria-hidden="true"
                                />
                                <span class="max-w-56 truncate font-medium text-gray-900 dark:text-white" v-tooltip.truncated="row.model ?? ''">{{ row.model }}</span>
                            </div>
                        </td>
                        <td class="text-right tabular-nums">{{ formatNumber(row.requests) }}</td>
                        <td class="text-right tabular-nums">{{ formatNumber(row.tokens) }}</td>
                        <td v-if="showCosts" class="text-right tabular-nums font-medium text-gray-900 dark:text-white"><Money :cents="row.cost ?? 0" /></td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td :colspan="showCosts ? 4 : 3">
                            <div class="empty-state py-8"><p class="empty-state-description">Nothing in this period</p></div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
