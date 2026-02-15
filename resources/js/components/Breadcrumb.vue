<script setup>
defineProps({
    items: {
        type: Array,
        required: true,
        validator: (items) => items.every((item) => item.label),
    },
});
</script>

<template>
    <nav class="mb-4" aria-label="Breadcrumb">
        <ol class="flex items-center gap-1.5 text-sm">
            <li v-for="(item, index) in items" :key="index" class="flex items-center gap-1.5">
                <svg
                    v-if="index > 0"
                    class="w-3.5 h-3.5 text-gray-400 dark:text-gray-500 shrink-0"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
                <router-link
                    v-if="item.to && index < items.length - 1"
                    :to="item.to"
                    class="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 transition-colors"
                >
                    {{ item.label }}
                </router-link>
                <span
                    v-else
                    :class="index === items.length - 1
                        ? 'text-gray-900 dark:text-white font-medium'
                        : 'text-gray-500 dark:text-gray-400'"
                >
                    {{ item.label }}
                </span>
            </li>
        </ol>
    </nav>
</template>
