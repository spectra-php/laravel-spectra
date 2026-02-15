<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    expiresAt: { type: String, required: true },
    formattedExpiresAt: { type: String, default: '' },
});

const emit = defineEmits(['expired']);

const now = ref(Date.now());
let timer = null;

const expiresAtMs = computed(() => new Date(props.expiresAt).getTime());
const remaining = computed(() => Math.max(0, expiresAtMs.value - now.value));
const hasExpired = computed(() => remaining.value === 0);

const display = computed(() => {
    if (hasExpired.value) return null;

    const total = Math.floor(remaining.value / 1000);
    const days = Math.floor(total / 86400);
    const hours = Math.floor((total % 86400) / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = total % 60;

    const pad = (n) => String(n).padStart(2, '0');

    if (days > 0) {
        return `${days}d ${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
    }
    return `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
});

const tick = () => {
    now.value = Date.now();
    if (hasExpired.value) {
        clearInterval(timer);
        timer = null;
        emit('expired');
    }
};

onMounted(() => {
    if (!hasExpired.value) {
        timer = setInterval(tick, 1000);
    }
});

onUnmounted(() => {
    if (timer) clearInterval(timer);
});
</script>

<template>
    <span
        v-if="hasExpired"
        class="text-red-500 dark:text-red-400 font-medium"
    >
        Expired
    </span>
    <span
        v-else
        class="tabular-nums"
        :title="formattedExpiresAt"
    >
        {{ display }}
    </span>
</template>
