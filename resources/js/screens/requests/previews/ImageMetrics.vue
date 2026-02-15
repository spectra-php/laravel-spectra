<script setup>
import { computed, ref } from 'vue';
import { useHelpers } from '@/composables/useHelpers';

const props = defineProps({
    request: { type: Object, required: true },
    expanded: { type: Boolean, default: true },
});

const emit = defineEmits(['toggle']);

const { formatNumber, formatCurrency } = useHelpers();

const hasTokens = computed(() => (props.request.prompt_tokens || 0) + (props.request.completion_tokens || 0) > 0);

const imageUrls = computed(() => {
    if (props.request.image_urls?.length) {
        return props.request.image_urls;
    }
    const data = props.request.response?.data || [];
    return data.filter(item => item.url).map(item => item.url);
});

const costPerImage = computed(() => {
    const count = props.request.image_count || 1;
    return props.request.total_cost_in_cents / count;
});

const imagesExpanded = ref(false);
</script>

<template>
    <div class="space-y-6">
        <div class="card">
            <div
                class="card-header cursor-pointer select-none"
                :class="{ 'border-b-0': !expanded }"
                @click="emit('toggle')"
            >
                <div class="flex items-center justify-between w-full">
                    <div class="flex items-center gap-2">
                        <svg
                            class="w-4 h-4 text-gray-400 transition-transform duration-200"
                            :class="{ 'rotate-90': expanded }"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                        <h3 class="text-sm font-medium">Image Generation</h3>
                    </div>
                </div>
            </div>
            <div v-if="expanded" class="card-body">
                <!-- Token-based models (gpt-image-1, gpt-image-1.5) -->
                <div v-if="hasTokens" class="grid grid-cols-4 gap-6">
                    <div class="text-center p-4 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Input Tokens</div>
                        <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                            {{ formatNumber(request.prompt_tokens || 0) }}
                        </div>
                    </div>
                    <div class="text-center p-4 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Output Tokens</div>
                        <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                            {{ formatNumber(request.completion_tokens || 0) }}
                        </div>
                    </div>
                    <div class="text-center p-4 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Images Generated</div>
                        <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                            {{ formatNumber(request.image_count || 0) }}
                        </div>
                    </div>
                    <div class="text-center p-4 rounded-lg bg-primary-50 dark:bg-primary-900/20">
                        <div class="text-sm font-medium text-primary-600 dark:text-primary-400 mb-1">Cost</div>
                        <div class="text-2xl font-bold text-primary-700 dark:text-primary-300 tabular-nums">
                            {{ formatCurrency(request.total_cost_in_cents) }}
                        </div>
                    </div>
                </div>

                <!-- Per-image models (DALL-E, legacy endpoints) -->
                <div v-else class="grid grid-cols-2 gap-6">
                    <div class="text-center p-4 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Images Generated</div>
                        <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                            {{ formatNumber(request.image_count || 0) }}
                        </div>
                    </div>
                    <div class="text-center p-4 rounded-lg bg-primary-50 dark:bg-primary-900/20">
                        <div class="text-sm font-medium text-primary-600 dark:text-primary-400 mb-1">Cost Per Image</div>
                        <div class="text-2xl font-bold text-primary-700 dark:text-primary-300 tabular-nums">
                            {{ formatCurrency(costPerImage) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Media Storage Disabled Notice -->
        <div v-if="!imageUrls.length && request.media_storage_enabled === false" class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4">
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-amber-500 dark:text-amber-400 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                </svg>
                <div>
                    <p class="text-sm font-medium text-amber-800 dark:text-amber-300">Media storage is disabled</p>
                    <p class="mt-1 text-sm text-amber-700 dark:text-amber-400/80">
                        Generated images are not being saved. Enable media storage to persist and view images in the dashboard.
                    </p>
                    <p class="mt-2 text-xs font-mono text-amber-600 dark:text-amber-500">
                        SPECTRA_MEDIA_ENABLED=true
                    </p>
                </div>
            </div>
        </div>

        <div v-if="imageUrls.length" class="card">
            <div
                class="card-header cursor-pointer select-none"
                @click="imagesExpanded = !imagesExpanded"
            >
                <div class="flex items-center justify-between w-full">
                    <div class="flex items-center gap-2">
                        <svg
                            class="w-4 h-4 text-gray-400 transition-transform duration-200"
                            :class="{ 'rotate-90': imagesExpanded }"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                        <h3 class="text-sm font-medium">Generated Images</h3>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ formatNumber(imageUrls.length) }}
                        </span>
                    </div>
                </div>
            </div>
            <div v-if="imagesExpanded" class="card-body">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <a
                        v-for="(url, idx) in imageUrls"
                        :key="idx"
                        :href="url"
                        target="_blank"
                        class="block rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:ring-2 hover:ring-primary-500 transition-shadow"
                    >
                        <img :src="url" :alt="'Generated image ' + (idx + 1)" class="w-full h-auto" loading="lazy" />
                    </a>
                </div>
            </div>
        </div>
    </div>
</template>
