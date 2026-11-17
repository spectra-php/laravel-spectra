<script setup lang="ts">
import BudgetLimitBars from '@/components/widgets/BudgetLimitBars.vue';
import type { DashboardBudgetLimit } from '@/types/api';

defineProps<{ limits: DashboardBudgetLimit[] }>();
</script>

<template>
    <div class="card h-full">
        <div class="card-header">
            <div>
                <h3>Budget</h3>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Usage against your limits</p>
            </div>
        </div>
        <div class="card-body">
            <div v-if="!limits.length" class="empty-state py-8">
                <p class="empty-state-description">No budget is set</p>
            </div>
            <BudgetLimitBars v-else :limits="limits" />
        </div>
    </div>
</template>
