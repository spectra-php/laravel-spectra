<script setup>
import { computed } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import ProviderLogo from '@/components/ProviderLogo.vue';

const props = defineProps({
    // The trace's calls in the order they started, as the API sends them.
    requests: { type: Array, required: true },
    // Spans around the calls (Spectra::span() blocks, the request, job or command). With any, the trace is drawn as a tree.
    spans: { type: Array, default: () => [] },
    durationMs: { type: Number, default: 0 },
    // Without spans: start a new lane, headed by its origin, whenever the origin changes (request → job).
    showOrigins: { type: Boolean, default: false },
});

const { formatCurrency, formatDuration } = useHelpers();

// Bars share one time scale: the whole trace, at least 1ms so nothing divides by zero.
const span = computed(() => Math.max(1, props.durationMs || 0));

const kindLabels = { span: 'span', request: 'request', job: 'job', command: 'command' };

const bar = (offset, length) => ({
    left: Math.min(100, (offset / span.value) * 100),
    width: Math.max(0.8, Math.min(100 - (offset / span.value) * 100, (length / span.value) * 100)),
});

const callNumbers = computed(() => new Map(props.requests.map((call, index) => [call.id, index + 1])));

const callRow = (call, depth, startsLane) => ({
    ...call,
    type: 'call',
    key: 'call:' + call.id,
    depth,
    number: callNumbers.value.get(call.id),
    startsLane,
    startLabel: (call.offset_ms || 0) > 0 ? '+' + formatDuration(call.offset_ms) : 'Start',
    ...bar(call.offset_ms || 0, call.latency_ms || 0),
});

const spanRow = (node, depth) => ({
    ...node,
    type: 'span',
    key: 'span:' + node.span_id,
    depth,
    startLabel: (node.offset_ms || 0) > 0 ? '+' + formatDuration(node.offset_ms) : 'Start',
    ...bar(node.offset_ms || 0, node.duration_ms || 0),
});

// A flat list with origin lanes, or the tree: each node under its parent span, children by start time.
// A node whose parent wasn't saved (or lies outside the trace) is a root.
const rows = computed(() => {
    if (!props.spans.length) {
        return props.requests.map((call, index) => {
            const previous = props.requests[index - 1];
            return callRow(call, 0, props.showOrigins && (index === 0 || (previous.origin || null) !== (call.origin || null)));
        });
    }

    const nodes = [
        ...props.spans.map((node) => ({ node, isSpan: true, start: node.offset_ms || 0 })),
        ...props.requests.map((node) => ({ node, isSpan: false, start: node.offset_ms || 0 })),
    ];
    const ids = new Set(nodes.map(({ node }) => node.span_id).filter(Boolean));
    const children = new Map();
    const roots = [];

    for (const entry of nodes) {
        const parent = entry.node.parent_span_id;
        if (parent && ids.has(parent) && parent !== entry.node.span_id) {
            if (!children.has(parent)) children.set(parent, []);
            children.get(parent).push(entry);
        } else {
            roots.push(entry);
        }
    }

    const byStart = (a, b) => a.start - b.start || Number(b.isSpan) - Number(a.isSpan);
    const out = [];
    const seen = new Set();
    const walk = (entries, depth) => {
        for (const entry of [...entries].sort(byStart)) {
            if (seen.has(entry)) continue;
            seen.add(entry);
            out.push(entry.isSpan ? spanRow(entry.node, depth) : callRow(entry.node, depth, false));
            if (entry.node.span_id && children.has(entry.node.span_id)) walk(children.get(entry.node.span_id), depth + 1);
        }
    };
    walk(roots, 0);

    return out;
});

const INDENT_PX = 14;

const indent = (depth) => ({ paddingLeft: `${depth * INDENT_PX}px` });

// Every row shares one name column, widened by the deepest indent, so a nested
// call keeps the same room for its name as a top-level one and the columns line up.
const nameColumn = computed(() => {
    const deepest = rows.value.reduce((max, row) => Math.max(max, row.depth || 0), 0);

    return `calc(13rem + ${deepest * INDENT_PX}px)`;
});
</script>

