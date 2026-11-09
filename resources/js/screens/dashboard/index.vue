<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { usePeriodQuery } from '@/composables/usePeriodQuery';
import { useProviders } from '@/composables/useProviders';
import { useLoading } from '@/composables/useLoading';
import { useCardRefresh } from '@/composables/useCardRefresh';
import PageRangeHeader from '@/components/PageRangeHeader.vue';
import BreakdownCard from '@/components/widgets/BreakdownCard.vue';
import HeadlineCards from '@/components/widgets/HeadlineCards.vue';
import MediaUsageCard from '@/components/widgets/MediaUsageCard.vue';
import PerformanceCard from '@/components/widgets/PerformanceCard.vue';
import RecentRequestsCard from '@/components/widgets/RecentRequestsCard.vue';
import ScoreSummaryCard from '@/components/widgets/ScoreSummaryCard.vue';
import TopModelsCard from '@/components/widgets/TopModelsCard.vue';
import TrendCard from '@/components/widgets/TrendCard.vue';
import AlertsCard from '@/components/AlertsCard.vue';
import RefreshButton from '@/components/RefreshButton.vue';
import type { AlertsResponse, DashboardConfigResponse, ModelPerformance, PerformanceResponse, ScoreSummary, ScoresResponse, StatsResponse } from '@/types/api';

const router = useRouter();
const { formatNumber, formatCompact, formatDurationSeconds, apiRequest } = useHelpers();
const { period, startDate, endDate, periodLabel, applyQueryState, buildQueryParams, syncQueryToRouter } = usePeriodQuery();
const { loadProviders, providerDisplayName } = useProviders();
const loading = useLoading();
const { isRefreshing, refresh } = useCardRefresh();

loading.value = true;
const layout = ref('full');
const stats = ref<StatsResponse>({
    total_requests: 0,
    total_cost_in_cents: 0,
    unpriced: { request_count: 0, models: [] },
    total_tokens: 0,
    total_images: 0,
    total_videos: 0,
    total_duration_seconds: 0,
    total_input_characters: 0,
    tts_characters: 0,
    tts_duration_seconds: 0,
    stt_duration_seconds: 0,
    avg_latency: 0,
    cost_by_model_type: {
        text: 0,
        embedding: 0,
        image: 0,
        video: 0,
        tts: 0,
        stt: 0,
        unknown: 0,
    },
    top_models: [],
    recent_requests: [],
    requests_by_date: [],
    requests_by_provider: [],
    stats_by_model_type: [],
    latency_by_model_type: [],
    layout: 'full',
});

const loadConfig = async () => {
    try {
        const data = await apiRequest<DashboardConfigResponse>('get', '/config');
        layout.value = data.layout || 'full';
    } catch (error) {
        // fallback to full
    }
};

// The Scores card; empty (and hidden) until the app records scores.
const scoreSummary = ref<ScoreSummary[]>([]);

const loadScores = async () => {
    try {
        scoreSummary.value = (await apiRequest<ScoresResponse>('get', '/scores?' + buildQueryParams().toString())).scores || [];
    } catch (error) {
        scoreSummary.value = [];
    }
};

// The Performance and Alerts cards.
const performance = ref<ModelPerformance[]>([]);
const alerts = ref<AlertsResponse>({ alerts: [], error: null });

const loadPerformance = async () => {
    try {
        performance.value = (await apiRequest<PerformanceResponse>('get', '/performance?' + buildQueryParams().toString())).models || [];
    } catch (error) {
        performance.value = [];
    }
};

const loadAlerts = async () => {
    try {
        alerts.value = await apiRequest<AlertsResponse>('get', '/alerts');
    } catch (error) {
        alerts.value = { alerts: [], error: null };
    }
};

const fetchStats = async () => {
    try {
        const data = await apiRequest<StatsResponse>('get', '/stats?' + buildQueryParams().toString());
        stats.value = data;
        layout.value = data.layout || layout.value;
    } catch (error) {
        console.error('Failed to load stats:', error);
    }
};

