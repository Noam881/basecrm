<script setup lang="ts">
defineProps<{ errors: Record<string, string | undefined> }>();
</script>
<template>
    <div
        v-if="Object.values(errors).some(Boolean)"
        class="crm-notice"
        role="alert"
    >
        <p v-for="(message, key) in errors" :key="key">{{ message }}</p>
    </div>
</template>
