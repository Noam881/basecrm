<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<template>
    <div v-show="message">
        <p class="text-sm text-neutral-600 dark:text-neutral-500">
            {{ message }}
        </p>
    </div>
</template>
