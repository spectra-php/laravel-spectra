<script setup lang="ts">
import Trackables from '@/screens/trackables/index.vue';

// The trackables screen, limited to users. A screen of its own so moving
// between the two remounts the list: the router reuses a shared component.
</script>

<template>
    <Trackables users-only />
</template>
