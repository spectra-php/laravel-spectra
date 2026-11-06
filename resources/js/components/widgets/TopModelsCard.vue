<script setup lang="ts">
import { computed } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import Money from '@/components/Money.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import Skeleton from '@/components/Skeleton.vue';

// The most used models, on the Overview and on a shared dashboard. A page that
// hides costs has no Cost column.
const props = withDefaults(defineProps<{
    models: { provider: string | null; providerName: string | null; model: string | null; count: number; cost: number | null }[];
    showCosts?: boolean;
    loading?: boolean;
}>(), {
    showCosts: true,
    loading: false,
});

const { formatNumber } = useHelpers();

const max = computed(() => Math.max(1, ...props.models.map((model) => Number(model.count || 0))));
</script>

<template>
    <div class="card h-full overflow-hidden">
        <div class="card-header">
            <div>
                <h3>Top models</h3>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">By request volume</p>
            </div>
            <slot name="action" />
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Model</th>
                        <th class="text-right">Requests</th>
                        <th v-if="showCosts" class="text-right">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-if="loading">
                        <Skeleton variant="table-row" :lines="5" />
                    </template>
                    <template v-else>
                        <tr v-for="model in models" :key="`${model.provider}|${model.model}`">
                            <td class="max-w-0 w-[55%]">
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <ProviderLogo
                                        :provider="model.provider"
                                        v-tooltip="model.providerName ?? ''"
                                        class="inline-flex h-4 w-4 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full"
                                        aria-hidden="true"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate font-medium text-gray-900 dark:text-white" v-tooltip.truncated="model.model ?? ''">{{ model.model }}</div>
                                        <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                            <div class="h-full rounded-full bg-primary-500/70" :style="{ width: (model.count / max) * 100 + '%' }"></div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-right tabular-nums">{{ formatNumber(model.count) }}</td>
                            <td v-if="showCosts" class="text-right tabular-nums font-medium text-gray-900 dark:text-white"><Money :cents="model.cost ?? 0" /></td>
                        </tr>
                        <tr v-if="!models.length">
                            <td :colspan="showCosts ? 3 : 2">
                                <div class="empty-state">
                                    <p class="empty-state-title">No models used in this period</p>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</template>
