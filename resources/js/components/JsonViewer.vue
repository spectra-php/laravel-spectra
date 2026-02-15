<script setup>
import { ref, computed } from 'vue';
import hljs from 'highlight.js/lib/core';
import json from 'highlight.js/lib/languages/json';

hljs.registerLanguage('json', json);

const props = defineProps({
    data: {
        type: [Object, Array, String],
        default: null
    },
    title: {
        type: String,
        default: null
    },
    maxHeight: {
        type: String,
        default: '24rem'
    }
});

const copied = ref(false);

const formattedJson = computed(() => {
    if (!props.data) return '';

    if (typeof props.data === 'string') {
        try {
            return JSON.stringify(JSON.parse(props.data), null, 2);
        } catch {
            return props.data;
        }
    }

    return JSON.stringify(props.data, null, 2);
});

const highlightedCode = computed(() => {
    if (!formattedJson.value) return '';

    try {
        return hljs.highlight(formattedJson.value, { language: 'json' }).value;
    } catch {
        return formattedJson.value;
    }
});

const copy = async (text) => {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
    }
    copied.value = true;
    setTimeout(() => copied.value = false, 2000);
};
</script>

<template>
    <div class="json-viewer">
        <div class="json-viewer-header">
            <span v-if="title" class="json-viewer-title">{{ title }}</span>
            <button
                @click="copy(formattedJson)"
                class="json-viewer-copy-btn"
                :class="{ 'copied': copied }"
            >
                <svg v-if="!copied" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
                <svg v-else class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <span class="ml-1">{{ copied ? 'Copied!' : 'Copy' }}</span>
            </button>
        </div>
        <div class="json-viewer-content" :style="{ maxHeight }">
            <pre ref="codeRef"><code class="hljs language-json" v-html="highlightedCode"></code></pre>
        </div>
    </div>
</template>

<style scoped>
.json-viewer {
    @apply overflow-hidden border border-gray-200 dark:border-gray-700;
}

.json-viewer-header {
    @apply flex items-center justify-between px-4 py-2 bg-gray-100 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700;
}

.json-viewer-title {
    @apply text-sm font-medium text-gray-700 dark:text-gray-300;
}

.json-viewer-copy-btn {
    @apply inline-flex items-center px-2 py-1 text-xs font-medium rounded-md transition-colors;
    @apply text-gray-600 hover:text-gray-900 hover:bg-gray-200;
    @apply dark:text-gray-400 dark:hover:text-white dark:hover:bg-gray-700;
}

.json-viewer-copy-btn.copied {
    @apply text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/30;
}

.json-viewer-content {
    @apply overflow-auto bg-gray-50 dark:bg-gray-900;
}

.json-viewer-content pre {
    @apply m-0 p-4 text-sm leading-relaxed;
}

.json-viewer-content code {
    @apply font-mono;
}
</style>
