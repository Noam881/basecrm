<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { Link } from "@inertiajs/vue3";
import type { Section } from "../types";
defineProps<{ sections: Section[] }>();
</script>
<template>
    <nav class="crm-tabs" :aria-label="tr('Finance navigation')">
        <Link href="/finance">{{ tr("Overview") }}</Link
        ><Link
            v-for="section in sections"
            :key="section.href"
            :href="section.href"
            >{{ section.label }}</Link
        >
    </nav>
</template>
