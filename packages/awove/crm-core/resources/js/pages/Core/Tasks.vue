<script setup lang="ts">
import { t } from "@/lib/i18n";
import { Head, Link } from "@inertiajs/vue3";
import DataTable from "../../components/table/DataTable.vue";
import type { TablePayload } from "../../components/table/types";
defineProps<{ table: TablePayload; view: string }>();
</script>
<template>
    <Head :title="t('Follow-ups')" />
    <div class="crm-heading">
        <h1>{{ t("Follow-ups") }}</h1>
    </div>
    <div class="crm-tabs md-quick-views">
        <Link href="/tasks" :aria-current="!view ? 'page' : undefined">{{
            t("Open")
        }}</Link
        ><Link
            href="/tasks?view=today"
            :aria-current="view === 'today' ? 'page' : undefined"
            >{{ t("Today") }}</Link
        ><Link
            href="/tasks?view=overdue"
            :aria-current="view === 'overdue' ? 'page' : undefined"
            >{{ t("Overdue") }}</Link
        ><Link
            href="/tasks?view=completed"
            :aria-current="view === 'completed' ? 'page' : undefined"
            >{{ t("Completed") }}</Link
        >
    </div>
    <DataTable
        :payload="table"
        endpoint="/tasks"
        :title="t('Follow-ups')"
        :base-query="view ? { view } : {}"
    />
</template>
