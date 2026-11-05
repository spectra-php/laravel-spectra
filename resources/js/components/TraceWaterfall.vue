<script setup>
import { computed, ref } from 'vue';
import { useHelpers } from '@/composables/useHelpers';
import ProviderLogo from '@/components/ProviderLogo.vue';
import Money from '@/components/Money.vue';
import JsonViewer from '@/components/JsonViewer.vue';

const props = defineProps({
    // The trace's calls in the order they started, as the API sends them.
    requests: { type: Array, required: true },
    // Spans around the calls (Spectra::span() blocks, tools, the request, job or command). With any, the trace is drawn as a tree.
    spans: { type: Array, default: () => [] },
    durationMs: { type: Number, default: 0 },
    // Without spans: start a new lane, headed by its origin, whenever the origin changes (request → job).
    showOrigins: { type: Boolean, default: false },
    // A span left out of the tree, its children drawn in its place: the root span when the page already names it.
    hiddenSpanId: { type: String, default: null },
});

const { formatDuration } = useHelpers();

// Bars share one time scale: the whole trace, at least 1ms so nothing divides by zero.
const span = computed(() => Math.max(1, props.durationMs || 0));

const kindLabels = { span: 'span', request: 'request', job: 'job', command: 'command', tool: 'tool' };

// A tool row opens to show the arguments the model passed and the result it got back.
const ARGUMENTS = 'gen_ai.tool.call.arguments';
const RESULT = 'gen_ai.tool.call.result';
const expanded = ref(new Set());