const loadStats = async () => {
    loading.value = true;
    loadScores();
    loadPerformance();
    loadAlerts();
    await fetchStats();
    loading.value = false;
};

// A card's refresh button reloads its data in place, without the skeletons. The
// trend, breakdown, top models and recent requests cards all read `/stats`.
const refreshStats = (card: string) => refresh(card, fetchStats);

const onRangeChange = async () => {
    await syncQueryToRouter();
    await loadStats();
};

const goToRequest = (id: string) => {
    router.push({ name: 'request-preview', params: { id } });
};

const showTokens = computed(() => ['full', 'text', 'embedding'].includes(layout.value));
const showImages = computed(() => ['full', 'image'].includes(layout.value));
const showVideos = computed(() => ['full', 'video'].includes(layout.value));
const showTts = computed(() => ['full', 'audio'].includes(layout.value));
const showStt = computed(() => ['full', 'audio'].includes(layout.value));

// Daily request counts for the stat-card sparkline.
const requestTrend = computed(() => (stats.value.requests_by_date || []).map((d) => d.count));

// The shared cards take provider names from the page, because a viewer can't load them.
const breakdownProviders = computed(() => (stats.value.requests_by_provider || []).map((row) => ({
    provider: row.provider,
    label: providerDisplayName(row.provider) ?? null,
    count: row.count,
})));

const topModels = computed(() => (stats.value.top_models || []).map((model) => ({
    provider: model.provider,
    providerName: providerDisplayName(model.provider) ?? null,
    model: model.model,
    count: model.count,
    cost: model.cost,
})));

// Media stat cards are shown only when the period has that kind of traffic.
const mediaCards = computed(() => [
    { key: 'images', show: showImages.value && stats.value.total_images > 0, label: 'Images', value: formatNumber(stats.value.total_images), unit: stats.value.total_images === 1 ? 'image' : 'images', cost: stats.value.cost_by_model_type?.image ?? 0 },
    { key: 'videos', show: showVideos.value && stats.value.total_videos > 0, label: 'Videos', value: formatNumber(stats.value.total_videos), unit: stats.value.total_videos === 1 ? 'video' : 'videos', cost: stats.value.cost_by_model_type?.video ?? 0 },
    { key: 'tts', show: showTts.value && stats.value.tts_characters > 0, label: 'Text-to-speech', value: formatCompact(stats.value.tts_characters), unit: 'characters', cost: stats.value.cost_by_model_type?.tts ?? 0 },
    { key: 'stt', show: showStt.value && stats.value.stt_duration_seconds > 0, label: 'Speech-to-text', value: formatDurationSeconds(stats.value.stt_duration_seconds), unit: 'audio', cost: stats.value.cost_by_model_type?.stt ?? 0 },
].filter((card) => card.show));

onMounted(async () => {
    applyQueryState();
    await syncQueryToRouter(null, true);
    await Promise.all([
        loadConfig(),
        loadProviders(),
    ]);
    await loadStats();
});
</script>

