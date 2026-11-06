<script setup lang="ts">
// The previous and next links under a shared dashboard's list.
defineProps<{ page: number; lastPage: number }>();

const emit = defineEmits<{ change: [page: number] }>();
</script>

<template>
    <div v-if="lastPage > 1" class="flex items-center justify-between border-t border-gray-100 px-5 py-3 dark:border-gray-800">
        <span class="text-sm text-gray-500 dark:text-gray-400">Page {{ page }} of {{ lastPage }}</span>
        <div class="flex gap-2">
            <button type="button" class="btn btn-secondary btn-sm" :disabled="page <= 1" @click="emit('change', page - 1)">Previous</button>
            <button type="button" class="btn btn-secondary btn-sm" :disabled="page >= lastPage" @click="emit('change', page + 1)">Next</button>
        </div>
    </div>
</template>
