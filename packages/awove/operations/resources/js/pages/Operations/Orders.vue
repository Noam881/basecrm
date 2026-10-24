<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import DataTable from "../../../../../crm-core/resources/js/components/table/DataTable.vue";
import type { TablePayload } from "../../../../../crm-core/resources/js/components/table/types";
import OperationsNav from "../../components/OperationsNav.vue";
import Filters from "../../components/Filters.vue";
import OrderEditor from "../../components/OrderEditor.vue";
import { exportUrl, type Options } from "../../types";
const props = defineProps<
    Options & { table: TablePayload; filters: Record<string, string> }
>();
</script>
<template>
    <Head title="Supplier orders" />
    <div class="crm-heading">
        <h1>Supplier orders</h1>
        <a
            v-if="canExport"
            class="crm-secondary"
            :href="exportUrl('/operations/orders', filters, table.state)"
            >Export CSV</a
        >
    </div>
    <div class="crm-flow">
        <OperationsNav :can-view-directory="canViewDirectory" />
        <div class="crm-tabs">
            <Link href="/operations/orders">Current</Link
            ><Link href="/operations/orders?view=overdue">Overdue</Link
            ><Link v-if="canViewAll" href="/operations/orders?view=unassigned"
                >Unassigned</Link
            ><Link href="/operations/orders?view=archived">Archived</Link>
        </div>
        <Filters v-bind="props" endpoint="/operations/orders" supplier />
        <section v-if="canCreate" class="crm-card">
            <details class="crm-form-disclosure">
                <summary>Add supplier order</summary>
                <OrderEditor v-bind="props" />
            </details>
        </section>
        <DataTable
            :payload="table"
            endpoint="/operations/orders"
            title="Supplier orders"
            :base-query="filters"
        />
    </div>
</template>
