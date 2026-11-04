<script setup>
import { computed } from 'vue';

// A score's value as a badge: Yes/No for yes/no scores, the number for
// numeric ones, the label for categories.
const props = defineProps({
    type: { type: String, required: true },
    value: { type: [Number, Boolean, String], default: null },
});

const text = computed(() => {
    if (props.type === 'boolean') return props.value ? 'Yes' : 'No';
    if (props.type === 'numeric') return Number(props.value ?? 0).toLocaleString('en-US', { maximumFractionDigits: 2 });

    return props.value ?? '';
});

const badgeClass = computed(() => {
    if (props.type === 'boolean') return props.value ? 'badge-success' : 'badge-danger';

    return 'badge-secondary';
});
</script>

<template>
    <span class="badge shrink-0 tabular-nums" :class="badgeClass">
        <svg v-if="type === 'boolean'" class="mr-1 h-3 w-3" :class="value ? '' : 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.633 10.25c.806 0 1.533-.446 2.031-1.08a9.041 9.041 0 0 1 2.861-2.4c.723-.384 1.35-.956 1.653-1.715a4.498 4.498 0 0 0 .322-1.672V2.75a.75.75 0 0 1 .75-.75 2.25 2.25 0 0 1 2.25 2.25c0 1.152-.26 2.243-.723 3.218-.266.558.107 1.282.725 1.282h3.126c1.026 0 1.945.694 2.054 1.715.045.422.068.85.068 1.285a11.95 11.95 0 0 1-2.649 7.521c-.388.482-.987.729-1.605.729H13.48c-.483 0-.964-.078-1.423-.23l-3.114-1.04a4.501 4.501 0 0 0-1.423-.23H5.904m.729-8.5H5.25a2.25 2.25 0 0 0-2.25 2.25v4.5a2.25 2.25 0 0 0 2.25 2.25h.654" />
        </svg>
        {{ text }}
    </span>
</template>
