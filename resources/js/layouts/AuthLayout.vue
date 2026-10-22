<script setup lang="ts">
import { usePage } from "@inertiajs/vue3";
const page = usePage();
const brand = page.props.brand as { name: string; color: string; logo: string };
defineProps<{ title?: string; description?: string }>();
</script>
<template>
    <div class="crm-auth" :style="{ '--brand': brand.color }">
        <div class="crm-auth-story">
            <img :src="brand.logo" alt="" width="52" height="52" /><span>{{
                brand.name
            }}</span>
            <h1>Good relationships.<br />Great possibilities.</h1>
            <p>
                A calmer place to manage your customers, conversations, and next
                steps.
            </p>
            <small>YOUR RELATIONSHIPS, IN FOCUS</small>
        </div>
        <main class="crm-auth-form">
            <div>
                <img
                    class="crm-mobile-logo"
                    :src="brand.logo"
                    alt=""
                    width="40"
                />
                <p class="crm-eyebrow">{{ brand.name }}</p>
                <h2>{{ title ?? "Welcome back." }}</h2>
                <p>{{ description ?? "Sign in to your workspace." }}</p>
                <slot />
            </div>
        </main>
    </div>
</template>
