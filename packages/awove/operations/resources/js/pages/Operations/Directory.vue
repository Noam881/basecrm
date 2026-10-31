<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { Head, Link, router } from "@inertiajs/vue3";
import { reactive } from "vue";
import DataTable from "../../../../../crm-core/resources/js/components/table/DataTable.vue";
import type { TablePayload } from "../../../../../crm-core/resources/js/components/table/types";
import OperationsNav from "../../components/OperationsNav.vue";
import ClassificationEditor from "../../components/ClassificationEditor.vue";
import { exportUrl, type Options, type Person } from "../../types";
const props = defineProps<
    Options & {
        table: TablePayload;
        filters: Record<string, string>;
        contacts: (Person & { company_id?: number | null })[];
        companies: Person[];
        classificationOwners: Person[];
        canAssignClassification: boolean;
    }
>();
const filters = reactive({
    role: props.filters.role ?? "",
    active: props.filters.active ?? "",
    archived: props.filters.archived ?? "",
});
</script>
<template>
    <Head :title="tr('Customers & suppliers')" />
    <div class="crm-heading">
        <h1>{{ tr("Customers & suppliers") }}</h1>
        <a
            v-if="canExport"
            class="crm-secondary"
            :href="exportUrl('/operations/directory', filters, table.state)"
            >{{ tr("Export CSV") }}</a
        >
    </div>
    <div class="crm-flow">
        <OperationsNav :can-view-directory="canViewDirectory" />
        <form
            class="crm-card crm-form-grid"
            @submit.prevent="
                router.get('/operations/directory', filters, {
                    preserveState: true,
                })
            "
        >
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Classification") }}</span
                ><select v-model="filters.role">
                    <option value="">
                        {{ tr("Customers and suppliers") }}
                    </option>
                    <option value="customer">{{ tr("Customers") }}</option>
                    <option value="supplier">{{ tr("Suppliers") }}</option>
                </select></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("Activity") }}</span
                ><select v-model="filters.active">
                    <option value="">{{ tr("All activity states") }}</option>
                    <option value="1">{{ tr("Active") }}</option>
                    <option value="0">{{ tr("Inactive") }}</option>
                </select></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("Archive") }}</span
                ><select v-model="filters.archived">
                    <option value="">
                        {{ tr("Current classifications") }}
                    </option>
                    <option value="1">
                        {{ tr("Archived classifications") }}
                    </option>
                </select></label
            >
            <div class="crm-form-action">
                <button class="crm-button">
                    {{ tr("Apply directory filters") }}</button
                ><Link class="crm-secondary" href="/operations/directory">{{
                    tr("Clear filters")
                }}</Link>
            </div>
        </form>
        <section v-if="canClassify" class="crm-card">
            <details class="crm-form-disclosure">
                <summary>
                    {{ tr("Classify existing contact or company") }}
                </summary>
                <ClassificationEditor v-bind="props" />
            </details>
        </section>
        <DataTable
            :payload="table"
            endpoint="/operations/directory"
            :title="tr('Customers and suppliers')"
            :base-query="filters"
        />
    </div>
</template>
