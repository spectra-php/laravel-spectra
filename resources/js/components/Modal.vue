<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue';

// The dashboard's dialog, on both the dashboard and a shared dashboard's page.
// The parent shows it with v-if and hides it on `close`:
//
//   <Modal v-if="open" title="All models" @close="open = false">…</Modal>
//
// While it is open, focus stays inside it, Escape and a click on the backdrop
// close it, and closing puts focus back on whatever opened it. It is drawn on
// <body>, so nothing around the place it is written in can clip it.
withDefaults(defineProps<{
    title: string;
    subtitle?: string | null;
    size?: 'md' | 'lg';
}>(), {
    subtitle: null,
    size: 'md',
});

const emit = defineEmits<{ close: [] }>();

const panel = ref<HTMLElement | null>(null);
const titleId = useId();
let opener: HTMLElement | null = null;

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

const focusable = () => [...(panel.value?.querySelectorAll<HTMLElement>(FOCUSABLE) ?? [])].filter((el) => el.offsetParent !== null);

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        event.preventDefault();
        emit('close');
        return;
    }

    if (event.key !== 'Tab' || !panel.value) return;

    const items = focusable();
    const first = items[0];
    const last = items[items.length - 1];
    const active = document.activeElement;

    // Nothing to move to: focus stays on the dialog itself.
    if (!first || !last) {
        event.preventDefault();
        panel.value.focus();
        return;
    }

    if (!panel.value.contains(active) || (event.shiftKey && (active === first || active === panel.value))) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus();
    } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
    }
};

// A click closes it only when it both started and ended on the backdrop, so
// selecting text and letting go outside the dialog leaves it open.
let pressedBackdrop = false;

const onBackdropClick = () => {
    if (pressedBackdrop) emit('close');
    pressedBackdrop = false;
};

onMounted(async () => {
    opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    document.addEventListener('keydown', onKeydown);
    await nextTick();
    panel.value?.focus();
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    if (opener?.isConnected) opener.focus();
});
</script>

<template>
    <Teleport to="body">
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4 backdrop-blur-xs"
            @mousedown.self="pressedBackdrop = true"
            @click.self="onBackdropClick"
        >
            <div
                ref="panel"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="titleId"
                tabindex="-1"
                class="flex max-h-[85vh] w-full flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-pop focus-visible:ring-0 dark:border-gray-800 dark:bg-gray-900"
                :class="size === 'lg' ? 'max-w-2xl' : 'max-w-xl'"
                @mousedown="pressedBackdrop = false"
            >
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h3 :id="titleId" class="text-base font-semibold text-gray-900 dark:text-white">{{ title }}</h3>
                            <p v-if="subtitle" class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ subtitle }}</p>
                        </div>
                        <button type="button" class="btn-ghost inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" aria-label="Close" @click="emit('close')">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                    <!-- Controls that belong to the dialog's heading, such as a search box. -->
                    <slot name="header" />
                </div>

                <div class="min-h-0 flex-1 overflow-auto">
                    <slot />
                </div>

                <slot name="footer" />
            </div>
        </div>
    </Teleport>
</template>
