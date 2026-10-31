<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { Head, Link, router } from "@inertiajs/vue3";
import { reactive, ref } from "vue";
import DataTable from "../../../../../crm-core/resources/js/components/table/DataTable.vue";
import { queryString } from "../../../../../crm-core/resources/js/components/table/queryString";
import type { TablePayload } from "../../../../../crm-core/resources/js/components/table/types";
import RecordForm from "../../components/RecordForm.vue";
import { sectionLabels, type Options, type Section } from "../../types";
const props = defineProps<
    Options & {
        section: Section;
        table: TablePayload;
        metrics: Record<string, number>;
        filters: Record<string, string>;
    }
>();
const create = ref(false);
const filters = reactive({
    owner_id: props.filters.owner_id ?? "",
    from: props.filters.from ?? "",
    to: props.filters.to ?? "",
    view: props.filters.view ?? "",
    kind: props.filters.kind ?? "",
});
function apply() {
    router.get(
        "/hr",
        { ...filters, section: props.section },
        { preserveState: false },
    );
}
const metricLabels: Record<string, string> = {
    employees: "Active employees",
    vacancies: "Open vacancies",
    candidates: "Active candidates",
    cases: "Pending onboarding/offboarding",
};
</script>
<template>
    <Head :title="tr('Human resources')" />
    <div class="crm-heading">
        <h1>{{ tr("Human resources") }}</h1>
        <div class="crm-form-action">
            <a
                v-if="canExport"
                class="crm-secondary"
                :href="
                    '/hr/export?' +
                    queryString({ ...props.filters, section, ...table.state })
                "
                >{{ tr("Export") }} {{ tr(section) }} {{ tr("(CSV)") }}</a
            ><button
                v-if="canCreate"
                class="crm-button"
                @click="create = !create"
            >
                {{ create ? tr("Close form") : tr("Add record") }}
            </button>
        </div>
    </div>
    <div class="crm-flow">
        <nav class="crm-tabs" :aria-label="tr('HR sections')">
            <Link
                v-for="s in sections"
                :key="s"
                :href="'/hr?section=' + s"
                :aria-current="section === s ? 'page' : undefined"
                >{{ tr(sectionLabels[s]) }}</Link
            >
        </nav>
        <div class="crm-metrics">
            <Link
                v-for="(value, key) in metrics"
                :key="key"
                class="crm-card"
                :href="'/hr?section=' + key + '&view=open'"
                ><span>{{ tr(metricLabels[key]) }}</span
                ><strong>{{ value }}</strong></Link
            >
        </div>
        <form class="crm-card crm-form-grid" @submit.prevent="apply">
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Owner filter") }}</span
                ><select v-model="filters.owner_id">
                    <option value="">{{ tr("All accessible owners") }}</option>
                    <option
                        v-for="owner in filterOwners"
                        :key="owner.id"
                        :value="owner.id"
                    >
                        {{ owner.name }}
                    </option>
                </select></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("View") }}</span
                ><select v-model="filters.view">
                    <option value="">{{ tr("All active records") }}</option>
                    <option value="open">{{ tr("Open") }}</option>
                    <option
                        v-if="section === 'candidates' || section === 'cases'"
                        value="overdue"
                    >
                        {{ tr("Overdue") }}
                    </option>
                    <option value="archived">{{ tr("Archived") }}</option>
                </select></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("From date") }}</span
                ><input v-model="filters.from" type="date" /></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("To date") }}</span
                ><input
                    v-model="filters.to"
                    type="date"
                    :min="filters.from || undefined" /></label
            ><label v-if="section === 'cases'" class="crm-field"
                ><span class="crm-field-label">{{ tr("Purpose filter") }}</span
                ><select v-model="filters.kind">
                    <option value="">
                        {{ tr("Onboarding and offboarding") }}
                    </option>
                    <option value="onboarding">{{ tr("Onboarding") }}</option>
                    <option value="offboarding">{{ tr("Offboarding") }}</option>
                </select></label
            >
            <p class="crm-muted">
                {{
                    tr(
                        "Periods use employment start, vacancy opening, candidate next-action and case due dates. Only records within your permissions are shown.",
                    )
                }}
            </p>
            <div class="crm-form-action">
                <button class="crm-button">{{ tr("Apply filters") }}</button
                ><Link :href="'/hr?section=' + section" class="crm-secondary">{{
                    tr("Clear filters")
                }}</Link>
            </div>
        </form>
        <section v-if="create && canCreate" class="crm-card crm-flow">
            <h2>
                {{ tr("New") }} {{ tr(sectionLabels[section]).toLowerCase() }}
                {{ tr("record") }}
            </h2>
            <RecordForm :section="section" :options="props" />
        </section>
        <DataTable
            :payload="table"
            endpoint="/hr"
            :base-query="{ ...props.filters, section }"
            :title="tr(sectionLabels[section])"
        />
    </div>
</template>
