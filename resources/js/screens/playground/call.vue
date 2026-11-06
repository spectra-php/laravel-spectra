<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useHelpers } from '@/composables/useHelpers';
import { useLoading } from '@/composables/useLoading';
import Breadcrumb from '@/components/Breadcrumb.vue';
import DocsLink from '@/components/DocsLink.vue';
import ProviderLogo from '@/components/ProviderLogo.vue';
import Skeleton from '@/components/Skeleton.vue';
import JsonEditor from './JsonEditor.vue';
import RunResult from './RunResult.vue';
import { canAddMessages, detectShape, newMessage, readMessages, readSettings, roleInShape, rolesFor, writeMessages, writeSettings } from './body';
import type { BodyShape, EditorMessage } from './body';
import type { JsonObject, PlaygroundCallResponse, PlaygroundRunResponse } from '@/types/api';
import type { ApiError } from '@/types/app';

// The Playground's editor, for one stored call or a new prompt: edit the messages,
// model and settings, or the whole body as JSON, and run it. A stored call's
// answers are compared with the original; a new prompt has none to compare with.
// A saved run opens at its own URL, so a refresh shows the same screen.
type Tab = 'messages' | 'json';

const route = useRoute();
const router = useRouter();
const { apiRequest } = useHelpers();
const loading = useLoading();

loading.value = true;
const opened = ref<PlaygroundCallResponse | null>(null);
const loadFailed = ref(false);

const tab = ref<Tab>('messages');
const model = ref('');
const body = ref<JsonObject>({});
// A plain Ref type: Vue's deep unwrapping can't resolve the recursive JSON type.
const messages = ref([]) as Ref<EditorMessage[]>;
const temperature = ref<string | number>('');
const maxTokens = ref<string | number>('');
const jsonText = ref('');

const running = ref(false);
const runError = ref('');
// The run at the top of the screen, and the others below the original.
const leadRun = ref<PlaygroundRunResponse | null>(null);
const runs = ref<PlaygroundRunResponse[]>([]);
let runCount = 0;

const isNewPrompt = computed(() => route.name === 'playground-new');
const provider = computed(() => opened.value?.provider ?? '');
const endpoint = computed(() => opened.value?.endpoint ?? null);
const shape = computed<BodyShape | null>(() => detectShape(provider.value, endpoint.value, body.value));
const isGoogle = computed(() => provider.value === 'google');

const jsonError = computed(() => {
    if (tab.value !== 'json') return '';

    try {
        const parsed: unknown = JSON.parse(jsonText.value);

        return typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed) ? '' : 'The body must be a JSON object.';
    } catch (error) {
        return (error as Error).message;
    }
});

// A new prompt has nothing to send until a message is written; the JSON tab is
// left to the provider to judge.
const hasPrompt = computed(() => !isNewPrompt.value || tab.value === 'json' || messages.value.some((message) => message.role !== 'system' && message.text.trim() !== ''));

const canRun = computed(() => !running.value && !opened.value?.unavailable_reason && model.value.trim() !== '' && !jsonError.value && hasPrompt.value);

// What the editor doesn't show in its form but still sends.
const keptInBody = computed(() => {
    const kept: string[] = [];
    const tools = body.value.tools ?? body.value.functions;

    if (Array.isArray(tools) && tools.length) kept.push(`${tools.length} ${tools.length === 1 ? 'tool' : 'tools'}`);
    if (body.value.response_format || body.value.text || body.value.output_config || (body.value.generationConfig as JsonObject | undefined)?.responseSchema) kept.push('the output format');

    return kept.join(' and ');
});

// The body as it stands in the open tab.
const currentBody = (): JsonObject => {
    if (tab.value === 'json') return JSON.parse(jsonText.value) as JsonObject;
    if (!shape.value) return body.value;

    const settings = { temperature: temperature.value, maxTokens: maxTokens.value };

    // A new prompt shows an empty system message to fill in, which is not sent as it is.
    const sent = isNewPrompt.value ? messages.value.filter((message) => message.locked || message.role !== 'system' || message.text.trim() !== '') : messages.value;

    return writeSettings(shape.value, provider.value, writeMessages(shape.value, body.value, sent), settings);
};

