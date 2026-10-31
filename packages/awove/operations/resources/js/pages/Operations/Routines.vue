<script setup lang="ts">
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
    <Head title="Operational routines" />
    <div class="crm-heading">
        <h1>Operational routines</h1>
        <a
            v-if="canExport"
            class="crm-secondary"
            :href="exportUrl('/operations/routines', filters, table.state)"
            >Export CSV</a
        >
    </div>
    <div class="crm-flow">
        <OperationsNav :can-view-directory="canViewDirectory" />
        <div class="crm-tabs">
            <Link href="/operations/routines">Current</Link
            ><Link href="/operations/routines?view=upcoming">Upcoming</Link
            ><Link href="/operations/routines?view=overdue">Overdue</Link
            ><Link v-if="canViewAll" href="/operations/routines?view=unassigned"
                >Unassigned</Link
            ><Link href="/operations/routines?view=archived">Archived</Link>
        </div>
        <Filters v-bind="props" endpoint="/operations/routines" />
        <section v-if="canCreate" class="crm-card">
            <details class="crm-form-disclosure">
                <summary>Add operational routine</summary>
                <RoutineEditor v-bind="props" />
            </details>
        </section>
        <DataTable
            :payload="table"
            endpoint="/operations/routines"
            title="Operational routines"
            :base-query="filters"
        />
    </div>
</template>
