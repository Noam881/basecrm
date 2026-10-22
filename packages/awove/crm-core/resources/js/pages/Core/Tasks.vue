<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import DataTable from "../../components/table/DataTable.vue";
import type { TablePayload } from "../../components/table/types";
defineProps<{ table: TablePayload; view: string }>();
</script>
<template>
    <Head title="Follow-ups" />
    <div class="crm-heading">
        <h1>Follow-ups</h1>
    </div>
    <div class="crm-tabs md-quick-views">
        <Link href="/tasks">Open</Link
        ><Link href="/tasks?view=today">Today</Link
        ><Link href="/tasks?view=overdue">Overdue</Link
        ><Link href="/tasks?view=completed">Completed</Link>
    </div>
    <DataTable
        :payload="table"
        endpoint="/tasks"
        title="Follow-ups"
        :base-query="view ? { view } : {}"
    />
</template>
