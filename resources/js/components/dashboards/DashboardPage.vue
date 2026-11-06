<script setup lang="ts">
import { computed } from 'vue';
import { useViewer } from '@/composables/useViewer';

// The page a shared dashboard's viewer opens, on its own bundle: a header with
// the pages the admin turned on, and no header when there is only the overview.
const { config } = useViewer();

const labels: Record<string, string> = {
    requests: 'Requests',
    traces: 'Traces',
    conversations: 'Conversations',
    errors: 'Errors',
    costs: 'Costs',
};

const links = computed(() => config.sections.map((section) => ({ name: section, label: labels[section] ?? section })));
</script>

<template>
    <div class="relative min-h-screen bg-gray-50 text-gray-900 dark:bg-gray-950 dark:text-gray-100">
        <!-- The same faint glow as the dashboard's header -->
        <div
            class="pointer-events-none absolute inset-x-0 top-0 z-0 h-72 opacity-60 dark:opacity-100"
            style="background: radial-gradient(60% 100% at 50% 0%, rgb(111 92 246 / 0.10) 0%, transparent 70%);"
            aria-hidden="true"
        ></div>

        <header v-if="links.length" class="relative border-b border-gray-200/80 bg-white/80 backdrop-blur-sm dark:border-gray-800 dark:bg-gray-900/70">
            <nav class="mx-auto flex max-w-shell gap-1 overflow-x-auto px-4 py-2 sm:px-6 lg:px-8" aria-label="Dashboard pages">
                <router-link
                    :to="{ name: 'overview' }"
                    class="shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white"
                    exact-active-class="bg-gray-100 text-gray-900! dark:bg-gray-800 dark:text-white!"
                >Overview</router-link>
                <router-link
                    v-for="link in links"
                    :key="link.name"
                    :to="{ name: link.name }"
                    class="shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white"
                    active-class="bg-gray-100 text-gray-900! dark:bg-gray-800 dark:text-white!"
                >{{ link.label }}</router-link>
            </nav>
        </header>

        <main class="relative mx-auto max-w-shell px-4 py-8 sm:px-6 lg:px-8">
            <router-view />
        </main>
    </div>
</template>
