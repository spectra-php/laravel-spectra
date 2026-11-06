<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { useLoading } from '@/composables/useLoading';
import Breadcrumb from '@/components/Breadcrumb.vue';
import Skeleton from '@/components/Skeleton.vue';
import type { DashboardSectionName, DashboardSummary, DashboardsResponse, DashboardWidgetData, DashboardWidgetSize } from '@/types/api';

// Design a shared dashboard: who it's for and which widgets it shows, in order.
// Opens empty, from another dashboard (`from`), or for a trackable (`type`, `id`).
type WidgetType = DashboardWidgetData['type'];

const catalog: { type: WidgetType; label: string; description: string; costs?: boolean }[] = [
    { type: 'stats', label: 'Headline numbers', description: 'Requests, tokens and average latency, and spend when costs are shown.' },
    { type: 'requests_trend', label: 'Requests over time', description: 'Requests per day.' },
    { type: 'cost_trend', label: 'Cost over time', description: 'Spend per day.', costs: true },
    { type: 'usage_by_model', label: 'Top models', description: 'The five most used models. This shows the viewer the models you use.' },
    { type: 'usage_by_provider', label: 'Breakdown', description: 'The share of requests by provider and by type. This shows the viewer the providers you use.' },
    { type: 'recent_requests', label: 'Recent requests', description: 'The latest 10 calls: model, usage and status. Never the prompt or response.' },
    { type: 'budget', label: 'Budget', description: 'What is left of their budget limits.' },
    { type: 'performance', label: 'Performance', description: 'Error rate and latency per model. This shows the viewer the models you use.' },
    { type: 'scores', label: 'Scores', description: 'Feedback and evaluation results on their calls, by name. Comments are never shown. Review the names first: they can be internal.' },
];

const route = useRoute();
const router = useRouter();
const { apiRequest } = useHelpers();
const loading = useLoading();

loading.value = true;
const screen = ref<DashboardsResponse | null>(null);
const saving = ref(false);

const id = computed(() => (route.params.id as string | undefined) ?? null);

const name = ref('');
const trackableType = ref('');
const audience = ref<'one' | 'every'>('one');
const trackableId = ref('');
const widgets = ref<{ type: WidgetType; size: DashboardWidgetSize }[]>([
    { type: 'stats', size: 'full' },
    { type: 'requests_trend', size: 'full' },
    { type: 'recent_requests', size: 'full' },
]);
const period = ref('month');
const showCosts = ref(false);
const sections = ref<DashboardSectionName[]>([]);
const showMessages = ref(false);
const active = ref(true);

const sectionOptions: { value: DashboardSectionName; label: string; description: string; costs?: boolean }[] = [
    { value: 'requests', label: 'Requests', description: 'Their calls: model, usage, status and time.' },
    { value: 'traces', label: 'Traces', description: 'Their traces and timelines. Tool arguments and results are never shown.' },
    { value: 'conversations', label: 'Conversations', description: 'Their conversations, turn by turn.' },
    { value: 'errors', label: 'Errors', description: 'Their failed calls, grouped by cause.' },
    { value: 'costs', label: 'Costs', description: 'Their spend over time, by provider and by model.', costs: true },
];

const details = (type: WidgetType) => catalog.find((widget) => widget.type === type);
const available = computed(() => catalog.filter((widget) => !widgets.value.some((chosen) => chosen.type === widget.type)));
const hidesCostWidget = (type: WidgetType) => Boolean(details(type)?.costs) && !showCosts.value;
const canSave = computed(() => Boolean(name.value.trim() && trackableType.value.trim() && widgets.value.length && (audience.value === 'every' || trackableId.value.trim())));

const fill = (dashboard: DashboardSummary) => {
    name.value = dashboard.name;
    trackableType.value = dashboard.trackable_type;
    audience.value = dashboard.trackable_id === null ? 'every' : 'one';
    trackableId.value = dashboard.trackable_id ?? '';
    widgets.value = dashboard.widgets.map((widget) => ({ ...widget }));
    period.value = dashboard.default_period;
    showCosts.value = dashboard.show_costs;
    sections.value = [...dashboard.sections];
    showMessages.value = dashboard.show_messages;
    active.value = dashboard.is_active;
};

