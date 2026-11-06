<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import { useLoading } from '@/composables/useLoading';
import DocsLink from '@/components/DocsLink.vue';
import Skeleton from '@/components/Skeleton.vue';
import type { DashboardSummary, DashboardsResponse } from '@/types/api';

// The saved shared dashboards: who each one is for and the URL to give them,
// or how to turn them on.
const { apiRequest } = useHelpers();
const loading = useLoading();

loading.value = true;
const screen = ref<DashboardsResponse | null>(null);
const confirmingDelete = ref<string | null>(null);
const copied = ref<string | null>(null);

const dashboards = computed(() => screen.value?.dashboards ?? []);

const load = async () => {
    try {
        screen.value = await apiRequest<DashboardsResponse>('get', '/dashboards');
    } catch (error) {
        console.error('Failed to load the dashboards:', error);
    } finally {
        loading.value = false;
    }
};

const typeName = (type: string) => type.split('\\').pop() ?? type;

const audience = (dashboard: DashboardSummary) => {
    return dashboard.trackable_name
        ? `${dashboard.trackable_name} (${typeName(dashboard.trackable_type)} #${dashboard.trackable_id})`
        : `${typeName(dashboard.trackable_type)} #${dashboard.trackable_id}`;
};

const copyLink = async (dashboard: DashboardSummary) => {
    await navigator.clipboard.writeText(dashboard.url);
    copied.value = dashboard.id;
    setTimeout(() => {
        if (copied.value === dashboard.id) copied.value = null;
    }, 2000);
};

const remove = async (dashboard: DashboardSummary) => {
    await apiRequest('delete', `/dashboards/${dashboard.id}`);
    confirmingDelete.value = null;
    await load();
};

onMounted(load);
</script>

<template>
    <div>
        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">Dashboards</h1>
                    <DocsLink page="dashboards" />
                </div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Read-only pages you share with people outside Spectra, showing only their own usage.</p>
            </div>
            <router-link v-if="screen?.allowed && screen.migrated" :to="{ name: 'dashboards-create' }" class="btn btn-primary btn-sm">New dashboard</router-link>
        </div>

        <div v-if="loading" class="card"><Skeleton variant="table-row" :lines="5" /></div>

        <template v-else-if="screen">
            <div v-if="!screen.enabled" class="card">
                <div class="empty-state">
                    <p class="empty-state-title">Dashboards are off</p>
                    <p class="empty-state-description">
                        They give people who can't open this dashboard access to data, so you turn them on yourself. Set <code class="font-mono">SPECTRA_DASHBOARDS_ENABLED=true</code> in your environment.
                    </p>
                    <a href="https://spectraphp.com/dashboards" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300">Read the docs</a>
                </div>
            </div>

            <div v-else-if="!screen.allowed" class="card">
                <div class="empty-state">
                    <p class="empty-state-title">You can't manage dashboards</p>
                    <p class="empty-state-description">It needs the <code class="font-mono">manageSpectra</code> gate, because a shared dashboard gives people access to data. The gate allows only the local environment until your app defines it.</p>
                </div>
            </div>

            <div v-else-if="!screen.migrated" class="card">
                <div class="empty-state">
                    <p class="empty-state-title">Run the dashboards migration</p>
                    <p class="empty-state-description">Run <code class="font-mono">php artisan spectra:install</code> to publish and run it.</p>
                </div>
            </div>

            <div v-else-if="!dashboards.length" class="card">
                <div class="empty-state">
                    <p class="empty-state-title">No dashboards yet</p>
                    <p class="empty-state-description">Create one for a trackable, and give them its link.</p>
                    <router-link :to="{ name: 'dashboards-create' }" class="btn btn-primary btn-sm mt-4">New dashboard</router-link>
                </div>
            </div>

            <div v-else class="card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>For</th>
                                <th class="text-right">Widgets</th>
                                <th>Costs</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="dashboard in dashboards" :key="dashboard.id">
                                <td class="font-medium text-gray-900 dark:text-white">{{ dashboard.name }}</td>
                                <td>{{ audience(dashboard) }}</td>
                                <td class="text-right tabular-nums">{{ dashboard.widgets.length }}</td>
                                <td>{{ dashboard.sections.includes('costs') ? 'Shown' : 'Hidden' }}</td>
                                <td><span class="badge" :class="dashboard.is_active ? 'badge-success' : 'badge-secondary'">{{ dashboard.is_active ? 'Active' : 'Paused' }}</span></td>
                                <td class="whitespace-nowrap text-right">
                                    <div v-if="confirmingDelete === dashboard.id" class="inline-flex items-center gap-2">
                                        <span class="text-xs text-gray-500 dark:text-gray-400">Its link stops working.</span>
                                        <button type="button" class="btn btn-secondary btn-sm" @click="confirmingDelete = null">Keep</button>
                                        <button type="button" class="btn btn-primary btn-sm" @click="remove(dashboard)">Delete</button>
                                    </div>
                                    <div v-else class="inline-flex items-center gap-3 text-xs font-medium text-primary-600 dark:text-primary-400">
                                        <button type="button" class="hover:underline" @click="copyLink(dashboard)">{{ copied === dashboard.id ? 'Copied' : 'Copy link' }}</button>
                                        <router-link class="hover:underline" :to="{ name: 'dashboards-preview', params: { id: dashboard.id } }">Preview</router-link>
                                        <router-link class="hover:underline" :to="{ name: 'dashboards-edit', params: { id: dashboard.id } }">Edit</router-link>
                                        <router-link class="hover:underline" :to="{ name: 'dashboards-create', query: { from: dashboard.id } }">Duplicate</router-link>
                                        <button type="button" class="hover:underline" @click="confirmingDelete = dashboard.id">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>
</template>
