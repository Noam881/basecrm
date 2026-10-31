<script setup lang="ts">
import { computed } from "vue";
const p = defineProps<{ errors: Record<string, string | undefined> }>();
const messages = computed(() =>
    [...new Set(Object.values(p.errors))].filter((v): v is string => !!v),
);
</script>
<template>
    <div v-if="messages.length" class="crm-error" role="alert">
        <p v-for="message in messages" :key="message">{{ message }}</p>
    </div>
</template>