const isTool = (row) => row.kind === 'tool';
const toggle = (row) => {
    const next = new Set(expanded.value);
    next.has(row.key) ? next.delete(row.key) : next.add(row.key);
    expanded.value = next;
};
const hasContent = (row) => row.attributes && (ARGUMENTS in row.attributes || RESULT in row.attributes);
// The JSON viewer takes objects, arrays and strings; anything else shows as text.
const viewable = (value) => (value !== null && typeof value === 'object') || typeof value === 'string' ? value : JSON.stringify(value);

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
            const hidden = entry.isSpan && props.hiddenSpanId && entry.node.span_id === props.hiddenSpanId;
            if (!hidden) out.push(entry.isSpan ? spanRow(entry.node, depth) : callRow(entry.node, depth, false));
            if (entry.node.span_id && children.has(entry.node.span_id)) walk(children.get(entry.node.span_id), hidden ? depth : depth + 1);
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
                <span class="font-mono text-[11px] text-gray-500 dark:text-gray-400" v-tooltip="row.origin || 'No origin recorded'">{{ row.origin || 'No origin recorded' }}</span>
            </li>

            <!-- A span: a Spectra::span() block, a tool, or the request, job or command -->
            <li v-if="row.type === 'span'">
                <component
                    :is="isTool(row) ? 'button' : 'div'"
                    :type="isTool(row) ? 'button' : undefined"
                    :aria-expanded="isTool(row) ? expanded.has(row.key) : undefined"
                    class="group relative grid w-full grid-cols-[1.5rem_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2 bg-gray-50/40 px-5 py-2 text-left sm:grid-cols-[1.5rem_minmax(0,var(--name-column))_minmax(0,1fr)_4.5rem_6rem] dark:bg-white/[0.015]"
                    :class="isTool(row) ? 'cursor-pointer hover:bg-gray-50 dark:hover:bg-white/[0.03]' : ''"
                    @click="isTool(row) && toggle(row)"
                >
                    <span class="col-start-1 row-start-1 sm:col-start-auto sm:row-start-auto" :class="isTool(row) ? 'text-amber-500 dark:text-amber-400' : 'text-gray-300 dark:text-gray-600'" aria-hidden="true">
                        <svg v-if="isTool(row)" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z" /></svg>
                        <svg v-else class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h10.5m-10.5 5.25h6" /></svg>
                    </span>

                    <span class="col-start-2 row-start-1 flex min-w-0 items-center gap-2 sm:col-start-auto sm:row-start-auto" :style="indent(row.depth)">
                        <span class="truncate font-mono text-[13px] font-medium text-gray-700 dark:text-gray-200" v-tooltip.truncated="row.name">{{ row.name }}</span>
                        <span class="badge shrink-0 !px-1.5 !py-0 text-[10px]" :class="isTool(row) ? 'badge-warning' : 'badge-secondary'">{{ kindLabels[row.kind] || row.kind }}</span>
                        <span v-if="row.is_error" class="badge badge-danger shrink-0 !px-1.5 !py-0 text-[11px]" v-tooltip="row.error_message || ''">Failed</span>
                    </span>

                    <span class="relative col-span-2 col-start-2 row-start-2 h-5 sm:col-span-1 sm:col-start-auto sm:row-start-auto">
                        <span class="absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-gray-100 dark:bg-gray-800" aria-hidden="true"></span>
                        <span
                            class="absolute top-1/2 h-1.5 -translate-y-1/2 rounded-[3px]"
                            :class="row.is_error ? 'bg-red-400/70 dark:bg-red-400/60' : isTool(row) ? 'bg-amber-300 dark:bg-amber-500/60' : 'bg-gray-300 dark:bg-gray-600'"
                            :style="{ left: row.left + '%', width: row.width + '%' }"
                        ></span>
                        <span
                            class="pointer-events-none absolute bottom-full z-10 mb-1 hidden whitespace-nowrap rounded-md bg-gray-900 px-2 py-1 text-[11px] font-medium text-white shadow-pop group-hover:block dark:bg-gray-100 dark:text-gray-900"
                            :style="{ left: `min(${row.left}%, calc(100% - 9rem))` }"
                        >{{ row.startLabel }} · {{ formatDuration(row.duration_ms) }}</span>
                    </span>

                    <span class="col-start-3 row-start-1 text-right text-xs tabular-nums text-gray-500 sm:col-start-auto sm:row-start-auto dark:text-gray-400">{{ formatDuration(row.duration_ms) }}</span>
                    <span class="hidden justify-end text-gray-400 sm:flex dark:text-gray-500" aria-hidden="true">
                        <svg v-if="isTool(row)" class="h-3.5 w-3.5 transition-transform" :class="expanded.has(row.key) ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                    </span>
                </component>

                <!-- A tool's arguments and result -->
                <div v-if="isTool(row) && expanded.has(row.key)" class="border-t border-gray-100 bg-gray-50/60 px-5 py-3 dark:border-gray-800/80 dark:bg-white/[0.02]">
                    <p v-if="row.is_error && row.error_message" class="mb-2 text-xs text-red-600 dark:text-red-400">{{ row.error_message }}</p>
                    <div v-if="hasContent(row)" class="grid gap-3 lg:grid-cols-2">
                        <JsonViewer v-if="'gen_ai.tool.call.arguments' in row.attributes" title="Arguments" :data="viewable(row.attributes['gen_ai.tool.call.arguments'])" max-height="16rem" />
                        <JsonViewer v-if="'gen_ai.tool.call.result' in row.attributes" title="Result" :data="viewable(row.attributes['gen_ai.tool.call.result'])" max-height="16rem" />
                    </div>
                    <p v-else class="text-xs text-gray-500 dark:text-gray-400">The arguments and result were not stored: <code>store_prompts</code> is off, or payload sampling skipped this trace.</p>
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
                            v-tooltip="row.provider_display_name || row.provider"
                            class="inline-flex h-4 w-4 shrink-0 items-center text-gray-500 dark:text-gray-400 [&>svg]:h-full [&>svg]:w-full"
                        />
                        <span
                            class="truncate text-sm"
                            :class="row.is_current ? 'font-semibold text-gray-900 dark:text-white' : 'font-medium text-gray-700 dark:text-gray-200'"
                            v-tooltip.truncated="row.model"
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
                    <span class="hidden text-right text-xs font-medium tabular-nums text-gray-700 sm:block dark:text-gray-200"><Money :cents="row.total_cost_in_cents" /></span>
                </router-link>
            </li>
        </template>
    </ol>
</template>