const loadEditor = (source: JsonObject) => {
    body.value = source;

    if (shape.value) {
        messages.value = readMessages(shape.value, source);

        if (isNewPrompt.value && !messages.value.some((message) => message.role === 'system')) {
            messages.value.unshift(newMessage('system'));
        }

        const settings = readSettings(shape.value, provider.value, source);
        temperature.value = settings.temperature;
        maxTokens.value = settings.maxTokens;
    }
};

const showTab = (next: Tab) => {
    if (next === tab.value || jsonError.value) return;

    const current = currentBody();

    if (next === 'json') {
        // The JSON shows what is sent; a Gemini endpoint names its model instead.
        if (!isGoogle.value) current.model = model.value;
        body.value = current;
        jsonText.value = JSON.stringify(current, null, 2);
    } else {
        if (typeof current.model === 'string' && !isGoogle.value) model.value = current.model;
        loadEditor(current);
    }

    tab.value = next;
};

const reset = () => {
    if (!opened.value) return;

    model.value = opened.value.model;
    loadEditor(opened.value.body ?? {});
    tab.value = shape.value ? 'messages' : 'json';
    jsonText.value = JSON.stringify(opened.value.body ?? {}, null, 2);
};

const fetchNewPrompt = (slug: string | null) => apiRequest<PlaygroundCallResponse>('get', `/playground/new${slug ? `?provider=${encodeURIComponent(slug)}` : ''}`);

const show = (response: PlaygroundCallResponse) => {
    opened.value = response;
    leadRun.value = response.run;
    runs.value = response.runs;
    runCount = response.run_count;
    reset();
};

const load = async () => {
    loadFailed.value = false;

    try {
        const slug = typeof route.query.provider === 'string' ? route.query.provider : null;

        show(await (isNewPrompt.value ? fetchNewPrompt(slug) : apiRequest<PlaygroundCallResponse>('get', `/playground/${route.params.id}`)));
    } catch {
        loadFailed.value = true;
    } finally {
        loading.value = false;
    }
};

// Going back or forward between runs keeps this screen, so it has to load again.
// Opening a run from run() has already put that run on the screen.
watch(() => route.params.id, (id) => {
    const isEditor = route.name === 'playground-call' || route.name === 'playground-new';

    if (isEditor && id !== (opened.value?.call?.id ?? undefined)) load();
});

// Another provider has another format, so a new prompt keeps its text, roles and
// settings, and anything added only in the JSON tab stays behind.
const changeProvider = async (event: Event) => {
    const slug = (event.target as HTMLSelectElement).value;

    if (tab.value === 'json') showTab('messages');

    const written = messages.value.filter((message) => !message.locked).map((message) => ({ role: message.role, text: message.text }));
    const settings = { temperature: temperature.value, maxTokens: maxTokens.value };
    const session = opened.value?.session ?? null;

    try {
        opened.value = { ...await fetchNewPrompt(slug), session };
    } catch {
        return;
    }

    reset();
    router.replace({ query: { ...route.query, provider: slug } });

    if (shape.value) {
        const nextShape = shape.value;

        messages.value = written.map(({ role, text }) => {
            const message = newMessage(roleInShape(role, nextShape));
            message.text = text;

            return message;
        });
    }

    temperature.value = settings.temperature;
    if (settings.maxTokens !== '') maxTokens.value = settings.maxTokens;
};

const addMessage = () => {
    const last = messages.value[messages.value.length - 1];
    const assistantRole = shape.value === 'google' ? 'model' : 'assistant';

    messages.value.push(newMessage(last?.role === 'user' ? assistantRole : 'user'));
};

const removeMessage = (key: number) => {
    messages.value = messages.value.filter((message) => message.key !== key);
};

// A role the editor doesn't offer (a function result) stays selectable on its message.
const rolesOf = (message: EditorMessage) => {
    const roles = shape.value ? rolesFor(shape.value) : [];

    return roles.includes(message.role) ? roles : [message.role, ...roles];
};

// Open a saved run at its own URL. A run stays on this screen when there's nothing
// to open it from: it wasn't saved, isn't saved yet, or its body wasn't stored.
const openRun = async (id: string | null) => {
    if (!id) return false;

    const response = await apiRequest<PlaygroundCallResponse>('get', `/playground/${id}`).catch(() => null);

    if (!response?.body) return false;

    const wasJson = tab.value === 'json';

    opened.value = response;
    await router.push({ name: 'playground-call', params: { id } });
    show(response);

    if (wasJson) showTab('json');

    return true;
};

