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

// Where a Playground session starts: a recent call to open and change, or how
// to turn the Playground on.
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
        <div class="mb-6">
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">Playground</h1>
                <DocsLink page="playground" />
            </div>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Open a real call, change its prompt or model, and compare the answers.</p>
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

            <div v-else-if="!playground.calls.length" class="card">
                <div class="empty-state">
                    <p class="empty-state-title">No calls to start from yet</p>
                    <p class="empty-state-description">The Playground opens text calls whose request body was stored. Make a chat call from your app, and it shows up here.</p>
                </div>
            </div>

            <div v-else class="card overflow-hidden">
                <div class="card-header">
                    <div>
                        <h3>Start from a recent call</h3>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Or open any text call from its page in the requests list.</p>
                    </div>
                    <router-link :to="{ name: 'requests' }" class="text-xs font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400">All requests</router-link>
                </div>
                <div class="overflow-x-auto">
                    <table class="table table-fixed">
                        <colgroup>
                            <col class="w-[24%]" />
                            <col />
                            <col class="w-[9%]" />
                            <col class="w-[9%]" />
                            <col class="w-[9%]" />
                            <col class="w-[8%]" />
                            <col class="w-[11%]" />
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Model</th>
                                <th>Last message</th>
                                <th class="text-right">Cost</th>
                                <th class="text-right">Tokens</th>
                                <th class="text-right">Latency</th>
                                <th>Status</th>
                                <th class="text-right">When</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="call in playground.calls" :key="call.id" class="cursor-pointer" @click="$router.push({ name: 'playground-call', params: { id: call.id } })">
                                <td>
                                    <router-link :to="{ name: 'playground-call', params: { id: call.id } }" class="flex min-w-0 items-center gap-2 font-medium text-gray-900 hover:text-primary-600 dark:text-white dark:hover:text-primary-400" @click.stop>
                                        <ProviderLogo :provider="call.provider" class="inline-flex h-4 w-4 shrink-0 items-center text-gray-500 [&>svg]:h-full [&>svg]:w-full" />
                                        <span class="truncate" v-tooltip.truncated="call.model">{{ call.model }}</span>
                                    </router-link>
                                </td>
                                <td class="truncate text-gray-600 dark:text-gray-300" v-tooltip.truncated="call.prompt ?? ''">{{ call.prompt ?? '—' }}</td>
                                <td class="text-right tabular-nums"><Money :cents="call.total_cost_in_cents" /></td>
                                <td class="text-right tabular-nums">{{ formatNumber(call.prompt_tokens + call.completion_tokens) }}</td>
                                <td class="text-right tabular-nums">{{ formatDuration(call.latency_ms) }}</td>
                                <td><StatusCode :code="call.status_code" :error="call.error_type" /></td>
                                <td class="truncate text-right text-xs text-gray-500 dark:text-gray-400">{{ call.created_at_human }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>
</template>
