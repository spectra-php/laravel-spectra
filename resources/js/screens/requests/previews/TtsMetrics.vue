<script setup>
import { computed } from 'vue';
import { useHelpers } from '@/composables/useHelpers';

const props = defineProps({
    request: { type: Object, required: true },
    expanded: { type: Boolean, default: true },
});

const emit = defineEmits(['toggle']);

const { formatNumber, formatCurrency } = useHelpers();

const hasDuration = computed(() => props.request.duration_seconds != null && props.request.duration_seconds > 0);
const hasCharacters = computed(() => props.request.input_characters != null && props.request.input_characters > 0);
const hasTokens = computed(() => (props.request.prompt_tokens || 0) + (props.request.completion_tokens || 0) > 0);

const columnCount = computed(() => {
    let count = 1; // Cost is always shown
    if (hasDuration.value) count++;
    if (hasCharacters.value) count++;
    if (hasTokens.value) count++;
    return Math.max(count, 2); // At least 2 columns
});
</script>

<template>
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
                    <h3 class="text-sm font-medium">Text-to-Speech</h3>
                </div>
            </div>
        </div>
        <div v-if="expanded" class="card-body">
            <div class="grid gap-6" :style="{ gridTemplateColumns: `repeat(${columnCount}, minmax(0, 1fr))` }">
                <div v-if="hasDuration" class="text-center p-4 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Duration</div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                        {{ request.duration_seconds.toFixed(1) }}s
                    </div>
                </div>
                <div v-if="hasCharacters" class="text-center p-4 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Input Characters</div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                        {{ formatNumber(request.input_characters) }}
                    </div>
                </div>
                <div v-if="hasTokens" class="text-center p-4 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Total Tokens</div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                        {{ formatNumber(request.total_tokens) }}
                    </div>
                </div>
                <div class="text-center p-4 rounded-lg bg-primary-50 dark:bg-primary-900/20">
                    <div class="text-sm font-medium text-primary-600 dark:text-primary-400 mb-1">Cost</div>
                    <div class="text-2xl font-bold text-primary-700 dark:text-primary-300 tabular-nums">
                        {{ formatCurrency(request.total_cost_in_cents) }}
                    </div>
                </div>
            </div>

            <!-- Media Storage Disabled Notice -->
            <div v-if="!request.audio_url && request.media_storage_enabled === false" class="mt-6 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-amber-500 dark:text-amber-400 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                    </svg>
                    <div>
                        <p class="text-sm font-medium text-amber-800 dark:text-amber-300">Media storage is disabled</p>
                        <p class="mt-1 text-sm text-amber-700 dark:text-amber-400/80">
                            Generated audio is not being saved. Enable media storage to persist and play audio in the dashboard.
                        </p>
                        <p class="mt-2 text-xs font-mono text-amber-600 dark:text-amber-500">
                            SPECTRA_MEDIA_ENABLED=true
                        </p>
                    </div>
                </div>
            </div>

            <!-- Audio Player & Download -->
            <div v-if="request.audio_url" class="mt-6">
                <audio controls class="w-full" preload="metadata">
                    <source :src="request.audio_url" />
                    Your browser does not support the audio element.
                </audio>
                <div class="mt-3 flex items-center gap-3">
                    <a
                        :href="request.audio_download_url"
                        class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 rounded-lg transition-colors"
                        download
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        Download Audio
                    </a>
                </div>
            </div>
        </div>
    </div>
</template>
