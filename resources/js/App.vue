<script setup lang="ts">
import { ref, provide } from 'vue';
import Navigation from './components/Navigation.vue';
import Alert from './components/Alert.vue';
import LoadingBar from './components/LoadingBar.vue';
import Logo from './components/Logo.vue';
import { useSidebar } from './composables/useSidebar';
import type { AlertState, AlertType } from './types/app';

// The page sits right of the sidebar on wide screens, by its width.
const { collapsed } = useSidebar();

// Refresh remounts the current screen, so it loads its data again, and tells
// the top bar to load its own state.
const refreshKey = ref(0);
provide('refreshView', () => {
    refreshKey.value++;
});

const alert = ref<AlertState>({ type: null, message: null });
const loading = ref(false);

const showAlert = (type: AlertType, message: string) => {
    alert.value = { type, message };
    setTimeout(() => {
        alert.value = { type: null, message: null };
    }, type === 'success' ? 3000 : 5000);
};

const setLoading = (value: boolean) => {
    loading.value = value;
};

provide('showAlert', showAlert);
provide('alert', alert);
provide('setLoading', setLoading);
</script>

<template>
    <div class="relative min-h-screen bg-gray-50 text-gray-900 transition-colors duration-200 dark:bg-gray-950 dark:text-gray-100">
        <!-- Faint spectral glow behind the header -->
        <div
            class="pointer-events-none absolute inset-x-0 top-0 z-0 h-72 opacity-60 dark:opacity-100"
            style="background: radial-gradient(60% 100% at 50% 0%, rgb(111 92 246 / 0.10) 0%, transparent 70%);"
            aria-hidden="true"
        ></div>

        <LoadingBar :loading="loading" />
        <Navigation :refresh-key="refreshKey" />

        <div class="transition-[padding] duration-200" :class="collapsed ? 'lg:pl-16' : 'lg:pl-56'">
            <main class="relative mx-auto max-w-shell px-4 py-8 sm:px-6 lg:px-8">
                <transition
                    enter-active-class="transition-opacity duration-200 ease-out"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="transition-opacity duration-150 ease-in"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                    mode="out-in"
                >
                    <Alert v-if="alert.type" :type="alert.type" :message="alert.message" />
                </transition>
                <router-view v-slot="{ Component }">
                    <component :is="Component" :key="refreshKey" />
                </router-view>
            </main>

            <footer class="relative mx-auto max-w-shell px-4 pb-10 pt-4 sm:px-6 lg:px-8">
                <div class="flex flex-col items-center justify-between gap-3 border-t border-gray-200/70 pt-6 text-xs text-gray-400 sm:flex-row dark:border-gray-800/80 dark:text-gray-500">
                    <div class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
                        <Logo class="w-20" />
                        <span>AI observability for Laravel</span>
                    </div>
                    <span>
                        MIT License · &copy; 2026-present
                        <a href="https://mayahi.net" target="_blank" rel="noopener noreferrer" class="underline decoration-gray-300 underline-offset-2 hover:text-gray-600 dark:decoration-gray-700 dark:hover:text-gray-300">Ahmad Mayahi</a>
                    </span>
                </div>
            </footer>
        </div>
    </div>
</template>
