<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { Link } from "@inertiajs/vue3";
defineProps<{ canViewDirectory: boolean }>();
</script>
<template>
    <nav class="crm-tabs" :aria-label="tr('Operations navigation')">
        <Link href="/operations">{{ tr("Overview") }}</Link
        ><Link href="/operations/requests">{{ tr("Requests") }}</Link
        ><Link href="/operations/orders">{{ tr("Supplier orders") }}</Link
        ><Link href="/operations/routines">{{ tr("Routines") }}</Link
        ><Link v-if="canViewDirectory" href="/operations/directory">{{
            tr("Customers & suppliers")
        }}</Link>
    </nav>
</template>
