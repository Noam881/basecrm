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
        <Link href="/tasks" :aria-current="!view ? 'page' : undefined"
            >Open</Link
        ><Link
            href="/tasks?view=today"
            :aria-current="view === 'today' ? 'page' : undefined"
            >Today</Link
        ><Link
            href="/tasks?view=overdue"
            :aria-current="view === 'overdue' ? 'page' : undefined"
            >Overdue</Link
        ><Link
            href="/tasks?view=completed"
            :aria-current="view === 'completed' ? 'page' : undefined"
            >Completed</Link
        >
    </div>
    <DataTable
        :payload="table"
        endpoint="/tasks"
        title="Follow-ups"
        :base-query="view ? { view } : {}"
    />
</template>
