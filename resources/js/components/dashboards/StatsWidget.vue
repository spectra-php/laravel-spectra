<script setup lang="ts">
import { computed } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import Money from '@/components/Money.vue';
import StatCard from '@/components/StatCard.vue';
import type { DashboardStats } from '@/types/api';

const props = defineProps<{ stats: DashboardStats }>();

const { formatNumber, formatCompact, formatDuration, formatDurationSeconds } = useHelpers();

// Media usage shows only for the kinds that appear in the period.
const mediaCards = computed(() => [
    { key: 'images', show: props.stats.total_images > 0, label: 'Images', value: formatNumber(props.stats.total_images) },
    { key: 'videos', show: props.stats.total_videos > 0, label: 'Videos', value: formatNumber(props.stats.total_videos) },
    { key: 'tts', show: props.stats.total_tts_characters > 0, label: 'Text-to-speech characters', value: formatCompact(props.stats.total_tts_characters) },
    { key: 'stt', show: props.stats.stt_duration_seconds > 0, label: 'Speech-to-text audio', value: formatDurationSeconds(props.stats.stt_duration_seconds) },
].filter((card) => card.show));
</script>

<template>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Requests">
            <div class="stat-value">{{ formatNumber(stats.total_requests) }}</div>
        </StatCard>
        <StatCard v-if="stats.total_cost !== null" label="Spend">
            <div class="stat-value"><Money :cents="stats.total_cost" /></div>
        </StatCard>
        <StatCard label="Tokens">
            <div class="stat-value" v-tooltip="formatNumber(stats.total_tokens) + ' tokens'">{{ formatCompact(stats.total_tokens) }}</div>
        </StatCard>
        <StatCard label="Average latency">
            <div class="stat-value">{{ formatDuration(stats.avg_latency) }}</div>
        </StatCard>
        <StatCard v-for="card in mediaCards" :key="card.key" :label="card.label">
            <div class="stat-value">{{ card.value }}</div>
        </StatCard>
    </div>
</template>
