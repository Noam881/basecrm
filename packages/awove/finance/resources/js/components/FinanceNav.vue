<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import type { Section } from "../types";
defineProps<{ sections: Section[] }>();
</script>
<template>
    <nav class="crm-tabs" aria-label="Finance navigation">
        <Link href="/finance">Overview</Link
        ><Link
            v-for="section in sections"
            :key="section.href"
            :href="section.href"
            >{{ section.label }}</Link
        >
    </nav>
</template>
