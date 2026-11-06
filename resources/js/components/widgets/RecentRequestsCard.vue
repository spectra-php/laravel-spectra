<script setup lang="ts">
import { useHelpers } from '@/composables/useHelpers';
import ProviderLogo from '@/components/ProviderLogo.vue';
import Skeleton from '@/components/Skeleton.vue';
import StatusCode from '@/components/StatusCode.vue';

// The latest calls, on the Overview and on a shared dashboard. A row opens the
// call only where the page has an id and listens for `select`: a viewer gets no
// ids, and can't open a call.
type RecentRow = {
    id?: string;
    provider: string | null;
    provider_display_name?: string | null;
    model: string | null;
    model_type?: string | null;
    prompt_tokens?: number | null;
    completion_tokens?: number | null;
    image_count?: number | null;
    video_count?: number | null;
    input_characters?: number | null;
    duration_seconds?: number | null;
    status_code?: number | null;
    error_type?: string | null;
    failed?: boolean;
    created_at_human?: string | null;
};

const props = withDefaults(defineProps<{
    requests: RecentRow[];
    clickable?: boolean;
    loading?: boolean;
}>(), {
    clickable: false,
    loading: false,
});

const emit = defineEmits<{ select: [id: string] }>();

const { formatNumber, truncate } = useHelpers();

const open = (request: RecentRow) => {
    if (props.clickable && request.id) emit('select', request.id);
};

const usage = (request: RecentRow) => {
    const type = request.model_type;
    if (type === 'image') {
        const count = request.image_count || 0;
        return count + (count === 1 ? ' image' : ' images');
    }
    if (type === 'tts') {
        return formatNumber(request.input_characters || 0) + ' chars';
    }
    if (type === 'video') {
        const count = request.video_count || 0;
        return count + (count === 1 ? ' video' : ' videos');
    }
    if (type === 'stt') {
        const duration = request.duration_seconds || 0;
        return duration.toFixed(1) + 's';
    }
    return formatNumber((request.prompt_tokens || 0) + (request.completion_tokens || 0));
};
</script>

<template>
    <div class="card h-full overflow-hidden">
        <div class="card-header">
            <div>
                <h3>Recent requests</h3>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Latest activity</p>
            </div>
            <slot name="action" />
        </div>
        <div class="overflow-x-auto">
            <table class="table table-fixed">
                <colgroup>
                    <col class="w-[46%]" />
                    <col class="w-[20%]" />
                    <col class="w-[14%]" />
                    <col class="w-[20%]" />
                </colgroup>
                <thead>
                    <tr>
                        <th>Model</th>
                        <th class="text-right">Usage</th>
                        <th>Status</th>
                        <th class="text-right">When</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-if="loading">
                        <Skeleton variant="table-row" :lines="5" />
                    </template>
                    <template v-else>
                        <tr
                            v-for="(request, index) in requests"
                            :key="request.id ?? index"
                            :class="clickable ? 'group cursor-pointer' : ''"
                            @click="open(request)"
                        >
                            <td class="max-w-0">
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <ProviderLogo
                                        :provider="request.provider"
                                        v-tooltip="request.provider_display_name ?? ''"
                                        class="inline-flex h-4 w-4 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full"
                                        aria-hidden="true"
                                    />
                                    <span class="truncate font-medium text-gray-900 dark:text-white" v-tooltip.truncated="request.model || ''">{{ truncate(request.model, 32) }}</span>
                                </div>
                            </td>
                            <td class="text-right tabular-nums">{{ usage(request) }}</td>
                            <td><StatusCode :code="request.status_code" :error="request.error_type ?? request.failed" /></td>
                            <td class="whitespace-nowrap text-right text-gray-500 transition-colors group-hover:text-primary-600! dark:text-gray-400 dark:group-hover:text-primary-400!">
                                {{ request.created_at_human }}
                            </td>
                        </tr>
                        <tr v-if="!requests.length">
                            <td colspan="4">
                                <div class="empty-state">
                                    <p class="empty-state-title">No requests yet</p>
                                    <p class="empty-state-description">Requests to AI providers will show up here as your app makes them.</p>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</template>
