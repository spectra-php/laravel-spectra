<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import { useLoading } from '@/composables/useLoading';
import DocsLink from '@/components/DocsLink.vue';
import Money from '@/components/Money.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import Skeleton from '@/components/Skeleton.vue';
import StatusCode from '@/components/StatusCode.vue';
import type { PlaygroundResponse } from '@/types/api';

// The Playground's start screen: a new request, the latest requests sent from
// here with their runs grouped, or how to turn the Playground on. The app's own
// calls open from their page.
const { formatNumber, formatDuration, apiRequest } = useHelpers();
const loading = useLoading();

loading.value = true;
const playground = ref<PlaygroundResponse | null>(null);

onMounted(async () => {
    try {
        playground.value = await apiRequest<PlaygroundResponse>('get', '/playground');
    } catch (error) {
        console.error('Failed to load the playground:', error);
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div>
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">Playground</h1>
                    <DocsLink page="playground" />
                </div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Try a prompt or a model and compare the answers, without touching your code.</p>
            </div>
            <div v-if="playground?.allowed" class="flex shrink-0 items-center gap-2 self-start">
                <router-link :to="{ name: 'playground-new' }" class="btn btn-primary">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    New request
                </router-link>
            </div>
        </div>

        <div v-if="loading" class="card"><Skeleton variant="table-row" :lines="6" /></div>

        <template v-else-if="playground">
            <div v-if="!playground.enabled" class="card">
                <div class="empty-state">
                    <p class="empty-state-title">The Playground is off</p>
                    <p class="empty-state-description">
                        Each run is a real call that uses your API key and costs money, so you turn it on yourself. Set <code class="font-mono">SPECTRA_PLAYGROUND_ENABLED=true</code> in your environment.
                    </p>
                    <a href="https://spectraphp.com/playground" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300">Read the docs</a>
                </div>
            </div>

            <div v-else-if="!playground.allowed" class="card">
                <div class="empty-state">
                    <p class="empty-state-title">You can't use the Playground</p>
                    <p class="empty-state-description">It needs the <code class="font-mono">manageSpectra</code> gate, because each run spends money. The gate allows only the local environment until your app defines it.</p>
                </div>
            </div>

            <div v-else-if="!playground.groups.length" class="card">
                <div class="empty-state">
                    <p class="empty-state-title">Nothing sent from the Playground yet</p>
                    <p class="empty-state-description">Write a new request, or use Open in Playground on any request's page to try again from a real call.</p>
                    <router-link :to="{ name: 'playground-new' }" class="btn btn-primary mt-4">New request</router-link>
                </div>
            </div>

            <div v-else class="card overflow-hidden">
                <div class="card-header">
                    <div>
                        <h3>Playground requests</h3>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">One row per request, with the numbers of its latest run. To try again from a real call, use Open in Playground on its request page.</p>
                    </div>
                    <router-link :to="{ name: 'requests', query: { tag: 'playground', period: 'all' } }" class="text-xs font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400">View all</router-link>
                </div>
                <div class="overflow-x-auto">
                    <table class="table min-w-[48rem] table-fixed">
                        <colgroup>
                            <col class="w-[22%]" />
                            <col />
                            <col class="w-[6%]" />
                            <col class="w-[9%]" />
                            <col class="w-[8%]" />
                            <col class="w-[8%]" />
                            <col class="w-[8%]" />
                            <col class="w-[14%]" />
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Model</th>
                                <th>Last message</th>
                                <th class="text-right">Runs</th>
                                <th class="text-right">Cost</th>
                                <th class="text-right">Tokens</th>
                                <th class="text-right">Latency</th>
                                <th>Status</th>
                                <th class="text-right">When</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="group in playground.groups" :key="group.open_id" class="cursor-pointer" @click="$router.push({ name: 'playground-call', params: { id: group.open_id } })">
                                <td>
                                    <router-link :to="{ name: 'playground-call', params: { id: group.open_id } }" class="flex min-w-0 items-center gap-2 font-medium text-gray-900 hover:text-primary-600 dark:text-white dark:hover:text-primary-400" @click.stop>
                                        <ProviderLogo :provider="group.latest.provider" class="inline-flex h-4 w-4 shrink-0 items-center text-gray-500 [&>svg]:h-full [&>svg]:w-full" />
                                        <span class="truncate" v-tooltip.truncated="group.latest.model">{{ group.latest.model }}</span>
                                    </router-link>
                                </td>
                                <td class="truncate text-gray-600 dark:text-gray-300" v-tooltip.truncated="group.prompt ?? ''">{{ group.prompt ?? '—' }}</td>
                                <td class="text-right tabular-nums">{{ formatNumber(group.run_count) }}</td>
                                <td class="text-right tabular-nums"><Money :cents="group.latest.total_cost_in_cents" /></td>
                                <td class="text-right tabular-nums">{{ formatNumber(group.latest.prompt_tokens + group.latest.completion_tokens) }}</td>
                                <td class="text-right tabular-nums">{{ formatDuration(group.latest.latency_ms) }}</td>
                                <td><StatusCode :code="group.latest.status_code" :error="group.latest.error_type" /></td>
                                <td class="truncate text-right text-xs text-gray-500 dark:text-gray-400">{{ group.latest.created_at_human }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>
</template>
