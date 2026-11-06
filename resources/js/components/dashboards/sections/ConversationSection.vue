<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { useViewer } from '@/composables/useViewer';
import DashboardHeading from '../DashboardHeading.vue';
import SectionFailed from '../SectionFailed.vue';
import Money from '@/components/Money.vue';
import Skeleton from '@/components/Skeleton.vue';
import type { ApiError } from '@/types/app';
import type { DashboardConversation } from '@/types/api';

// One of the viewer's conversations, turn by turn. The text shows only on a
// dashboard that shows messages; otherwise each turn is its numbers.
const route = useRoute();
const { config, fetchData, formatWhen } = useViewer();
const { formatNumber, formatDuration } = useHelpers();

const conversation = ref<DashboardConversation | null>(null);
const missing = ref(false);
const failed = ref(false);

const subtitle = computed(() => {
    if (!conversation.value) return '';

    return `${formatNumber(conversation.value.turn_count)} turns · ${formatWhen(conversation.value.last_activity_at)}`;
});

const load = async () => {
    failed.value = false;

    try {
        conversation.value = await fetchData<DashboardConversation>(`/conversations/${encodeURIComponent(route.params.conversationId as string)}`);
    } catch (error) {
        if ((error as ApiError).response?.status === 404) {
            missing.value = true;
        } else {
            failed.value = true;
        }
    }
};

onMounted(load);
</script>

<template>
    <div>
        <router-link :to="{ name: 'conversations' }" class="mb-4 inline-block text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">← Conversations</router-link>
        <div v-if="missing" class="card">
            <div class="empty-state"><p class="empty-state-title">This conversation isn't available</p></div>
        </div>
        <SectionFailed v-else-if="failed" @retry="load" />
        <div v-else-if="!conversation" class="card"><Skeleton variant="table-row" :lines="6" /></div>
        <template v-else>
            <DashboardHeading title="Conversation" :subtitle="subtitle" />
            <div class="space-y-4">
                <div v-for="(turn, index) in conversation.turns" :key="index" class="card overflow-hidden">
                    <div v-if="config.showMessages" class="space-y-3 px-5 py-4">
                        <div v-if="turn.prompt" class="flex justify-end">
                            <div class="max-w-[85%] whitespace-pre-wrap wrap-break-word rounded-2xl rounded-br-md bg-primary-600 px-4 py-2.5 text-sm text-white">{{ turn.prompt }}</div>
                        </div>
                        <div v-if="turn.reply" class="flex justify-start">
                            <div class="max-w-[85%] whitespace-pre-wrap wrap-break-word rounded-2xl rounded-bl-md bg-gray-100 px-4 py-2.5 text-sm text-gray-900 dark:bg-gray-800 dark:text-gray-100">{{ turn.reply }}</div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-2.5 text-xs text-gray-500 dark:text-gray-400" :class="config.showMessages ? 'border-t border-gray-100 dark:border-gray-800' : ''">
                        <span class="font-medium text-gray-700 dark:text-gray-200">{{ turn.turn === null ? 'Call' : `Turn ${turn.turn}` }}</span>
                        <span>{{ formatWhen(turn.started_at) }}</span>
                        <span>{{ formatNumber(turn.request_count) }} {{ turn.request_count === 1 ? 'call' : 'calls' }}</span>
                        <span v-if="turn.failed_count" class="text-red-600 dark:text-red-400">{{ turn.failed_count }} failed</span>
                        <span>{{ formatNumber(turn.total_tokens) }} tokens</span>
                        <span>{{ formatDuration(turn.duration_ms) }}</span>
                        <span v-if="turn.total_cost_in_cents !== null"><Money :cents="turn.total_cost_in_cents" /></span>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
