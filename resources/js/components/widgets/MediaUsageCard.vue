<script setup lang="ts">
import Money from '@/components/Money.vue';

// The strip of media usage on the Overview and on a shared dashboard: only the
// kinds that appear in the period. A card's cost is null when the page hides costs.
defineProps<{
    cards: { key: string; label: string; value: string; unit: string; cost: number | null }[];
}>();
</script>

<template>
    <div
        class="card grid grid-cols-1 divide-y divide-gray-100 sm:divide-x sm:divide-y-0 dark:divide-gray-800"
        :class="{ 1: 'sm:grid-cols-1', 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-3', 4: 'sm:grid-cols-4' }[cards.length]"
    >
        <div v-for="card in cards" :key="card.key" class="flex items-center justify-between gap-4 px-5 py-3.5">
            <div class="min-w-0">
                <div class="stat-label">{{ card.label }}</div>
                <div class="mt-0.5 flex items-baseline gap-1.5">
                    <span class="text-lg font-semibold tracking-tight text-gray-900 tabular-nums dark:text-white">{{ card.value }}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ card.unit }}</span>
                </div>
            </div>
            <span v-if="card.cost !== null" class="text-sm font-medium tabular-nums text-gray-700 dark:text-gray-300"><Money :cents="card.cost" /></span>
        </div>
    </div>
</template>
