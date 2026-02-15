<script setup>
import { ref, provide } from 'vue';
import Navigation from './components/Navigation.vue';
import Alert from './components/Alert.vue';
import LoadingBar from './components/LoadingBar.vue';
import Logo from './components/Logo.vue';

const alert = ref({ type: null, message: null });
const loading = ref(false);

const showAlert = (type, message) => {
    alert.value = { type, message };
    setTimeout(() => {
        alert.value = { type: null, message: null };
    }, type === 'success' ? 3000 : 5000);
};

const setLoading = (value) => {
    loading.value = value;
};

provide('showAlert', showAlert);
provide('alert', alert);
provide('setLoading', setLoading);
</script>

<template>
    <div class="min-h-screen bg-gray-50 dark:bg-gray-900 transition-colors duration-200">
        <LoadingBar :loading="loading" />
        <Navigation />

        <main class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
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
            <router-view />
        </main>

        <footer class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col items-center gap-0.5 text-xs text-gray-400 dark:text-gray-500">
                <Logo class="w-20" />
                <span>Released under the MIT License.</span>
                <span>Copyright &copy; 2026-present <a href="https://mayahi.net" target="_blank" rel="noopener noreferrer" class="hover:text-gray-600 dark:hover:text-gray-300 underline">Ahmad Mayahi</a></span>
            </div>
        </footer>
    </div>
</template>
