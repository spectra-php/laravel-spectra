<script setup lang="ts">
// What a section shows when its data couldn't be loaded.
const emit = defineEmits<{ retry: [] }>();
</script>

<template>
    <div class="card">
        <div class="empty-state">
            <p class="empty-state-title">This page could not be loaded</p>
            <p class="empty-state-description">Please try again in a moment.</p>
            <button type="button" class="btn btn-secondary btn-sm mt-4" @click="emit('retry')">Try again</button>
        </div>
    </div>
</template>
