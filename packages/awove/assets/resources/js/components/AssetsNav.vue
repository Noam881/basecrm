<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { Link } from "@inertiajs/vue3";
import type { Section } from "../types";
defineProps<{ sections: Section[]; moduleLabel: string }>();
const labels: Record<Section, string> = {
    register: "Asset register",
    assignments: "Assignments",
    maintenance: "Maintenance",
};
</script>
<template>
    <nav
        class="crm-tabs"
        :aria-label="tr('{module} navigation', { module: moduleLabel })"
    >
        <Link
            v-for="section in sections"
            :key="section"
            :href="`/assets?section=${section}`"
            >{{
                section === "register" ? moduleLabel : tr(labels[section])
            }}</Link
        >
    </nav>
</template>
