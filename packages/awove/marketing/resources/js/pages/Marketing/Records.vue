<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import DataTable from "../../../../../crm-core/resources/js/components/table/DataTable.vue";
import type { TablePayload } from "../../../../../crm-core/resources/js/components/table/types";
import { queryString } from "../../../../../crm-core/resources/js/components/table/queryString";
import MarketingNav from "../../components/MarketingNav.vue";
import MarketingFilters from "../../components/MarketingFilters.vue";
import Editor from "../../components/Editor.vue";
import type { Options, Totals } from "../../types";
const p = defineProps<
    Options & {
        table: TablePayload;
        filters: Record<string, string>;
        totals?: Totals | null;
    }
>();
const title =
    p.kind === "campaigns"
        ? "Campaigns"
        : p.kind === "content"
          ? "Content calendar"
          : "Performance";
</script>
<template>
    <Head :title="title" />
    <div class="crm-heading">
        <h1>{{ title }}</h1>
        <a
            v-if="canExport"
            class="crm-secondary"
            :href="`/marketing/${kind}/export?${queryString({ ...filters, ...table.state })}`"
            >Export CSV</a
        >
    </div>
    <div class="crm-flow">
        <MarketingNav v-bind="p" /><MarketingFilters
            :endpoint="`/marketing/${kind}`"
            :reports="kind === 'reports'"
            v-bind="p"
        />
        <p v-if="!currencyConsistent" class="crm-notice" role="alert">
            The configured currency differs from retained records. Restore the
            original currency before financial edits or totals.
        </p>
        <p v-if="totals" class="crm-notice" role="status">
            Actual spend: {{ totals.actual_spend }} {{ currency }} · Reported
            leads: {{ totals.reported_leads }} · Cost per lead:
            {{ totals.cost_per_lead ?? "Not available" }}
        </p>
        <section v-if="canCreate && kind !== 'reports'" class="crm-card">
            <details class="crm-form-disclosure">
                <summary>
                    {{ kind === "campaigns" ? "Add campaign" : "Plan content" }}
                </summary>
                <Editor v-bind="p" />
            </details>
        </section>
        <DataTable
            :payload="table"
            :endpoint="`/marketing/${kind}`"
            :title="title"
            :base-query="filters"
        />
    </div>
</template>
