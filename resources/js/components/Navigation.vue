<script setup>
import { ref } from 'vue';
import { useRoute } from 'vue-router';
import Logo from '@/components/Logo.vue';

const route = useRoute();
const mobileMenuOpen = ref(false);

const items = [
    { name: 'dashboard', match: 'dashboard', label: 'Overview', icon: 'M3.75 13.5h4.5v6.75h-4.5zM9.75 3.75h4.5v16.5h-4.5zM15.75 9h4.5v11.25h-4.5z' },
    { name: 'requests', match: 'request', label: 'Requests', icon: 'M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h10.5' },
    { name: 'traces', match: 'trace', label: 'Traces', icon: 'M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244' },
    { name: 'costs', match: 'costs', label: 'Costs', icon: 'M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z' },
    { name: 'trackables', match: 'trackable', label: 'Trackables', icon: 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z' },
];

const isActive = (match) => {
    if (match === 'dashboard') return route.name === 'dashboard';
    return route.name?.startsWith(match);
};

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
    <nav class="sticky top-0 z-50 border-b border-gray-200/70 bg-white/80 backdrop-blur-md dark:border-gray-800/80 dark:bg-gray-950/75">
        <div class="mx-auto flex h-14 max-w-shell items-center justify-between gap-6 px-4 sm:px-6 lg:px-8">
            <div class="flex items-center gap-8">
                <router-link :to="{ name: 'dashboard' }" class="text-gray-900 dark:text-white" aria-label="Spectra home">
                    <Logo class="w-40" />
                </router-link>

                <div class="hidden items-center gap-1 sm:flex">
                    <router-link
                        v-for="item in items"
                        :key="item.name"
                        :to="{ name: item.name }"
                        :class="[
                            'relative inline-flex h-8 items-center gap-2 rounded-lg px-3 text-sm font-medium transition-colors',
                            isActive(item.match)
                                ? 'bg-gray-100 text-gray-900 dark:bg-white/[0.07] dark:text-white'
                                : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/[0.04] dark:hover:text-white',
                        ]"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
                        </svg>
                        {{ item.label }}
                        <span
                            v-if="isActive(item.match)"
                            class="spectrum-bar absolute inset-x-3 -bottom-[11px] h-[2px] rounded-full"
                            aria-hidden="true"
                        ></span>
                    </router-link>
                </div>
            </div>

            <div class="flex items-center gap-1">
                <button
                    type="button"
                    @click="toggleDarkMode"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/[0.06] dark:hover:text-white"
                    :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                    :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                >
                    <svg v-if="isDark" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                    </svg>
                    <svg v-else class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                    </svg>
                </button>

                <button
                    type="button"
                    @click="mobileMenuOpen = !mobileMenuOpen"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900 sm:hidden dark:text-gray-400 dark:hover:bg-white/[0.06] dark:hover:text-white"
                    aria-label="Toggle navigation"
                    :aria-expanded="mobileMenuOpen"
                >
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                        <path v-if="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                        <path v-else stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>

        <div v-show="mobileMenuOpen" class="border-t border-gray-200/70 px-4 py-2 sm:hidden dark:border-gray-800/80">
            <router-link
                v-for="item in items"
                :key="item.name"
                :to="{ name: item.name }"
                @click="mobileMenuOpen = false"
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
        </div>
    </nav>
</template>
