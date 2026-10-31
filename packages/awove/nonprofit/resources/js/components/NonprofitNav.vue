<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { Link } from "@inertiajs/vue3";
defineProps<{ sections: { label: string; href: string }[] }>();
</script>
<template>
    <nav class="crm-tabs" :aria-label="tr('Nonprofit navigation')">
        <Link
            v-for="section in sections"
            :key="section.href"
            :href="section.href"
            >{{ section.label }}</Link
        >
    </nav>
</template>
