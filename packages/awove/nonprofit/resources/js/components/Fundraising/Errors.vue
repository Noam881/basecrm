<script setup lang="ts">
defineProps<{ errors: Record<string, string | undefined> }>();
</script>
<template>
    <div
        v-if="Object.values(errors).some(Boolean)"
        class="crm-notice crm-form-span"
        role="alert"
    >
        <p v-for="(error, key) in errors" :key="key">{{ error }}</p>
    </div>
</template>
