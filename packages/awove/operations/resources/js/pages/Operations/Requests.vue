<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import DataTable from "../../../../../crm-core/resources/js/components/table/DataTable.vue";
import type { TablePayload } from "../../../../../crm-core/resources/js/components/table/types";
import OperationsNav from "../../components/OperationsNav.vue";
import Filters from "../../components/Filters.vue";
import RequestEditor from "../../components/RequestEditor.vue";
import { exportUrl, type Options } from "../../types";
const props = defineProps<
    Options & { table: TablePayload; filters: Record<string, string> }
>();
</script>
<template>
    <Head title="Operational requests" />
    <div class="crm-heading">
        <h1>Operational requests</h1>
        <a
            v-if="canExport"
            class="crm-secondary"
            :href="exportUrl('/operations/requests', filters, table.state)"
            >Export CSV</a
        >
    </div>
    <div class="crm-flow">
        <OperationsNav :can-view-directory="canViewDirectory" />
        <div class="crm-tabs">
            <Link href="/operations/requests">Current</Link
            ><Link href="/operations/requests?view=overdue">Overdue</Link
            ><Link v-if="canViewAll" href="/operations/requests?view=unassigned"
                >Unassigned</Link
            ><Link href="/operations/requests?view=archived">Archived</Link>
        </div>
        <Filters v-bind="props" endpoint="/operations/requests" customer />
        <section v-if="canCreate" class="crm-card">
            <details class="crm-form-disclosure">
                <summary>Add operational request</summary>
                <RequestEditor v-bind="props" />
            </details>
        </section>
        <DataTable
            :payload="table"
            endpoint="/operations/requests"
            title="Operational requests"
            :base-query="filters"
        />
    </div>
</template>
