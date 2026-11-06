<script setup lang="ts">
import { useHelpers } from '@/composables/useHelpers';
import Money from '@/components/Money.vue';
import StatusCode from '@/components/StatusCode.vue';
import type { RequestPlaygroundRun } from '@/types/api';

// The Playground runs started from a request, each opening in the Playground for
// someone who may use it, or at its own request page for anyone else.
defineProps<{
    runs: RequestPlaygroundRun[];
    count: number;
    canOpen: boolean;
}>();

const { formatNumber, formatDuration } = useHelpers();
</script>

<template>
    <div class="card overflow-hidden">
        <div class="card-header">
            <div>
                <h3>Playground runs</h3>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    {{ count > runs.length ? `The latest ${runs.length} of ${count}.` : `${count} sent from this request.` }}
                </p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="table min-w-[40rem] table-fixed">
                <colgroup>
                    <col class="w-[10%]" />
                    <col />
                    <col class="w-[13%]" />
                    <col class="w-[11%]" />
                    <col class="w-[12%]" />
                    <col class="w-[12%]" />
                </colgroup>
                <thead>
                    <tr>
                        <th>Run</th>
                        <th>Reply</th>
                        <th class="text-right">Cost</th>
                        <th class="text-right">Tokens</th>
                        <th class="text-right">Latency</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="run in runs" :key="run.request_id ?? run.number ?? 0">
                        <td>
                            <router-link v-if="run.request_id" :to="{ name: canOpen ? 'playground-call' : 'request-preview', params: { id: run.request_id } }" class="font-medium text-primary-600 hover:underline dark:text-primary-400">Run {{ run.number }}</router-link>
                        </td>
                        <td class="truncate text-gray-600 dark:text-gray-300" v-tooltip.truncated="run.reply ?? run.error_message ?? ''">{{ run.reply ?? run.error_message ?? '—' }}</td>
                        <td class="text-right tabular-nums"><Money :cents="run.total_cost_in_cents" /></td>
                        <td class="text-right tabular-nums">{{ formatNumber(run.prompt_tokens + run.completion_tokens) }}</td>
                        <td class="text-right tabular-nums">{{ formatDuration(run.latency_ms) }}</td>
                        <td><StatusCode :code="run.status_code" :error="run.error_type" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
