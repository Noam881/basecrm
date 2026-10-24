<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
defineProps<{ canViewDirectory: boolean }>();
</script>
<template>
    <nav class="crm-tabs" aria-label="Operations navigation">
        <Link href="/operations">Overview</Link
        ><Link href="/operations/requests">Requests</Link
        ><Link href="/operations/orders">Supplier orders</Link
        ><Link href="/operations/routines">Routines</Link
        ><Link v-if="canViewDirectory" href="/operations/directory"
            >Customers &amp; suppliers</Link
        >
    </nav>
</template>
