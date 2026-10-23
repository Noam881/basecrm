<script setup lang="ts">
import { usePage } from "@inertiajs/vue3";
import { computed } from "vue";
const page = usePage();
const brand = computed(
    () => page.props.brand as { name: string; logo: string },
);
defineProps<{ title?: string; description?: string }>();
</script>
<template>
    <div class="crm-auth">
        <main class="crm-auth-form">
            <div>
                <div class="crm-auth-brand">
                    <img :src="brand.logo" alt="" width="40" height="40" />
                    <span>{{ brand.name }}</span>
                </div>
                <h1>{{ title ?? "Log in" }}</h1>
                <p>{{ description ?? "Sign in to your workspace." }}</p>
                <slot />
            </div>
        </main>
    </div>
</template>
