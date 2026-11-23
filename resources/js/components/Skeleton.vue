<script setup lang="ts">
withDefaults(defineProps<{
    variant?: 'text' | 'chart' | 'table-row';
    lines?: number;
    // For table rows: the table's number of columns, so the placeholder cells sit under its headings.
    columns?: number;
    width?: string | null;
    height?: string | null;
}>(), {
    variant: 'text',
    lines: 1,
    columns: 5,
    width: null,
    height: null,
});
</script>

<template>
    <!-- Table rows have no wrapper: a <tbody> takes rows only, and anything else is squeezed into its first column. -->
    <template v-if="variant === 'table-row'">
        <tr v-for="i in lines" :key="i" class="animate-pulse border-b border-gray-200 dark:border-gray-800">
            <td class="px-4 py-3" v-for="j in columns" :key="j">
                <div class="skeleton-line h-4" :class="j === 1 ? 'w-20' : 'w-16'"></div>
            </td>
        </tr>
    </template>

    <div v-else class="animate-pulse">
        <!-- Text skeleton -->
        <template v-if="variant === 'text'">
            <div
                v-for="i in lines"
                :key="i"
                class="skeleton-line"
                :class="{ 'w-3/4': i === lines && lines > 1 }"
                :style="{ width: width!, height: height! }"
            ></div>
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
    </div>
</template>

<style scoped>
@reference "../../css/app.css";

.skeleton-line {
    @apply bg-gray-200 dark:bg-gray-700 rounded h-4 mb-2;
}

.skeleton-line:last-child {
    @apply mb-0;
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
