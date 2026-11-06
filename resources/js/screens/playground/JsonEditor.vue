<script setup lang="ts">
import { computed, ref } from 'vue';
import hljs from 'highlight.js/lib/core';
import json from 'highlight.js/lib/languages/json';

// An editable, highlighted JSON box, without an editor library in the bundle:
// a textarea with invisible text sits exactly over a highlighted copy of the
// same text, so typing, selecting and undo stay the browser's own. Both reserve
// the scrollbar's width, or their lines would wrap at different places.
hljs.registerLanguage('json', json);

defineProps<{
    id: string;
}>();

const text = defineModel<string>({ required: true });
const highlight = ref<HTMLElement | null>(null);

// ignoreIllegals keeps colouring half-typed JSON. The extra line break lets the
// copy grow with a last empty line, as a textarea does.
const highlighted = computed(() => hljs.highlight(`${text.value}\n`, { language: 'json', ignoreIllegals: true }).value);

const syncScroll = (event: Event) => {
    const textarea = event.target as HTMLTextAreaElement;

    if (!highlight.value) return;

    highlight.value.scrollTop = textarea.scrollTop;
    highlight.value.scrollLeft = textarea.scrollLeft;
};
</script>

<template>
    <div class="json-editor">
        <pre ref="highlight" class="json-editor-layer pointer-events-none overflow-hidden" aria-hidden="true"><code class="hljs language-json !bg-transparent !p-0" v-html="highlighted"></code></pre>
        <textarea
            :id="id"
            v-model="text"
            class="json-editor-layer json-editor-input overflow-y-auto"
            spellcheck="false"
            autocomplete="off"
            autocapitalize="off"
            wrap="soft"
            @scroll="syncScroll"
        ></textarea>
    </div>
</template>

<style scoped>
@reference "../../../css/app.css";

.json-editor {
    @apply relative h-[32rem] min-h-40 resize-y overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xs transition-colors duration-150;
    @apply hover:border-gray-300 focus-within:border-primary-500 focus-within:ring-2 focus-within:ring-primary-500/20;
    @apply dark:border-gray-700/80 dark:bg-gray-900 dark:hover:border-gray-600;
}

/* The two layers must lay text out identically, character for character. */
.json-editor-layer {
    @apply absolute inset-0 m-0 h-full w-full border-0 bg-transparent px-3 py-2.5 font-mono text-xs leading-relaxed;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    tab-size: 2;
    scrollbar-gutter: stable;
}

.json-editor-layer code {
    font: inherit;
    white-space: inherit;
    overflow-wrap: inherit;
}

.json-editor-input {
    @apply resize-none text-transparent caret-gray-900 outline-none selection:bg-primary-500/25 focus:ring-0 focus:outline-none dark:caret-white;
    box-shadow: none;
}
</style>
