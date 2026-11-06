<script setup lang="ts">
import { useHelpers } from '@/composables/useHelpers';
import Money from '@/components/Money.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import type { DashboardRequest } from '@/types/api';

defineProps<{ requests: DashboardRequest[]; showCosts: boolean }>();

const { formatNumber, formatDuration } = useHelpers();

const usage = (request: DashboardRequest) => {
    if (request.model_type === 'image') return request.images + (request.images === 1 ? ' image' : ' images');
    if (request.model_type === 'video') return request.videos + (request.videos === 1 ? ' video' : ' videos');
    if (request.model_type === 'tts') return formatNumber(request.input_characters) + ' chars';
    if (request.model_type === 'stt') return request.duration_seconds.toFixed(1) + 's';

    return formatNumber(request.tokens) + ' tokens';
};

const when = (date: string | null) => {
    if (!date) return '';

    return new Date(date).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
};
</script>

<template>
    <div class="card overflow-hidden">
        <div class="card-header">
            <h3>Recent requests</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Model</th>
                        <th class="text-right">Usage</th>
                        <th class="text-right">Latency</th>
                        <th v-if="showCosts" class="text-right">Cost</th>
                        <th>Status</th>
                        <th class="text-right">When</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(request, index) in requests" :key="index">
                        <td>
                            <div class="flex min-w-0 items-center gap-2">
                                <ProviderLogo
                                    :provider="request.provider"
                                    v-tooltip="request.provider_name ?? ''"
                                    class="inline-flex h-4 w-4 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full"
                                    aria-hidden="true"
                                />
                                <span class="max-w-56 truncate font-medium text-gray-900 dark:text-white" v-tooltip.truncated="request.model ?? ''">{{ request.model }}</span>
                            </div>
                        </td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ usage(request) }}</td>
                        <td class="text-right tabular-nums">{{ formatDuration(request.latency_ms) }}</td>
                        <td v-if="showCosts" class="text-right tabular-nums"><Money :cents="request.cost ?? 0" /></td>
                        <td><span class="badge" :class="request.failed ? 'badge-danger' : 'badge-success'">{{ request.failed ? 'Failed' : 'OK' }}</span></td>
                        <td class="whitespace-nowrap text-right">{{ when(request.created_at) }}</td>
                    </tr>
                    <tr v-if="!requests.length">
                        <td :colspan="showCosts ? 6 : 5">
                            <div class="empty-state py-8"><p class="empty-state-description">Nothing in this period</p></div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