const run = async () => {
    if (!canRun.value) return;

    running.value = true;
    runError.value = '';

    try {
        // Sent as a string so the app's input middleware can't trim or null any of the prompt's text.
        const input = { model: model.value.trim(), body: JSON.stringify(currentBody()) };
        const result = await (isNewPrompt.value
            ? apiRequest<PlaygroundRunResponse>('post', '/playground/run', { ...input, provider: provider.value, session: opened.value?.session })
            : apiRequest<PlaygroundRunResponse>('post', `/playground/${route.params.id}/run`, input));

        if (!await openRun(result.request_id)) {
            if (leadRun.value) runs.value = [leadRun.value, ...runs.value];
            leadRun.value = { ...result, number: ++runCount };
        }
    } catch (error) {
        console.error('Failed to run the call:', error);
        runError.value = (error as ApiError)?.response?.data?.message || 'The run could not be sent.';
    } finally {
        running.value = false;
    }
};

const onKeydown = (event: KeyboardEvent) => {
    if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
        event.preventDefault();
        run();
    }
};

// What the runs are compared with: the call they started from, or the opened call
// unless it is a run itself. A new request's runs have nothing to compare with.
const original = computed(() => opened.value?.original ?? (opened.value?.run ? null : opened.value?.call ?? null));

const isSuccessful = computed(() => {
    const call = original.value;

    return !!call && !!call.status_code && call.status_code >= 200 && call.status_code < 300 && !call.error_type;
});

