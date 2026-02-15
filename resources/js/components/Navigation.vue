<script setup>
import { ref } from 'vue';
import { useRoute } from 'vue-router';
import Logo from "@/components/Logo.vue";

const route = useRoute();
const mobileMenuOpen = ref(false);

const isActive = (name) => {
    if (name === 'dashboard') return route.name === 'dashboard';
    return route.name?.startsWith(name);
};

const navClass = (name) => {
    return isActive(name)
        ? 'border-primary-500 text-gray-900 dark:text-white'
        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200';
};

const mobileNavClass = (name) => {
    return isActive(name)
        ? 'bg-primary-50 dark:bg-primary-900/20 border-primary-500 text-primary-700 dark:text-primary-400'
        : 'border-transparent text-gray-600 hover:bg-gray-50 hover:border-gray-300 hover:text-gray-800 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200';
};

const isDark = ref(false);

const toggleDarkMode = () => {
    document.documentElement.classList.toggle('dark');
    isDark.value = document.documentElement.classList.contains('dark');
    localStorage.setItem('spectra-dark', isDark.value);
};

// Initialize dark mode
if (typeof window !== 'undefined') {
    const stored = localStorage.getItem('spectra-dark');
    if (stored === 'true' || (!stored && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
        isDark.value = true;
    }
}
</script>

<template>
    <nav class="bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex">
                    <!-- Logo -->
                    <div class="flex-shrink-0 flex items-center">
                        <router-link :to="{ name: 'dashboard' }" class="flex items-center">
                          <Logo class="w-40"/>
                        </router-link>
                    </div>

                    <!-- Desktop Navigation -->
                    <div class="hidden sm:ml-8 sm:flex sm:space-x-1">
                        <router-link
                            :to="{ name: 'dashboard' }"
                            :class="['inline-flex items-center px-4 py-2 border-b-2 text-sm font-medium transition-colors', navClass('dashboard')]"
                        >
                            <svg class="w-4 h-4 mr-2 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                            </svg>
                            Dashboard
                        </router-link>
                        <router-link
                            :to="{ name: 'requests' }"
                            :class="['inline-flex items-center px-4 py-2 border-b-2 text-sm font-medium transition-colors', navClass('request')]"
                        >
                            <svg class="w-4 h-4 mr-2 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                            </svg>
                            Requests
                        </router-link>
                        <router-link
                            :to="{ name: 'costs' }"
                            :class="['inline-flex items-center px-4 py-2 border-b-2 text-sm font-medium transition-colors', navClass('costs')]"
                        >
                            <svg class="w-4 h-4 mr-2 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            Costs
                        </router-link>
                        <router-link
                            :to="{ name: 'trackables' }"
                            :class="['inline-flex items-center px-4 py-2 border-b-2 text-sm font-medium transition-colors', navClass('trackable')]"
                        >
                            <svg class="w-4 h-4 mr-2 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                            Trackables
                        </router-link>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <!-- Dark mode toggle -->
                    <button
                        @click="toggleDarkMode"
                        class="p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-gray-700 transition-colors"
                        :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                    >
                        <svg v-if="isDark" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" clip-rule="evenodd"/>
                        </svg>
                        <svg v-else class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"/>
                        </svg>
                    </button>

                    <!-- Mobile menu button -->
                    <button
                        @click="mobileMenuOpen = !mobileMenuOpen"
                        class="sm:hidden p-2 rounded-lg text-gray-500 hover:text-gray-700 hover:bg-gray-100 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-gray-700 transition-colors"
                    >
                        <svg v-if="!mobileMenuOpen" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                        <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile menu -->
        <div v-show="mobileMenuOpen" class="sm:hidden border-t border-gray-200 dark:border-gray-700">
            <div class="py-2 space-y-1">
                <router-link
                    :to="{ name: 'dashboard' }"
                    @click="mobileMenuOpen = false"
                    :class="['block pl-3 pr-4 py-3 border-l-4 text-base font-medium transition-colors', mobileNavClass('dashboard')]"
                >
                    Dashboard
                </router-link>
                <router-link
                    :to="{ name: 'requests' }"
                    @click="mobileMenuOpen = false"
                    :class="['block pl-3 pr-4 py-3 border-l-4 text-base font-medium transition-colors', mobileNavClass('request')]"
                >
                    Requests
                </router-link>
                <router-link
                    :to="{ name: 'costs' }"
                    @click="mobileMenuOpen = false"
                    :class="['block pl-3 pr-4 py-3 border-l-4 text-base font-medium transition-colors', mobileNavClass('costs')]"
                >
                    Costs
                </router-link>
                <router-link
                    :to="{ name: 'trackables' }"
                    @click="mobileMenuOpen = false"
                    :class="['block pl-3 pr-4 py-3 border-l-4 text-base font-medium transition-colors', mobileNavClass('trackable')]"
                >
                    Trackables
                </router-link>
            </div>
        </div>
    </nav>
</template>
