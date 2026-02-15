<script setup>
defineProps({
    loading: Boolean,
});
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
    background: linear-gradient(90deg, transparent, var(--loading-color, #6366f1) 50%, transparent);
    background-size: 200% 100%;
    animation: loading-slide 1.2s linear infinite;
}

@keyframes loading-slide {
    from { background-position: 200% 0; }
    to { background-position: -200% 0; }
}
</style>
