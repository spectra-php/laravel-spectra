<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';

// A card's refresh button: the arrows spin while the card reloads, then a check
// shows for a moment so the user sees the reload finished.
const props = withDefaults(defineProps<{
    refreshing?: boolean;
    label?: string;
}>(), {
    refreshing: false,
    label: 'Refresh',
});

const emit = defineEmits<{
    refresh: [];
}>();

const done = ref(false);
let doneTimer: ReturnType<typeof setTimeout> | null = null;

watch(() => props.refreshing, (refreshing, wasRefreshing) => {
    if (doneTimer) clearTimeout(doneTimer);
    done.value = !refreshing && wasRefreshing;
    if (done.value) {
        doneTimer = setTimeout(() => { done.value = false; }, 1200);
    }
});

onBeforeUnmount(() => {
    if (doneTimer) clearTimeout(doneTimer);
});
</script>

<template>
    <button
        type="button"
        class="refresh-button"
        :class="{ 'is-refreshing': refreshing, 'is-done': done }"
        :disabled="refreshing"
        :aria-label="label"
        :aria-busy="refreshing"
        v-tooltip="refreshing ? 'Refreshing…' : label"
        @click="emit('refresh')"
    >
        <Transition name="refresh-icon" mode="out-in">
            <svg v-if="done" key="done" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />
            </svg>
            <svg v-else key="arrows" class="refresh-button-arrows h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H3.989a.75.75 0 0 0-.75.75v4.242a.75.75 0 0 0 1.5 0v-2.43l.31.31a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm1.23-3.723a.75.75 0 0 0 .219-.53V2.929a.75.75 0 0 0-1.5 0V5.36l-.31-.31A7 7 0 0 0 3.239 8.188a.75.75 0 1 0 1.448.389A5.5 5.5 0 0 1 13.89 6.11l.311.31h-2.432a.75.75 0 0 0 0 1.5h4.243a.75.75 0 0 0 .53-.219Z" clip-rule="evenodd" />
            </svg>
        </Transition>
    </button>
</template>
