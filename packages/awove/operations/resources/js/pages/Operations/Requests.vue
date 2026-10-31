<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
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
    <Head :title="tr('Operational requests')" />
    <div class="crm-heading">
        <h1>{{ tr("Operational requests") }}</h1>
        <a
            v-if="canExport"
            class="crm-secondary"
            :href="exportUrl('/operations/requests', filters, table.state)"
            >{{ tr("Export CSV") }}</a
        >
    </div>
    <div class="crm-flow">
        <OperationsNav :can-view-directory="canViewDirectory" />
        <div class="crm-tabs">
            <Link href="/operations/requests">{{ tr("Current") }}</Link
            ><Link href="/operations/requests?view=overdue">{{
                tr("Overdue")
            }}</Link
            ><Link
                v-if="canViewAll"
                href="/operations/requests?view=unassigned"
                >{{ tr("Unassigned") }}</Link
            ><Link href="/operations/requests?view=archived">{{
                tr("Archived")
            }}</Link>
        </div>
        <Filters v-bind="props" endpoint="/operations/requests" customer />
        <section v-if="canCreate" class="crm-card">
            <details class="crm-form-disclosure">
                <summary>{{ tr("Add operational request") }}</summary>
                <RequestEditor v-bind="props" />
            </details>
        </section>
        <DataTable
            :payload="table"
            endpoint="/operations/requests"
            :title="tr('Operational requests')"
            :base-query="filters"
        />
    </div>
</template>
