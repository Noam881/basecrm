<script setup lang="ts">
import VaultWorkbench from "../../components/VaultWorkbench.vue";
defineProps<{ endpoint: string; editable?: boolean }>();
</script>
<template>
    <VaultWorkbench :endpoint="endpoint" :editable="editable" />
</template>
