<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';

const filters = defineModel('filters', {
    type: Object,
    required: true,
});

const props = defineProps({
    providers: {
        type: Array,
        default: () => [],
    },
    modelTypes: {
        type: Array,
        default: () => [],
    },
    availableTags: {
        type: Array,
        default: () => [],
    },
    availableFinishReasons: {
        type: Array,
        default: () => [],
    },
    availableReasoningEfforts: {
        type: Array,
        default: () => [],
    },
    searchPlaceholder: {
        type: String,
        default: 'Search requests...',
    },
    sortOptions: {
        type: Array,
        default: () => ([
            { value: 'created_at', label: 'Sort by Time' },
            { value: 'total_cost_in_cents', label: 'Sort by Cost' },
            { value: 'total_tokens', label: 'Sort by Usage' },
            { value: 'latency_ms', label: 'Sort by Latency' },
        ]),
    },
    activeFilterKeys: {
        type: Array,
        default: () => [
            'search',
            'provider',
            'model',
            'status',
            'tag',
            'model_type',
            'finish_reason',
            'has_tool_calls',
            'is_reasoning',
            'reasoning_effort',
            'trace_id',
        ],
    },
    clearTitle: {
        type: String,
        default: 'Clear all filters',
    },
    showModelType: {
        type: Boolean,
        default: true,
    },
    showProvider: {
        type: Boolean,
        default: true,
    },
    showSort: {
        type: Boolean,
        default: true,
    },
    showAdvancedFilters: {
        type: Boolean,
        default: true,
    },
    showTraceBadge: {
        type: Boolean,
        default: true,
    },
    showTrackableBadge: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['clear']);

const showMoreFilters = ref(false);
const moreFiltersEl = ref(null);

const extraFilterKeys = ['model', 'status', 'tag', 'finish_reason', 'has_tool_calls', 'is_reasoning', 'reasoning_effort'];

const hasClearableFilters = computed(() => {
    if (!filters.value) return false;
    return props.activeFilterKeys.some((key) => Boolean(filters.value[key]));
});

const activeExtraFilters = computed(() => {
    if (!props.showAdvancedFilters || !filters.value) return [];
    return extraFilterKeys.filter((key) => Boolean(filters.value[key]));
});

const extraFilterLabel = (key) => {
    const labels = {
        model: 'Model',
        status: 'Status',
        tag: 'Tag',
        finish_reason: 'Finish',
        has_tool_calls: 'Tools',
        is_reasoning: 'Reasoning',
        reasoning_effort: 'Effort',
    };

    const label = labels[key] || key;
    let value = filters.value[key];

    if (key === 'has_tool_calls' || key === 'is_reasoning') {
        value = value === '1' ? 'Yes' : 'No';
    }

    return `${label}: ${value}`;
};

const removeExtraFilter = (key) => {
    if (!filters.value) return;
    filters.value[key] = '';
};

const onClickOutside = (event) => {
    if (!showMoreFilters.value || !moreFiltersEl.value) return;
    if (moreFiltersEl.value.contains(event.target)) return;
    showMoreFilters.value = false;
};

onMounted(() => {
    document.addEventListener('click', onClickOutside, true);
});

onUnmounted(() => {
    document.removeEventListener('click', onClickOutside, true);
});
</script>

<template>
    <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
        <div class="md:col-span-2">
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input
                    v-model="filters.search"
                    type="text"
                    :placeholder="searchPlaceholder"
                    class="form-input pl-10"
                />
            </div>
        </div>

        <div v-if="showModelType">
            <select v-model="filters.model_type" class="form-select">
                <option value="">All Types</option>
                <option v-for="modelType in modelTypes" :key="modelType.value" :value="modelType.value">
                    {{ modelType.label }}
                </option>
            </select>
        </div>

        <div v-if="showProvider">
            <select v-model="filters.provider" class="form-select">
                <option value="">All Providers</option>
                <option v-for="provider in providers" :key="provider.internal_name" :value="provider.internal_name">
                    {{ provider.display_name }}
                </option>
            </select>
        </div>

        <slot name="inline-filters"></slot>

        <div v-if="showSort">
            <select v-model="filters.sort_by" class="form-select">
                <option v-for="option in sortOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option>
            </select>
        </div>

        <div class="flex gap-2">
            <div v-if="showAdvancedFilters" class="relative flex-1" ref="moreFiltersEl">
                <button
                    @click="showMoreFilters = !showMoreFilters"
                    class="btn btn-secondary w-full justify-between py-2.5"
                    :class="{ '!border-primary-500 !text-primary-600 dark:!text-primary-400': activeExtraFilters.length > 0 }"
                >
                    <span class="flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                        </svg>
                        Filters
                        <span
                            v-if="activeExtraFilters.length"
                            class="inline-flex items-center justify-center w-5 h-5 rounded-full text-[10px] font-bold bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300"
                        >{{ activeExtraFilters.length }}</span>
                    </span>
                    <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': showMoreFilters }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <Transition
                    enter-active-class="transition ease-out duration-100"
                    enter-from-class="transform opacity-0 scale-95"
                    enter-to-class="transform opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-75"
                    leave-from-class="transform opacity-100 scale-100"
                    leave-to-class="transform opacity-0 scale-95"
                >
                    <div
                        v-if="showMoreFilters"
                        class="absolute right-0 mt-2 w-72 bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 z-50 p-4 space-y-3"
                    >
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Model</label>
                            <input
                                v-model="filters.model"
                                type="text"
                                placeholder="e.g. gpt-4o"
                                class="form-input text-sm"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Status</label>
                            <select v-model="filters.status" class="form-select text-sm">
                                <option value="">All</option>
                                <option value="success">Success</option>
                                <option value="error">Error</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Tag</label>
                            <select v-model="filters.tag" class="form-select text-sm">
                                <option value="">All</option>
                                <option v-for="tag in availableTags" :key="tag" :value="tag">
                                    {{ tag }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Finish Reason</label>
                            <select v-model="filters.finish_reason" class="form-select text-sm">
                                <option value="">All</option>
                                <option v-for="reason in availableFinishReasons" :key="reason" :value="reason">
                                    {{ reason }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Tool Calls</label>
                            <select v-model="filters.has_tool_calls" class="form-select text-sm">
                                <option value="">All</option>
                                <option value="1">With Tool Calls</option>
                                <option value="0">Without Tool Calls</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Reasoning</label>
                            <select v-model="filters.is_reasoning" class="form-select text-sm">
                                <option value="">All</option>
                                <option value="1">Reasoning</option>
                                <option value="0">Not Reasoning</option>
                            </select>
                        </div>
                        <div v-if="availableReasoningEfforts.length">
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Reasoning Effort</label>
                            <select v-model="filters.reasoning_effort" class="form-select text-sm">
                                <option value="">All</option>
                                <option v-for="effort in availableReasoningEfforts" :key="effort" :value="effort">
                                    {{ effort }}
                                </option>
                            </select>
                        </div>
                    </div>
                </Transition>
            </div>

            <button
                v-if="hasClearableFilters"
                @click="emit('clear')"
                class="btn btn-ghost px-3"
                :title="clearTitle"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </div>

    <div v-if="activeExtraFilters.length || (showTraceBadge && filters.trace_id) || (showTrackableBadge && filters.trackable_type)" class="mt-3 flex flex-wrap items-center gap-2">
        <span
            v-for="key in activeExtraFilters"
            :key="key"
            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300"
        >
            {{ extraFilterLabel(key) }}
            <button @click="removeExtraFilter(key)" class="hover:text-gray-900 dark:hover:text-white">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </span>

        <span
            v-if="showTraceBadge && filters.trace_id"
            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300"
        >
            Trace: <span class="font-mono">{{ filters.trace_id }}</span>
            <button @click="filters.trace_id = ''" class="hover:text-primary-900 dark:hover:text-primary-100">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </span>

        <span
            v-if="showTrackableBadge && filters.trackable_type"
            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-violet-50 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300"
        >
            Trackable: <span class="font-mono">{{ filters.trackable_type.split('\\').pop() }}#{{ filters.trackable_id }}</span>
            <button @click="filters.trackable_type = ''; filters.trackable_id = ''" class="hover:text-violet-900 dark:hover:text-violet-100">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </span>
    </div>
</template>
