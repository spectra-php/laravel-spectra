<script setup>
import { ref, inject, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import Logo from '@/components/Logo.vue';
import { useHelpers } from '@/composables/useHelpers';
import { useSidebar } from '@/composables/useSidebar';

// The dashboard's frame, as Telescope lays it out: a top bar with the logo
// and the actions (pause recording, prune, refresh, review, maintenance,
// dark mode), and a sidebar with the screens that collapses to icons. On
// narrow screens the screens open from a menu button instead.
const { collapsed, toggle: toggleCollapsed } = useSidebar();
const refreshView = inject('refreshView', () => {});

const route = useRoute();
const mobileMenuOpen = ref(false);
const { apiRequest, alertSuccess, alertError } = useHelpers();

const items = [
    { name: 'dashboard', match: 'dashboard', label: 'Overview', icon: 'M3.75 13.5h4.5v6.75h-4.5zM9.75 3.75h4.5v16.5h-4.5zM15.75 9h4.5v11.25h-4.5z' },
    { name: 'requests', match: 'request', label: 'Requests', icon: 'M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h10.5' },
    { name: 'traces', match: 'trace', label: 'Traces', icon: 'M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244' },
    { name: 'conversations', match: 'conversation', label: 'Conversations', icon: 'M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z' },
    { name: 'prompts', match: 'prompts', label: 'Prompts', icon: 'M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z' },
    { name: 'errors', match: 'errors', label: 'Errors', icon: 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z' },
    { name: 'evals', match: 'eval', label: 'Evals', icon: 'M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 0-6.23-.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5' },
    { name: 'costs', match: 'costs', label: 'Costs', icon: 'M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z' },
    { name: 'trackables', match: 'trackable', label: 'Trackables', icon: 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z' },
];

const isActive = (match) => {
    if (!match) return false;
    if (match === 'dashboard') return route.name === 'dashboard';
    return route.name?.startsWith(match);
};

// The Review button shows once the app configures a review queue, with the traces waiting.
const reviewQueues = ref(0);
const pendingReviews = ref(0);
const loadReviews = async () => {
    try {
        const data = await apiRequest('get', '/annotations');
        reviewQueues.value = data.queues.length;
        pendingReviews.value = data.queues.reduce((sum, queue) => sum + queue.pending_count, 0);
    } catch {
        reviewQueues.value = 0;
    }
};

// Pause and resume recording, for users the manageSpectra gate allows.
const canManage = ref(false);
const recordingPaused = ref(false);
const switchingRecording = ref(false);
const loadRecording = async () => {
    try {
        const data = await apiRequest('get', '/maintenance');
        canManage.value = data.can_manage;
        recordingPaused.value = data.recording_paused;
    } catch {
        canManage.value = false;
    }
};
const toggleRecording = async () => {
    if (switchingRecording.value) return;
    switchingRecording.value = true;
    try {
        const data = await apiRequest('post', '/maintenance/recording', { paused: !recordingPaused.value });
        recordingPaused.value = data.recording_paused;
        alertSuccess(data.recording_paused ? 'Recording paused. AI calls still work but are not stored.' : 'Recording resumed.');
        if (route.name === 'maintenance') refreshView();
    } catch {
        alertError('Recording could not be switched.');
    } finally {
        switchingRecording.value = false;
    }
};

onMounted(() => {
    loadReviews();
    loadRecording();
});
watch(() => route.name, (name, previous) => {
    if (name?.startsWith('review') || previous?.startsWith('review')) loadReviews();
    if (previous === 'maintenance') loadRecording();
    mobileMenuOpen.value = false;
});

const ICONS = {
    pause: 'M15.75 5.25v13.5m-7.5-13.5v13.5',
    play: 'M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z',
    trash: 'm14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0',
    refresh: 'M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99',
    review: 'M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3 1.5 1.5 3-3.75',
    maintenance: 'M21.75 6.75a4.5 4.5 0 0 1-4.884 4.484c-1.076-.091-2.264.071-2.95.904l-7.152 8.684a2.548 2.548 0 1 1-3.586-3.586l8.684-7.152c.833-.686.995-1.874.904-2.95a4.5 4.5 0 0 1 6.336-4.486l-3.276 3.276a3.004 3.004 0 0 0 2.25 2.25l3.276-3.276c.256.565.398 1.192.398 1.852ZM4.867 19.125h.008v.008h-.008v-.008Z',
    sun: 'M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z',
    moon: 'M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z',
    collapse: 'm18.75 4.5-7.5 7.5 7.5 7.5m-6-15L5.25 12l7.5 7.5',
};

const actionClass = (active = false) => [
    'relative inline-flex h-9 w-9 items-center justify-center rounded-lg transition-colors',
    active
        ? 'bg-gray-100 text-gray-900 dark:bg-white/[0.08] dark:text-white'
        : 'bg-gray-100/60 text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:bg-white/[0.03] dark:text-gray-400 dark:hover:bg-white/[0.07] dark:hover:text-white',
];

const linkClass = (item) => [
    'relative flex h-9 items-center gap-3 rounded-lg text-sm font-medium transition-colors',
    collapsed.value ? 'justify-center px-0' : 'px-3',
    isActive(item.match)
        ? 'bg-gray-100 text-gray-900 dark:bg-white/[0.07] dark:text-white'
        : 'text-gray-500 hover:bg-gray-100/70 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/[0.04] dark:hover:text-white',
];

const isDark = ref(false);

const applyTheme = (dark) => {
    document.documentElement.classList.toggle('dark', dark);
    isDark.value = dark;
};

const toggleDarkMode = () => {
    applyTheme(!isDark.value);
    try {
        localStorage.setItem('spectra-dark', String(isDark.value));
    } catch {
        // Storage can be unavailable (private mode); the toggle still works for this page.
    }
};

if (typeof window !== 'undefined') {
    let stored = null;
    try {
        stored = localStorage.getItem('spectra-dark');
    } catch {
        stored = null;
    }
    applyTheme(stored === 'true' || (stored === null && window.matchMedia('(prefers-color-scheme: dark)').matches));
}
</script>

<template>
    <!-- Top bar: the logo, and the actions -->
    <header class="sticky top-0 z-40 border-b border-gray-200/70 bg-white/80 backdrop-blur-md dark:border-gray-800/80 dark:bg-gray-950/75">
        <div class="flex h-16 items-center justify-between gap-3 px-4 sm:px-6">
            <div class="flex items-center gap-3">
                <button
                    type="button"
                    @click="mobileMenuOpen = !mobileMenuOpen"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900 lg:hidden dark:text-gray-400 dark:hover:bg-white/[0.06] dark:hover:text-white"
                    aria-label="Toggle navigation"
                    :aria-expanded="mobileMenuOpen"
                >
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                        <path v-if="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                        <path v-else stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
                <router-link :to="{ name: 'dashboard' }" class="text-gray-900 dark:text-white" aria-label="Spectra home">
                    <Logo class="w-28 sm:w-32" />
                </router-link>
                <span v-if="recordingPaused" class="badge badge-warning hidden sm:inline-flex">Recording paused</span>
            </div>

            <div class="flex items-center gap-1.5 sm:gap-2">
                <button
                    v-if="canManage"
                    type="button"
                    :class="actionClass(recordingPaused)"
                    :disabled="switchingRecording"
                    v-tooltip="recordingPaused ? 'Resume recording' : 'Pause recording'"
                    :aria-label="recordingPaused ? 'Resume recording' : 'Pause recording'"
                    :aria-pressed="recordingPaused"
                    @click="toggleRecording"
                >
                    <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="recordingPaused ? ICONS.play : ICONS.pause" />
                    </svg>
                </button>
                <router-link
                    v-if="canManage"
                    :to="{ name: 'maintenance', hash: '#prune' }"
                    :class="actionClass()"
                    v-tooltip="'Prune old data'"
                    aria-label="Prune old data"
                >
                    <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" />
                    </svg>
                </router-link>
                <button type="button" :class="actionClass()" class="hidden sm:inline-flex" v-tooltip="'Refresh'" aria-label="Refresh" @click="refreshView">
                    <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.refresh" />
                    </svg>
                </button>
                <router-link
                    v-if="reviewQueues"
                    :to="{ name: 'review' }"
                    :class="actionClass(isActive('review'))"
                    v-tooltip="pendingReviews ? `Review: ${pendingReviews} waiting` : 'Review'"
                    aria-label="Review"
                >
                    <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.review" />
                    </svg>
                    <span v-if="pendingReviews" class="absolute -right-1 -top-1 min-w-[1.1rem] rounded-full bg-primary-600 px-1 text-center text-[10px] font-semibold leading-[1.1rem] text-white">{{ pendingReviews > 99 ? '99+' : pendingReviews }}</span>
                </router-link>
                <router-link :to="{ name: 'maintenance' }" :class="actionClass(isActive('maintenance'))" class="hidden sm:inline-flex" v-tooltip="'Maintenance'" aria-label="Maintenance">
                    <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.maintenance" />
                    </svg>
                </router-link>
                <button type="button" :class="actionClass()" v-tooltip="isDark ? 'Switch to light mode' : 'Switch to dark mode'" :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'" @click="toggleDarkMode">
                    <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="isDark ? ICONS.sun : ICONS.moon" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Narrow screens: the screens, from the menu button -->
        <nav v-show="mobileMenuOpen" class="space-y-0.5 border-t border-gray-200/70 px-4 py-2 lg:hidden dark:border-gray-800/80" aria-label="Main">
            <router-link
                v-for="item in items"
                :key="item.name"
                :to="{ name: item.name }"
                :class="[
                    'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium',
                    isActive(item.match)
                        ? 'bg-gray-100 text-gray-900 dark:bg-white/[0.07] dark:text-white'
                        : 'text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/[0.04]',
                ]"
            >
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
                </svg>
                {{ item.label }}
            </router-link>
        </nav>
    </header>

    <!-- Wide screens: the screens, in a sidebar that collapses to icons -->
    <aside
        class="fixed bottom-0 left-0 top-16 z-30 hidden flex-col border-r border-gray-200/70 bg-white/60 backdrop-blur-md transition-[width] duration-200 lg:flex dark:border-gray-800/80 dark:bg-gray-950/60"
        :class="collapsed ? 'w-16' : 'w-56'"
    >
        <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-4" aria-label="Main">
            <router-link
                v-for="item in items"
                :key="item.name"
                :to="{ name: item.name }"
                :class="linkClass(item)"
                v-tooltip="collapsed ? item.label : undefined"
                :aria-label="collapsed ? item.label : undefined"
            >
                <span v-if="isActive(item.match)" class="spectrum-bar absolute inset-y-2 -left-3 w-[3px] rounded-r-full" aria-hidden="true"></span>
                <svg class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
                </svg>
                <span v-if="!collapsed" class="truncate">{{ item.label }}</span>
            </router-link>
        </nav>

        <div class="border-t border-gray-200/70 px-3 py-3 dark:border-gray-800/80">
            <button
                type="button"
                :class="linkClass({ match: null })"
                class="w-full"
                v-tooltip="collapsed ? 'Expand the menu' : undefined"
                :aria-label="collapsed ? 'Expand the menu' : 'Collapse the menu'"
                :aria-expanded="!collapsed"
                @click="toggleCollapsed"
            >
                <svg class="h-[18px] w-[18px] shrink-0 transition-transform" :class="collapsed ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.collapse" />
                </svg>
                <span v-if="!collapsed">Collapse</span>
            </button>
        </div>
    </aside>
</template>
