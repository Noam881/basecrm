<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { Head, Link, router } from "@inertiajs/vue3";
import { reactive } from "vue";
import DataTable from "../../../../../crm-core/resources/js/components/table/DataTable.vue";
import type { TablePayload } from "../../../../../crm-core/resources/js/components/table/types";
import { queryString } from "../../../../../crm-core/resources/js/components/table/queryString";
import FinanceNav from "../../components/FinanceNav.vue";
import PayrollEditor from "../../components/PayrollEditor.vue";
import type { PayrollOptions } from "../../payroll";
const props = defineProps<
    PayrollOptions & { table: TablePayload; filters: Record<string, string> }
>();
const filters = reactive({
    owner_id: props.filters.owner_id ?? "",
    status: props.filters.status ?? "",
    from: props.filters.from ?? "",
    to: props.filters.to ?? "",
    archived: props.filters.archived ?? "",
});
function apply() {
    router.get("/finance/payroll", filters, {
        preserveState: true,
        preserveScroll: true,
    });
}
</script>
<template>
    <Head :title="tr('Payroll preparation')" />
    <div class="crm-heading">
        <h1>{{ tr("Payroll preparation") }}</h1>
        <a
            v-if="canExport"
            class="crm-secondary"
            :href="`/finance/payroll/export?${queryString({ ...props.filters, ...table.state })}`"
            >{{ tr("Export CSV") }}</a
        >
    </div>
    <div class="crm-flow">
        <FinanceNav :sections="sections" />
        <form class="crm-card crm-form-grid" @submit.prevent="apply">
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Owner") }}</span
                ><select v-model="filters.owner_id">
                    <option value="">{{ tr("All permitted owners") }}</option>
                    <option
                        v-for="owner in owners"
                        :key="owner.id"
                        :value="owner.id"
                    >
                        {{ owner.name }}
                    </option>
                </select></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("Status") }}</span
                ><select v-model="filters.status">
                    <option value="">{{ tr("Any status") }}</option>
                    <option
                        v-for="(label, key) in statuses"
                        :key="key"
                        :value="key"
                    >
                        {{ label }}
                    </option>
                </select></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("Period from") }}</span
                ><input v-model="filters.from" type="date" /></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("Period to") }}</span
                ><input v-model="filters.to" type="date" /></label
            ><label class="crm-checkbox"
                ><input
                    v-model="filters.archived"
                    type="checkbox"
                    true-value="1"
                    false-value=""
                />{{ tr("Include archived") }}</label
            >
            <div class="crm-form-action">
                <button class="crm-secondary">{{ tr("Apply filters") }}</button
                ><Link href="/finance/payroll" class="crm-secondary">{{
                    tr("Clear")
                }}</Link>
            </div>
        </form>
        <section v-if="canCreate" class="crm-card">
            <details class="crm-form-disclosure">
                <summary>{{ tr("Add payroll preparation") }}</summary>
                <PayrollEditor v-bind="props" />
            </details>
        </section>
        <DataTable
            :payload="table"
            endpoint="/finance/payroll"
            :title="tr('Payroll preparations')"
            :base-query="props.filters"
        />
    </div>
</template>
