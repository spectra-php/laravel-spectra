<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { useHelpers } from '@/composables/useHelpers';

// A cost in cents, shown with the dashboard's one rounding rule. When that
// rounds the amount, hovering or focusing it shows the exact amount in a
// tooltip rendered on <body>, so a card's overflow can't clip it.
const props = defineProps({
    cents: { type: [Number, String], default: 0 },
});

const { formatCurrency, formatCurrencyExact } = useHelpers();

const shown = computed(() => formatCurrency(props.cents));
const exact = computed(() => formatCurrencyExact(props.cents));
const rounded = computed(() => exact.value !== shown.value);

const anchor = ref(null);
const position = ref(null);

const show = () => {
    if (!rounded.value || !anchor.value) return;

    const rect = anchor.value.getBoundingClientRect();
    position.value = { left: rect.left + rect.width / 2, top: rect.top };
    window.addEventListener('scroll', hide, { passive: true, capture: true });
};

const hide = () => {
    position.value = null;
    window.removeEventListener('scroll', hide, { capture: true });
};

onBeforeUnmount(hide);
</script>

<template>
    <span
        ref="anchor"
        :tabindex="rounded ? 0 : undefined"
        :class="rounded ? 'cursor-help rounded-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/50' : undefined"
        :aria-label="rounded ? exact : undefined"
        @mouseenter="show"
        @mouseleave="hide"
        @focus="show"
        @blur="hide"
    >{{ shown }}</span>

    <Teleport v-if="position" to="body">
        <span
            role="tooltip"
            class="pointer-events-none fixed z-50 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-md bg-gray-900 px-2 py-1 text-[11px] font-medium tabular-nums text-white shadow-pop dark:bg-gray-100 dark:text-gray-900"
            :style="{ left: position.left + 'px', top: position.top - 6 + 'px' }"
        >{{ exact }}</span>
    </Teleport>
</template>