const breadcrumbs = computed(() => [
    { label: 'Playground', to: { name: 'playground' } },
    { label: isNewPrompt.value ? 'New request' : opened.value?.call?.model ?? 'Call' },
]);

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    load();
});

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div>
        <Breadcrumb :items="breadcrumbs" />

        <div v-if="loading" class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <div class="card"><div class="card-body"><Skeleton variant="chart" height="420px" /></div></div>
            <div class="card"><div class="card-body"><Skeleton variant="chart" height="240px" /></div></div>
        </div>

        <div v-else-if="loadFailed" class="card">
            <div class="empty-state">
                <p class="empty-state-title">This call can't be opened</p>
                <p class="empty-state-description">It may have been pruned, or the Playground may be off for you. <router-link :to="{ name: 'playground' }" class="text-primary-600 hover:underline dark:text-primary-400">Back to the Playground</router-link></p>
            </div>
        </div>

        <template v-else-if="opened">
            <!-- Header -->
            <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                <div class="flex min-w-0 items-start gap-3.5">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-gray-200 bg-white shadow-card dark:border-gray-800 dark:bg-gray-900">
                        <ProviderLogo :provider="opened.provider" class="inline-flex h-5 w-5 items-center text-gray-700 dark:text-gray-200 [&>svg]:h-full [&>svg]:w-full" />
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ isNewPrompt ? 'New request' : 'Playground' }}</h1>
                            <DocsLink page="playground" />
                        </div>
                        <p v-if="!opened.call" class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Write a prompt, pick a model, and see the answer.
                            <span class="font-mono text-xs">{{ opened.endpoint }}</span>
                        </p>
                        <p v-else class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            From <router-link :to="{ name: 'request-preview', params: { id: opened.call.id } }" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ opened.run ? 'a run' : 'a call' }} to {{ opened.call.provider_display_name || opened.call.provider }}</router-link>
                            · {{ opened.call.formatted_created_at }}
                            · <span class="font-mono text-xs">{{ opened.call.endpoint }}</span>
                        </p>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <button type="button" class="btn btn-secondary" :disabled="running" @click="reset">Reset</button>
                    <button type="button" class="btn btn-primary" :disabled="!canRun" v-tooltip="'⌘ or Ctrl + Enter'" @click="run">
                        <svg class="h-4 w-4" :class="{ 'animate-pulse': running }" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" /></svg>
                        {{ running ? 'Running…' : 'Run' }}
                    </button>
                </div>
            </div>

            <!-- Why it can't run -->
            <div v-if="opened.unavailable_reason" class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
                {{ opened.unavailable_reason }}
            </div>

            <!-- What may differ from the original -->
            <div v-if="opened.notices.length" class="mb-6 space-y-1 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                <p v-for="notice in opened.notices" :key="notice">{{ notice }}</p>
            </div>

            <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-2">
                <!-- Editor -->
                <div class="card min-w-0 overflow-hidden">
                    <div class="card-header">
                        <h3>Prompt</h3>
                        <div class="segmented">
                            <button type="button" :class="{ 'is-active': tab === 'messages' }" :disabled="!shape" @click="showTab('messages')">Messages</button>
                            <button type="button" :class="{ 'is-active': tab === 'json' }" @click="showTab('json')">JSON</button>
                        </div>
                    </div>

                    <div class="space-y-4 p-5">
                        <div v-if="isNewPrompt">
                            <label for="playground-provider" class="mb-1 block text-xs text-gray-500 dark:text-gray-400">Provider</label>
                            <select id="playground-provider" :value="opened.provider" class="form-select w-full" :disabled="running || !!jsonError" @change="changeProvider">
                                <option v-for="option in opened.providers" :key="option.slug" :value="option.slug" :disabled="!option.has_key">{{ option.name }}{{ option.has_key ? '' : ' (no API key)' }}</option>
                            </select>
                        </div>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                            <div :class="tab === 'messages' ? 'sm:col-span-2' : 'sm:col-span-4'">
                                <label for="playground-model" class="mb-1 block text-xs text-gray-500 dark:text-gray-400">Model</label>
                                <input id="playground-model" v-model="model" list="playground-models" class="form-input w-full font-mono !text-xs" placeholder="Pick or type a model" autocomplete="off" spellcheck="false" />
                                <datalist id="playground-models">
                                    <option v-for="name in opened.models" :key="name" :value="name" />
                                </datalist>
                            </div>
                            <template v-if="tab === 'messages'">
                                <div>
                                    <label for="playground-temperature" class="mb-1 block text-xs text-gray-500 dark:text-gray-400">Temperature</label>
                                    <input id="playground-temperature" v-model="temperature" type="number" min="0" max="2" step="0.1" placeholder="Default" class="form-input w-full" />
                                </div>
                                <div>
                                    <label for="playground-max-tokens" class="mb-1 block text-xs text-gray-500 dark:text-gray-400">Max tokens</label>
                                    <input id="playground-max-tokens" v-model="maxTokens" type="number" min="1" step="1" placeholder="Default" class="form-input w-full" />
                                </div>
                            </template>
                        </div>

                        <!-- Messages -->
                        <template v-if="tab === 'messages'">
                            <div v-for="message in messages" :key="message.key" class="rounded-lg border border-gray-200 dark:border-gray-800">
                                <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3 py-1.5 dark:border-gray-800">
                                    <select v-if="!message.locked && !message.isSystemField" v-model="message.role" class="rounded border-0 bg-transparent py-0.5 pl-1 pr-7 text-xs font-medium capitalize text-gray-700 focus:ring-1 focus:ring-primary-500 dark:text-gray-200" :aria-label="`Role of the ${message.role} message`">
                                        <option v-for="role in rolesOf(message)" :key="role" :value="role">{{ role }}</option>
                                    </select>
                                    <span v-else class="px-1 py-0.5 text-xs font-medium capitalize text-gray-700 dark:text-gray-200">{{ message.role.replace(/_/g, ' ') }}</span>
                                    <button type="button" class="rounded p-0.5 text-gray-400 hover:text-red-600 dark:hover:text-red-400" v-tooltip="'Remove'" aria-label="Remove this message" @click="removeMessage(message.key)">
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>
                                <div v-if="message.locked" class="px-3 py-2">
                                    <p class="mb-1.5 text-xs text-gray-500 dark:text-gray-400">This message holds more than text, such as an image or a tool call. It is sent as it is; change it in the JSON tab.</p>
                                    <pre class="max-h-40 overflow-auto whitespace-pre-wrap break-all font-mono text-xs text-gray-600 dark:text-gray-300">{{ JSON.stringify(message.raw, null, 2) }}</pre>
                                </div>
                                <textarea v-else v-model="message.text" rows="4" class="block w-full resize-y rounded-b-lg border-0 bg-transparent px-3 py-2 text-sm leading-relaxed text-gray-800 placeholder-gray-400 focus:ring-1 focus:ring-inset focus:ring-primary-500 dark:text-gray-200" :placeholder="`Write the ${message.role} message`" :aria-label="`The ${message.role} message`"></textarea>
                            </div>

                            <div class="flex items-center justify-between gap-3">
                                <button v-if="shape && canAddMessages(shape)" type="button" class="btn btn-secondary" @click="addMessage">Add a message</button>
                                <p v-if="keptInBody" class="text-xs text-gray-500 dark:text-gray-400">Also sends {{ keptInBody }}. Change them in the JSON tab.</p>
                            </div>
                        </template>

                        <!-- The whole body -->
                        <template v-else>
                            <div>
                                <label for="playground-json" class="mb-1 block text-xs text-gray-500 dark:text-gray-400">Request body, as the provider gets it</label>
                                <JsonEditor id="playground-json" v-model="jsonText" />
                                <p v-if="jsonError" class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ jsonError }}</p>
                            </div>
                        </template>

                        <p class="border-t border-gray-100 pt-3 text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
                            Each run is a real call to <span class="font-mono">{{ opened.base_url }}</span>: it uses your API key, costs money, and is saved with the tag <router-link :to="{ name: 'requests', query: { tag: 'playground', period: 'all' } }" class="font-mono text-primary-600 hover:underline dark:text-primary-400">#playground</router-link>.
                        </p>
                    </div>
                </div>

                <!-- Answers -->
                <div class="min-w-0 space-y-6">
                    <div v-if="runError" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">{{ runError }}</div>

                    <div v-if="running" class="card"><div class="card-body"><Skeleton variant="text" :lines="4" /></div></div>

                    <RunResult
                        v-if="leadRun && !running"
                        :key="leadRun.request_id ?? `run-${leadRun.number}`"
                        :title="leadRun.number ? `Run ${leadRun.number}` : 'This run'"
                        :model="leadRun.model"
                        :succeeded="leadRun.succeeded"
                        :status-code="leadRun.status_code"
                        :error-type="leadRun.error_type"
                        :error-message="leadRun.error_message"
                        :reply="leadRun.reply"
                        :has-tool-calls="leadRun.has_tool_calls"
                        :finish-reason="leadRun.finish_reason"
                        :prompt-tokens="leadRun.prompt_tokens"
                        :completion-tokens="leadRun.completion_tokens"
                        :cost-in-cents="leadRun.total_cost_in_cents"
                        :latency-ms="leadRun.latency_ms"
                        :response="leadRun.response"
                        :request-id="leadRun.request_id"
                        :compare-with="original"
                        highlighted
                    />

                    <div v-if="!original && !leadRun && !runs.length && !running" class="card">
                        <div class="empty-state">
                            <p class="empty-state-title">The answer appears here</p>
                            <p class="empty-state-description">Each run gets its own page and stays listed with the others, so you can change the prompt or model and compare.</p>
                        </div>
                    </div>

                    <RunResult
                        v-if="original"
                        title="Original call"
                        :model="original.model"
                        :succeeded="isSuccessful"
                        :status-code="original.status_code"
                        :error-type="original.error_type"
                        :error-message="original.error_message"
                        :reply="original.reply"
                        :finish-reason="original.finish_reason"
                        :prompt-tokens="original.prompt_tokens"
                        :completion-tokens="original.completion_tokens"
                        :cost-in-cents="original.total_cost_in_cents"
                        :latency-ms="original.latency_ms"
                        :request-id="original.id"
                    />

                    <RunResult
                        v-for="other in runs"
                        :key="other.request_id ?? `run-${other.number}`"
                        :title="`Run ${other.number}`"
                        :model="other.model"
                        :succeeded="other.succeeded"
                        :status-code="other.status_code"
                        :error-type="other.error_type"
                        :error-message="other.error_message"
                        :reply="other.reply"
                        :has-tool-calls="other.has_tool_calls"
                        :finish-reason="other.finish_reason"
                        :prompt-tokens="other.prompt_tokens"
                        :completion-tokens="other.completion_tokens"
                        :cost-in-cents="other.total_cost_in_cents"
                        :latency-ms="other.latency_ms"
                        :response="other.response"
                        :request-id="other.request_id"
                        :compare-with="original"
                    />
                </div>
            </div>
        </template>
    </div>
</template>
