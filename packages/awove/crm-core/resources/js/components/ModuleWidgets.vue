<script setup lang="ts">
import { Link } from "@inertiajs/vue3";

export interface DashboardWidget {
    id: string;
    module: string;
    label: string;
    value: number | string;
    href: string;
}
defineProps<{ widgets: DashboardWidget[] }>();
</script>
<template>
    <section
        v-if="widgets.length"
        aria-label="Module overviews"
        class="crm-metrics"
    >
        <Link
            v-for="widget in widgets"
            :key="widget.id"
            :href="widget.href"
            class="crm-card"
            ><span>{{ widget.label }}</span
            ><strong>{{ widget.value }}</strong></Link
        >
    </section>
</template>
