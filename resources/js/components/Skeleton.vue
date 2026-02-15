<script setup>
defineProps({
    variant: {
        type: String,
        default: 'text',
        validator: (v) => ['text', 'circle', 'card', 'chart', 'table-row'].includes(v)
    },
    lines: {
        type: Number,
        default: 1
    },
    width: {
        type: String,
        default: null
    },
    height: {
        type: String,
        default: null
    }
});
</script>

<template>
    <div class="animate-pulse">
        <!-- Text skeleton -->
        <template v-if="variant === 'text'">
            <div
                v-for="i in lines"
                :key="i"
                class="skeleton-line"
                :class="{ 'w-3/4': i === lines && lines > 1 }"
                :style="{ width, height }"
            ></div>
        </template>

        <!-- Circle skeleton (for avatars) -->
        <template v-else-if="variant === 'circle'">
            <div class="skeleton-circle" :style="{ width, height }"></div>
        </template>

        <!-- Card skeleton -->
        <template v-else-if="variant === 'card'">
            <div class="skeleton-card">
                <div class="skeleton-line w-1/3 h-4 mb-3"></div>
                <div class="skeleton-line w-2/3 h-8"></div>
            </div>
        </template>

        <!-- Chart skeleton -->
        <template v-else-if="variant === 'chart'">
            <div class="skeleton-chart" :style="{ height: height || '200px' }">
                <div class="skeleton-bars">
                    <div class="skeleton-bar" style="height: 60%"></div>
                    <div class="skeleton-bar" style="height: 80%"></div>
                    <div class="skeleton-bar" style="height: 45%"></div>
                    <div class="skeleton-bar" style="height: 90%"></div>
                    <div class="skeleton-bar" style="height: 55%"></div>
                    <div class="skeleton-bar" style="height: 70%"></div>
                    <div class="skeleton-bar" style="height: 40%"></div>
                </div>
            </div>
        </template>

        <!-- Table row skeleton -->
        <template v-else-if="variant === 'table-row'">
            <tr v-for="i in lines" :key="i" class="border-b border-gray-200 dark:border-gray-700">
                <td class="px-4 py-3" v-for="j in 5" :key="j">
                    <div class="skeleton-line h-4" :class="j === 1 ? 'w-20' : 'w-16'"></div>
                </td>
            </tr>
        </template>
    </div>
</template>

<style scoped>
.skeleton-line {
    @apply bg-gray-200 dark:bg-gray-700 rounded h-4 mb-2;
}

.skeleton-line:last-child {
    @apply mb-0;
}

.skeleton-circle {
    @apply bg-gray-200 dark:bg-gray-700 rounded-full w-10 h-10;
}

.skeleton-card {
    @apply p-6;
}

.skeleton-chart {
    @apply relative bg-gray-100 dark:bg-gray-800 rounded-lg overflow-hidden;
}

.skeleton-bars {
    @apply absolute bottom-0 left-0 right-0 flex items-end justify-around px-4 pb-4 gap-2;
    height: 80%;
}

.skeleton-bar {
    @apply flex-1 bg-gray-200 dark:bg-gray-700 rounded-t;
}
</style>
