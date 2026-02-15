<script setup>
defineProps({
    type: {
        type: String,
        default: 'info',
    },
    message: {
        type: String,
        required: true,
    },
});

const typeClasses = {
    success: 'bg-green-50 text-green-800 border-green-200 dark:bg-green-900/20 dark:text-green-400 dark:border-green-800',
    danger: 'bg-red-50 text-red-800 border-red-200 dark:bg-red-900/20 dark:text-red-400 dark:border-red-800',
    warning: 'bg-yellow-50 text-yellow-800 border-yellow-200 dark:bg-yellow-900/20 dark:text-yellow-400 dark:border-yellow-800',
    info: 'bg-blue-50 text-blue-800 border-blue-200 dark:bg-blue-900/20 dark:text-blue-400 dark:border-blue-800',
};
</script>

<template>
    <div
        :class="['mb-4 p-4 rounded-lg border', typeClasses[type] || typeClasses.info]"
        role="alert"
    >
        {{ message }}
    </div>
</template>
