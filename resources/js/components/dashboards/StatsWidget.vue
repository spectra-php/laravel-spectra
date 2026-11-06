<script setup lang="ts">
import { computed } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import HeadlineCards from '@/components/widgets/HeadlineCards.vue';
import MediaUsageCard from '@/components/widgets/MediaUsageCard.vue';
import type { DashboardStats } from '@/types/api';

// The Overview's headline cards and media strip, for one trackable.
const props = defineProps<{ stats: DashboardStats; periodLabel: string }>();

const { formatNumber, formatCompact, formatDurationSeconds } = useHelpers();

const typeCost = (type: string) => props.stats.cost_by_model_type?.[type] ?? null;

const mediaCards = computed(() => [
    { key: 'images', show: props.stats.total_images > 0, label: 'Images', value: formatNumber(props.stats.total_images), unit: props.stats.total_images === 1 ? 'image' : 'images', cost: typeCost('image') },
    { key: 'videos', show: props.stats.total_videos > 0, label: 'Videos', value: formatNumber(props.stats.total_videos), unit: props.stats.total_videos === 1 ? 'video' : 'videos', cost: typeCost('video') },
    { key: 'tts', show: props.stats.total_tts_characters > 0, label: 'Text-to-speech', value: formatCompact(props.stats.total_tts_characters), unit: 'characters', cost: typeCost('tts') },
    { key: 'stt', show: props.stats.stt_duration_seconds > 0, label: 'Speech-to-text', value: formatDurationSeconds(props.stats.stt_duration_seconds), unit: 'audio', cost: typeCost('stt') },
].filter((card) => card.show));
</script>

<template>
    <div class="space-y-4">
        <HeadlineCards
            :requests="stats.total_requests"
            :request-trend="stats.request_trend"
            :cost="stats.total_cost"
            :tokens="stats.total_tokens"
            :avg-latency="stats.avg_latency"
            :latency-by-model-type="stats.latency_by_model_type"
            :period-label="periodLabel"
        />
        <MediaUsageCard v-if="mediaCards.length" :cards="mediaCards" />
    </div>
</template>