<template>
    <div>
        <PageRangeHeader
            title="Overview"
            :subtitle="`AI traffic, spend and latency · ${periodLabel}`"
            v-model:period="period"
            v-model:start-date="startDate"
            v-model:end-date="endDate"
            :loading="loading"
            @change="onRangeChange"
        />

        <!-- Headline numbers -->
        <HeadlineCards
            class="mb-4"
            :requests="stats.total_requests"
            :request-trend="requestTrend"
            :cost="stats.total_cost_in_cents"
            :tokens="stats.total_tokens"
            :avg-latency="stats.avg_latency"
            :latency-by-model-type="stats.latency_by_model_type"
            :period-label="periodLabel"
            :show-tokens="showTokens"
            :loading="loading"
        >
            <template #spend-footer>
                <!-- Calls recorded at $0 because their model has no price: the total is short by them. -->
                <div
                    v-if="stats.unpriced?.request_count"
                    class="mt-1.5 text-xs text-amber-700 dark:text-amber-300"
                    v-tooltip="'No price for ' + stats.unpriced.models.join(', ') + '. Add the model to your pricing file.'"
                >
                    {{ formatNumber(stats.unpriced.request_count) }} {{ stats.unpriced.request_count === 1 ? 'call' : 'calls' }} without a price ({{ stats.unpriced.models.slice(0, 2).join(', ') }}{{ stats.unpriced.models.length > 2 ? ', …' : '' }})
                </div>
            </template>
        </HeadlineCards>

        <AlertsCard v-if="alerts.alerts?.length || alerts.error" class="mb-4" :class="{ 'is-refreshing': isRefreshing('alerts') }" :alerts="alerts.alerts" :error="alerts.error">
            <template #action>
                <RefreshButton :refreshing="isRefreshing('alerts')" @refresh="refresh('alerts', loadAlerts)" />
            </template>
        </AlertsCard>

        <!-- Media usage (only kinds that appear in this period) -->
        <MediaUsageCard v-if="!loading && mediaCards.length" class="mb-4" :cards="mediaCards" />

        <!-- Charts -->
        <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <TrendCard
                class="lg:col-span-2"
                :class="{ 'is-refreshing': isRefreshing('trend') }"
                title="Requests over time"
                description="Daily volume"
                kind="requests"
                :labels="(stats.requests_by_date || []).map((row) => row.date)"
                :values="requestTrend"
                :loading="loading"
            >
                <template #action>
                    <RefreshButton v-if="!loading" :refreshing="isRefreshing('trend')" @refresh="refreshStats('trend')" />
                </template>
            </TrendCard>
            <BreakdownCard
                :class="{ 'is-refreshing': isRefreshing('breakdown') }"
                :providers="breakdownProviders"
                :model-types="stats.stats_by_model_type || []"
                :show-types="layout === 'full'"
                :loading="loading"
            >
                <template #action>
                    <RefreshButton v-if="!loading" :refreshing="isRefreshing('breakdown')" @refresh="refreshStats('breakdown')" />
                </template>
            </BreakdownCard>
        </div>

        <!-- Tables -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <TopModelsCard :class="{ 'is-refreshing': isRefreshing('top-models') }" :models="topModels" :loading="loading">
                <template #action>
                    <div class="card-actions">
                        <router-link :to="{ name: 'costs' }" class="text-xs font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400">View costs</router-link>
                        <RefreshButton v-if="!loading" :refreshing="isRefreshing('top-models')" @refresh="refreshStats('top-models')" />
                    </div>
                </template>
            </TopModelsCard>
            <RecentRequestsCard :class="{ 'is-refreshing': isRefreshing('recent') }" :requests="stats.recent_requests || []" clickable :loading="loading" @select="goToRequest">
                <template #action>
                    <div class="card-actions">
                        <router-link :to="{ name: 'requests' }" class="text-xs font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400">View all</router-link>
                        <RefreshButton v-if="!loading" :refreshing="isRefreshing('recent')" @refresh="refreshStats('recent')" />
                    </div>
                </template>
            </RecentRequestsCard>
        </div>

        <PerformanceCard v-if="performance.length" class="mt-4" :class="{ 'is-refreshing': isRefreshing('performance') }" :models="performance">
            <template #action>
                <RefreshButton :refreshing="isRefreshing('performance')" @refresh="refresh('performance', loadPerformance)" />
            </template>
        </PerformanceCard>

        <ScoreSummaryCard v-if="scoreSummary.length" class="mt-4" :class="{ 'is-refreshing': isRefreshing('scores') }" :scores="scoreSummary">
            <template #action>
                <RefreshButton :refreshing="isRefreshing('scores')" @refresh="refresh('scores', loadScores)" />
            </template>
        </ScoreSummaryCard>
    </div>
</template>
