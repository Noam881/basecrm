<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<template>
    <div v-show="message" data-slot="input-error" role="alert">
        <p>
            {{ message }}
        </p>
    </div>
</template>
