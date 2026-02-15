<script setup>
import { ref } from 'vue';

const props = defineProps({
    content: {
        type: String,
        default: ''
    },
    maxHeight: {
        type: String,
        default: '24rem'
    },
    variant: {
        type: String,
        default: 'default',
        validator: (value) => ['default', 'error'].includes(value)
    }
});

const copied = ref(false);

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
    <div class="code-block" :class="variant">
        <div class="code-block-header">
            <button
                @click="copy(content)"
                class="code-block-copy-btn"
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
        <div class="code-block-content" :style="{ maxHeight }">
            <pre><code>{{ content }}</code></pre>
        </div>
    </div>
</template>

<style scoped>
.code-block {
    @apply overflow-hidden border border-gray-200 dark:border-gray-700;
}

.code-block.error {
    @apply border-red-200 dark:border-red-800;
}

.code-block-header {
    @apply flex items-center justify-end px-4 py-2 bg-gray-100 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700;
}

.code-block.error .code-block-header {
    @apply bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800;
}

.code-block-copy-btn {
    @apply inline-flex items-center px-2 py-1 text-xs font-medium rounded-md transition-colors;
    @apply text-gray-600 hover:text-gray-900 hover:bg-gray-200;
    @apply dark:text-gray-400 dark:hover:text-white dark:hover:bg-gray-700;
}

.code-block.error .code-block-copy-btn {
    @apply text-red-600 hover:text-red-900 hover:bg-red-100;
    @apply dark:text-red-400 dark:hover:text-red-300 dark:hover:bg-red-900/40;
}

.code-block-copy-btn.copied {
    @apply text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/30;
}

.code-block-content {
    @apply overflow-auto bg-gray-50 dark:bg-gray-900;
}

.code-block.error .code-block-content {
    @apply bg-red-50/50 dark:bg-red-900/10;
}

.code-block-content pre {
    @apply m-0 p-4 text-sm leading-relaxed whitespace-pre-wrap;
    @apply text-gray-800 dark:text-gray-200;
}

.code-block.error .code-block-content pre {
    @apply text-red-800 dark:text-red-400;
}

.code-block-content code {
    @apply font-mono;
}
</style>