const move = (index: number, by: number) => {
    const [widget] = widgets.value.splice(index, 1);
    widgets.value.splice(index + by, 0, widget);
};

const save = async () => {
    saving.value = true;

    const body = {
        name: name.value.trim(),
        trackable_type: trackableType.value.trim(),
        trackable_id: audience.value === 'every' ? null : trackableId.value.trim(),
        widgets: widgets.value,
        default_period: period.value,
        show_costs: showCosts.value,
        sections: sections.value,
        show_messages: showMessages.value,
        is_active: active.value,
    };

    try {
        await (id.value
            ? apiRequest('put', `/dashboards/${id.value}`, body)
            : apiRequest('post', '/dashboards', body));
        await router.push({ name: 'dashboards' });
    } catch (error) {
        console.error('Failed to save the dashboard:', error);
    } finally {
        saving.value = false;
    }
};

onMounted(async () => {
    try {
        screen.value = await apiRequest<DashboardsResponse>('get', '/dashboards');
        const source = screen.value.dashboards.find((dashboard) => dashboard.id === (id.value ?? route.query.from));

        if (source) {
            fill(source);

            if (!id.value) name.value = `${source.name} copy`;
        } else if (route.query.type) {
            trackableType.value = route.query.type as string;
            trackableId.value = (route.query.id as string | undefined) ?? '';
        }
    } catch (error) {
        console.error('Failed to load the dashboard:', error);
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div>
        <Breadcrumb :items="[{ label: 'Dashboards', to: { name: 'dashboards' } }, { label: id ? 'Edit' : 'New' }]" />

        <h1 class="mb-6 text-xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ id ? 'Edit dashboard' : 'New dashboard' }}</h1>

        <div v-if="loading" class="card"><Skeleton variant="table-row" :lines="6" /></div>

        <div v-else-if="screen && (!screen.enabled || !screen.allowed || !screen.migrated)" class="card">
            <div class="empty-state">
                <p class="empty-state-title">{{ !screen.enabled ? 'Dashboards are off' : !screen.allowed ? "You can't manage dashboards" : 'Run the dashboards migration' }}</p>
            </div>
        </div>

        <form v-else class="grid grid-cols-1 gap-4 lg:grid-cols-3" @submit.prevent="save">
            <div class="space-y-4 lg:col-span-2">
                <div class="card">
                    <div class="card-header"><h3>Widgets</h3></div>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                        <li v-for="(widget, index) in widgets" :key="widget.type" class="flex flex-wrap items-center gap-3 px-5 py-3">
                            <div class="min-w-0 flex-1 basis-56">
                                <div class="text-sm font-medium text-gray-900 dark:text-white">{{ details(widget.type)?.label }}</div>
                                <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    {{ hidesCostWidget(widget.type) ? 'Hidden from viewers until you show costs.' : details(widget.type)?.description }}
                                </div>
                            </div>
                            <select v-model="widget.size" class="form-select form-select-sm w-auto" :aria-label="`Width of ${details(widget.type)?.label}`">
                                <option value="full">Full width</option>
                                <option value="half">Half width</option>
                            </select>
                            <div class="flex items-center gap-1">
                                <button type="button" class="btn btn-ghost btn-sm" :disabled="index === 0" :aria-label="`Move ${details(widget.type)?.label} up`" @click="move(index, -1)">↑</button>
                                <button type="button" class="btn btn-ghost btn-sm" :disabled="index === widgets.length - 1" :aria-label="`Move ${details(widget.type)?.label} down`" @click="move(index, 1)">↓</button>
                                <button type="button" class="btn btn-ghost btn-sm" @click="widgets.splice(index, 1)">Remove</button>
                            </div>
                        </li>
                        <li v-if="!widgets.length" class="px-5 py-6 text-center text-sm text-gray-500 dark:text-gray-400">Add at least one widget.</li>
                    </ul>
                    <div v-if="available.length" class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                        <div class="stat-label mb-2">Add a widget</div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="widget in available"
                                :key="widget.type"
                                type="button"
                                class="btn btn-secondary btn-sm"
                                v-tooltip="widget.description"
                                @click="widgets.push({ type: widget.type, size: 'full' })"
                            >{{ widget.label }}</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <div class="card">
                    <div class="card-header"><h3>Details</h3></div>
                    <div class="card-body space-y-4">
                        <div>
                            <label for="shared-name" class="stat-label mb-1.5 block">Name</label>
                            <input id="shared-name" v-model="name" type="text" maxlength="255" required class="form-input" />
                        </div>
                        <div>
                            <label for="shared-type" class="stat-label mb-1.5 block">Trackable type</label>
                            <input id="shared-type" v-model="trackableType" type="text" list="shared-types" required placeholder="App\Models\User" class="form-input font-mono text-xs" />
                            <datalist id="shared-types">
                                <option v-for="type in screen?.trackable_types ?? []" :key="type" :value="type"></option>
                            </datalist>
                        </div>
                        <fieldset>
                            <legend class="stat-label mb-1.5">Who can see it</legend>
                            <label class="flex items-center gap-2 py-1 text-sm">
                                <input v-model="audience" type="radio" value="one" /> One trackable
                            </label>
                            <label class="flex items-center gap-2 py-1 text-sm">
                                <input v-model="audience" type="radio" value="every" /> Every one of this type, each seeing their own data
                            </label>
                        </fieldset>
                        <div v-if="audience === 'one'">
                            <label for="shared-id" class="stat-label mb-1.5 block">Trackable ID</label>
                            <input id="shared-id" v-model="trackableId" type="text" required class="form-input" />
                        </div>
                        <div>
                            <label for="shared-period" class="stat-label mb-1.5 block">Opens on</label>
                            <select id="shared-period" v-model="period" class="form-select">
                                <option value="today">Today</option>
                                <option value="week">This week</option>
                                <option value="month">This month</option>
                                <option value="year">This year</option>
                            </select>
                        </div>
                        <label class="flex items-start gap-2 text-sm">
                            <input v-model="showCosts" type="checkbox" class="mt-0.5" />
                            <span>Show costs<span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">These are what you pay the provider, so a customer could work out your margin.</span></span>
                        </label>
                        <fieldset>
                            <legend class="stat-label mb-1.5">Pages in its header</legend>
                            <label v-for="option in sectionOptions" :key="option.value" class="flex items-start gap-2 py-1 text-sm" :class="option.costs && !showCosts ? 'opacity-50' : ''">
                                <input v-model="sections" type="checkbox" class="mt-0.5" :value="option.value" :disabled="option.costs && !showCosts" />
                                <span>{{ option.label }}<span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ option.costs && !showCosts ? 'Needs Show costs.' : option.description }}</span></span>
                            </label>
                        </fieldset>
                        <label class="flex items-start gap-2 text-sm">
                            <input v-model="showMessages" type="checkbox" class="mt-0.5" />
                            <span>Show message text<span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">Their messages, the replies and error messages. Never system prompts, tools or raw request bodies.</span></span>
                        </label>
                        <label class="flex items-start gap-2 text-sm">
                            <input v-model="active" type="checkbox" class="mt-0.5" />
                            <span>Active<span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">While paused, its link answers "not found".</span></span>
                        </label>
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <router-link :to="{ name: 'dashboards' }" class="btn btn-secondary btn-sm">Cancel</router-link>
                    <button type="submit" class="btn btn-primary btn-sm" :disabled="!canSave || saving">{{ saving ? 'Saving…' : 'Save' }}</button>
                </div>
            </div>
        </form>
    </div>
</template>
