<script setup lang="ts">
import { computed } from "vue";
const props = defineProps<{
    errors: Record<string, string | undefined>;
    notice?: string | null;
}>();
const messages = computed(() =>
    [...new Set([props.notice, ...Object.values(props.errors)])].filter(
        (message): message is string => !!message,
    ),
);
</script>
<template>
    <div v-if="messages.length" class="crm-error crm-form-span" role="alert">
        <p v-for="message in messages" :key="message">{{ message }}</p>
    </div>
</template>
