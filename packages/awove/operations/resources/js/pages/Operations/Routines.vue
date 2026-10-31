<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { Head, Link } from "@inertiajs/vue3";
import DataTable from "../../../../../crm-core/resources/js/components/table/DataTable.vue";
import type { TablePayload } from "../../../../../crm-core/resources/js/components/table/types";
import OperationsNav from "../../components/OperationsNav.vue";
import Filters from "../../components/Filters.vue";
import RoutineEditor from "../../components/RoutineEditor.vue";
import { exportUrl, type Options } from "../../types";
const props = defineProps<
    Options & { table: TablePayload; filters: Record<string, string> }
>();
</script>
<template>
    <Head :title="tr('Operational routines')" />
    <div class="crm-heading">
        <h1>{{ tr("Operational routines") }}</h1>
        <a
            v-if="canExport"
            class="crm-secondary"
            :href="exportUrl('/operations/routines', filters, table.state)"
            >{{ tr("Export CSV") }}</a
        >
    </div>
    <div class="crm-flow">
        <OperationsNav :can-view-directory="canViewDirectory" />
        <div class="crm-tabs">
            <Link href="/operations/routines">{{ tr("Current") }}</Link
            ><Link href="/operations/routines?view=upcoming">{{
                tr("Upcoming")
            }}</Link
            ><Link href="/operations/routines?view=overdue">{{
                tr("Overdue")
            }}</Link
            ><Link
                v-if="canViewAll"
                href="/operations/routines?view=unassigned"
                >{{ tr("Unassigned") }}</Link
            ><Link href="/operations/routines?view=archived">{{
                tr("Archived")
            }}</Link>
        </div>
        <Filters v-bind="props" endpoint="/operations/routines" />
        <section v-if="canCreate" class="crm-card">
            <details class="crm-form-disclosure">
                <summary>{{ tr("Add operational routine") }}</summary>
                <RoutineEditor v-bind="props" />
            </details>
        </section>
        <DataTable
            :payload="table"
            endpoint="/operations/routines"
            :title="tr('Operational routines')"
            :base-query="filters"
        />
    </div>
</template>
