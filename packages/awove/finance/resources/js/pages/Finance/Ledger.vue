<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import DataTable from "../../../../../crm-core/resources/js/components/table/DataTable.vue";
import type { TablePayload } from "../../../../../crm-core/resources/js/components/table/types";
import FinanceNav from "../../components/FinanceNav.vue";
import LedgerEditor from "../../components/LedgerEditor.vue";
import LedgerFilters from "../../components/LedgerFilters.vue";
import ClassifyIdentity from "../../components/ClassifyIdentity.vue";
import { exportUrl, type Options } from "../../types";
const props = defineProps<
    Options & { table: TablePayload; filters: Record<string, string> }
>();
const title =
    props.cap === "collections" ? "Customer charges" : "Supplier expenses";
</script>
<template>
    <Head :title="title" />
    <div class="crm-heading">
        <h1>{{ title }}</h1>
        <a
            v-if="canExport"
            class="crm-secondary"
            :href="exportUrl(cap, filters, table.state)"
            >Export CSV</a
        >
    </div>
    <div class="crm-flow">
        <FinanceNav :sections="sections" />
        <div class="crm-tabs">
            <Link :href="`/finance/${cap}`">All records</Link
            ><Link :href="`/finance/${cap}?view=outstanding`">Outstanding</Link
            ><Link :href="`/finance/${cap}?view=overdue`">Overdue</Link
            ><Link :href="`/finance/${cap}?view=paid`">Paid</Link
            ><Link :href="`/finance/${cap}?view=cancelled`">Cancelled</Link
            ><Link v-if="canViewAll" :href="`/finance/${cap}?view=unassigned`"
                >Unassigned</Link
            >
        </div>
        <LedgerFilters v-bind="props" />
        <section v-if="canCreate && canClassify" class="crm-card">
            <ClassifyIdentity v-bind="props" />
        </section>
        <section v-if="canCreate" class="crm-card">
            <details class="crm-form-disclosure">
                <summary>
                    {{
                        cap === "collections"
                            ? "Add customer charge"
                            : "Add supplier expense"
                    }}
                </summary>
                <LedgerEditor v-bind="props" />
            </details>
        </section>
        <DataTable
            :payload="table"
            :endpoint="`/finance/${cap}`"
            :title="title"
            :base-query="filters"
        />
    </div>
</template>
