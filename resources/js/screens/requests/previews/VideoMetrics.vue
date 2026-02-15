<script setup>
import { ref } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import ExpiryCountdown from '@/components/ExpiryCountdown.vue';

const props = defineProps({
    request: { type: Object, required: true },
    expanded: { type: Boolean, default: true },
});

const emit = defineEmits(['toggle']);

const { spectra, formatNumber, formatCurrency } = useHelpers();

const hasExpired = ref(
    props.request.expires_at && new Date(props.request.expires_at) < new Date()
);

const onExpired = () => {
    hasExpired.value = true;
};
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
                    <h3 class="text-sm font-medium">Video Generation</h3>
                </div>
            </div>
        </div>
        <div v-if="expanded" class="card-body">
            <div class="grid grid-cols-3 gap-6">
                <div class="text-center p-4 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Videos Generated</div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                        {{ formatNumber(request.video_count || 0) }}
                    </div>
                </div>
                <div class="text-center p-4 rounded-lg bg-gray-50 dark:bg-gray-800/50">
                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Duration</div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-white tabular-nums">
                        {{ (request.duration_seconds || 0).toFixed(1) }}s
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
            <div v-if="!request.response?.id && request.media_storage_enabled === false" class="mt-6 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-amber-500 dark:text-amber-400 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                    </svg>
                    <div>
                        <p class="text-sm font-medium text-amber-800 dark:text-amber-300">Media storage is disabled</p>
                        <p class="mt-1 text-sm text-amber-700 dark:text-amber-400/80">
                            Generated videos are not being saved. Enable media storage to persist and play videos in the dashboard.
                        </p>
                        <p class="mt-2 text-xs font-mono text-amber-600 dark:text-amber-500">
                            SPECTRA_MEDIA_ENABLED=true
                        </p>
                    </div>
                </div>
            </div>

            <div v-if="request.response?.id" class="mt-6">
                <div v-if="hasExpired" class="flex items-center gap-3">
                    <span
                        class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-400 dark:text-gray-500 bg-gray-100 dark:bg-gray-800 rounded-lg cursor-not-allowed"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Expired
                    </span>
                </div>
                <template v-else>
                    <div class="rounded-lg overflow-hidden bg-black mb-4">
                        <video
                            controls
                            class="w-full max-h-[480px]"
                            :src="'/' + spectra.path + '/api/requests/' + request.id + '/video'"
                        >
                            Your browser does not support the video tag.
                        </video>
                    </div>
                    <div class="flex items-center gap-3">
                        <a
                            :href="'/' + spectra.path + '/api/requests/' + request.id + '/video'"
                            class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 rounded-lg transition-colors"
                            download
                        >
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                            </svg>
                            Download Video
                        </a>
                        <ExpiryCountdown
                            v-if="request.expires_at"
                            :expires-at="request.expires_at"
                            :formatted-expires-at="request.formatted_expires_at"
                            class="text-sm text-gray-500 dark:text-gray-400"
                            @expired="onExpired"
                        />
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