<template>
    <ol class="divide-y divide-gray-100 dark:divide-gray-800/80" :style="{ '--name-column': nameColumn }">
        <template v-for="row in rows" :key="row.key">
            <li v-if="row.startsLane" class="bg-gray-50/70 px-5 py-1.5 dark:bg-white/[0.02]">
                <span class="font-mono text-[11px] text-gray-500 dark:text-gray-400" :title="row.origin || 'No origin recorded'">{{ row.origin || 'No origin recorded' }}</span>
            </li>

            <!-- A span: a Spectra::span() block, or the request, job or command -->
            <li v-if="row.type === 'span'">
                <div class="group relative grid grid-cols-[1.5rem_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2 bg-gray-50/40 px-5 py-2 sm:grid-cols-[1.5rem_minmax(0,var(--name-column))_minmax(0,1fr)_4.5rem_6rem] dark:bg-white/[0.015]">
                    <span class="col-start-1 row-start-1 text-gray-300 sm:col-start-auto sm:row-start-auto dark:text-gray-600" aria-hidden="true">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h10.5m-10.5 5.25h6" /></svg>
                    </span>

                    <span class="col-start-2 row-start-1 flex min-w-0 items-center gap-2 sm:col-start-auto sm:row-start-auto" :style="indent(row.depth)">
                        <span class="truncate font-mono text-[13px] font-medium text-gray-700 dark:text-gray-200" :title="row.name">{{ row.name }}</span>
                        <span class="badge badge-secondary shrink-0 !px-1.5 !py-0 text-[10px]">{{ kindLabels[row.kind] || row.kind }}</span>
                        <span v-if="row.is_error" class="badge badge-danger shrink-0 !px-1.5 !py-0 text-[11px]" :title="row.error_message || ''">Failed</span>
                    </span>

                    <span class="relative col-span-2 col-start-2 row-start-2 h-5 sm:col-span-1 sm:col-start-auto sm:row-start-auto">
                        <span class="absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-gray-100 dark:bg-gray-800" aria-hidden="true"></span>
                        <span
                            class="absolute top-1/2 h-1.5 -translate-y-1/2 rounded-[3px]"
                            :class="row.is_error ? 'bg-red-400/70 dark:bg-red-400/60' : 'bg-gray-300 dark:bg-gray-600'"
                            :style="{ left: row.left + '%', width: row.width + '%' }"
                        ></span>
                        <span
                            class="pointer-events-none absolute bottom-full z-10 mb-1 hidden whitespace-nowrap rounded-md bg-gray-900 px-2 py-1 text-[11px] font-medium text-white shadow-pop group-hover:block dark:bg-gray-100 dark:text-gray-900"
                            :style="{ left: `min(${row.left}%, calc(100% - 9rem))` }"
                        >{{ row.startLabel }} · {{ formatDuration(row.duration_ms) }}</span>
                    </span>

                    <span class="col-start-3 row-start-1 text-right text-xs tabular-nums text-gray-500 sm:col-start-auto sm:row-start-auto dark:text-gray-400">{{ formatDuration(row.duration_ms) }}</span>
                    <span class="hidden sm:block" aria-hidden="true"></span>
                </div>
            </li>

            <!-- An AI call -->
            <li v-else>
                <router-link
                    :to="{ name: 'request-preview', params: { id: row.id } }"
                    :aria-current="row.is_current ? 'page' : undefined"
                    class="group relative grid grid-cols-[1.5rem_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2 px-5 py-2.5 transition-colors sm:grid-cols-[1.5rem_minmax(0,var(--name-column))_minmax(0,1fr)_4.5rem_6rem]"
                    :class="row.is_current
                        ? 'bg-primary-50/70 dark:bg-primary-500/[0.08]'
                        : 'hover:bg-gray-50 dark:hover:bg-white/[0.03]'"
                >
                    <span
                        v-if="row.is_current"
                        class="absolute inset-y-0 left-0 w-0.5 bg-primary-500"
                        aria-hidden="true"
                    ></span>

                    <span
                        class="col-start-1 row-start-1 text-xs tabular-nums sm:col-start-auto sm:row-start-auto"
                        :class="row.is_current ? 'font-semibold text-primary-700 dark:text-primary-300' : 'text-gray-400 dark:text-gray-500'"
                    >{{ row.number }}</span>

                    <span class="col-start-2 row-start-1 flex min-w-0 items-center gap-2 sm:col-start-auto sm:row-start-auto" :style="indent(row.depth)">
                        <ProviderLogo
                            :provider="row.provider"
                            :title="row.provider_display_name || row.provider"
                            class="inline-flex h-4 w-4 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full"
                        />
                        <span
                            class="truncate text-sm"
                            :class="row.is_current ? 'font-semibold text-gray-900 dark:text-white' : 'font-medium text-gray-700 dark:text-gray-200'"
                            :title="row.model"
                        >{{ row.model }}</span>
                        <span v-if="row.is_error" class="badge badge-danger shrink-0 !px-1.5 !py-0 text-[11px]">
                            <svg class="mr-0.5 h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                            Failed
                        </span>
                    </span>

                    <!-- Waterfall: where this call sits in the trace -->
                    <span class="relative col-span-2 col-start-2 row-start-2 h-5 sm:col-span-1 sm:col-start-auto sm:row-start-auto">
                        <span class="absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-gray-100 dark:bg-gray-800" aria-hidden="true"></span>
                        <span
                            class="absolute top-1/2 h-2 -translate-y-1/2 rounded-[4px] ring-2 ring-white transition-colors dark:ring-gray-900"
                            :class="[
                                row.is_error
                                    ? 'bg-red-500 dark:bg-red-400'
                                    : row.is_current
                                        ? 'bg-primary-600 dark:bg-primary-400'
                                        : 'bg-primary-300 group-hover:bg-primary-400 dark:bg-primary-500/60 dark:group-hover:bg-primary-400/80',
                            ]"
                            :style="{ left: row.left + '%', width: row.width + '%' }"
                        ></span>
                        <!-- Hover details -->
                        <span
                            class="pointer-events-none absolute bottom-full z-10 mb-1 hidden whitespace-nowrap rounded-md bg-gray-900 px-2 py-1 text-[11px] font-medium text-white shadow-pop group-hover:block dark:bg-gray-100 dark:text-gray-900"
                            :style="{ left: `min(${row.left}%, calc(100% - 9rem))` }"
                        >
                            {{ row.startLabel }} · {{ formatDuration(row.latency_ms) }}<template v-if="row.is_streaming"> · streamed</template>
                        </span>
                    </span>

                    <span class="col-start-3 row-start-1 text-right text-xs tabular-nums text-gray-500 sm:col-start-auto sm:row-start-auto dark:text-gray-400">{{ formatDuration(row.latency_ms) }}</span>
                    <span class="hidden text-right text-xs font-medium tabular-nums text-gray-700 sm:block dark:text-gray-200">{{ formatCurrency(row.total_cost_in_cents) }}</span>
                </router-link>
            </li>
        </template>
    </ol>
</template>
