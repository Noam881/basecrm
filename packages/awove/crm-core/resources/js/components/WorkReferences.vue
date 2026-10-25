<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { useId } from "vue";
export interface WorkReference {
    id: string;
    label: string;
    href: string;
}
defineProps<{ links: WorkReference[] }>();
const heading = useId();
</script>
<template>
    <section
        v-if="links.length"
        class="crm-card crm-flow"
        :aria-labelledby="heading"
    >
        <h2 :id="heading">Instructions</h2>
        <ul class="crm-flow">
            <li v-for="link in links" :key="link.id">
                <Link :href="link.href">{{ link.label }}</Link>
            </li>
        </ul>
    </section>
</template>
