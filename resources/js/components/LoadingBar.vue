<script setup lang="ts">
defineProps<{
    loading?: boolean;
}>();
</script>

<template>
    <div v-if="loading" class="loading-bar" />
</template>

<style scoped>
.loading-bar {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 2px;
    z-index: 100;
    background: linear-gradient(90deg, transparent 0%, #ff5f6d 20%, #ffa94d 35%, #4ade80 55%, #38bdf8 70%, #8b7dff 85%, transparent 100%);
    background-size: 200% 100%;
    animation: loading-slide 1.2s linear infinite;
}

@keyframes loading-slide {
    from { background-position: 200% 0; }
    to { background-position: -200% 0; }
}
</style>
