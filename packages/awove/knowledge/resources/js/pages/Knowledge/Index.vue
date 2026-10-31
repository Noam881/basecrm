<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { Head, Link, router } from "@inertiajs/vue3";
import { reactive, ref } from "vue";
import DataTable from "../../../../../crm-core/resources/js/components/table/DataTable.vue";
import { queryString } from "../../../../../crm-core/resources/js/components/table/queryString";
import type { TablePayload } from "../../../../../crm-core/resources/js/components/table/types";
import RecordForm from "../../components/RecordForm.vue";
import { sectionLabels, type Section, type Options } from "../../types";
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
    kind: props.filters.kind ?? "",
    role: props.filters.role ?? "",
    category: props.filters.category ?? "",
    from: props.filters.from ?? "",
    to: props.filters.to ?? "",
    view: props.filters.view ?? "",
});
function apply() {
    router.get(
        "/knowledge",
        { ...filters, section: props.section },
        { preserveState: false },
    );
}
const metricLabels: Record<string, string> = {
    current: "Current materials",
    upcoming: "Renewals in the next 30 days",
    overdue: "Expired renewals",
    "missing-owner-library": "Library records missing an owner",
    "missing-document": "Library records missing a document or revision",
};
const metricQueries: Record<string, Record<string, string>> = {
    current: { section: "library", view: "current" },
    upcoming: { section: "renewals", view: "upcoming" },
    overdue: { section: "renewals", view: "overdue" },
    "missing-owner-library": { section: "library", view: "missing-owner" },
    "missing-document": { section: "library", view: "missing-document" },
};
</script>
<template>
    <Head :title="tr('Knowledge')" />
    <div class="crm-heading">
        <h1>{{ tr("Knowledge") }}</h1>
        <div class="crm-form-action">
            <a
                v-if="canExport"
                class="crm-secondary"
                :href="
                    '/knowledge/export?' +
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
        <nav class="crm-tabs" :aria-label="tr('Knowledge sections')">
            <Link
                v-for="s in sections"
                :key="s"
                :href="'/knowledge?section=' + s"
                :aria-current="s === section ? 'page' : undefined"
                >{{ tr(sectionLabels[s]) }}</Link
            >
        </nav>
        <div class="crm-metrics">
            <Link
                v-for="(value, key) in metrics"
                :key="key"
                class="crm-card"
                :href="'/knowledge?' + queryString(metricQueries[key])"
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
                    <option v-if="section === 'library'" value="current">
                        {{ tr("Current") }}
                    </option>
                    <template v-if="section === 'renewals'"
                        ><option value="open">{{ tr("Open renewals") }}</option>
                        <option value="upcoming">
                            {{ tr("Next 30 days") }}
                        </option>
                        <option value="overdue">
                            {{ tr("Expired") }}
                        </option></template
                    >
                    <option value="missing-owner">
                        {{ tr("Missing owner") }}
                    </option>
                    <option
                        v-if="section !== 'systems' && canViewDocuments"
                        value="missing-document"
                    >
                        {{ tr("Missing document or revision") }}
                    </option>
                    <option value="archived">{{ tr("Archived") }}</option>
                </select></label
            ><template v-if="section === 'library'"
                ><label class="crm-field"
                    ><span class="crm-field-label">{{ tr("Kind filter") }}</span
                    ><select v-model="filters.kind">
                        <option value="">{{ tr("All kinds") }}</option>
                        <option
                            v-for="(label, id) in kinds"
                            :key="id"
                            :value="id"
                        >
                            {{ label }}
                        </option>
                    </select></label
                ><label class="crm-field"
                    ><span class="crm-field-label">{{ tr("Role filter") }}</span
                    ><input v-model="filters.role" maxlength="160" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Category filter")
                    }}</span
                    ><input
                        v-model="filters.category"
                        maxlength="120" /></label></template
            ><template v-if="section !== 'systems'"
                ><label class="crm-field"
                    ><span class="crm-field-label">{{ tr("From date") }}</span
                    ><input v-model="filters.from" type="date" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">{{ tr("To date") }}</span
                    ><input
                        v-model="filters.to"
                        type="date"
                        :min="filters.from || undefined"
                /></label>
                <p class="crm-muted">
                    {{
                        tr(
                            "Periods use updated document dates for the library and expiry dates for renewals. No document age is assumed to be stale.",
                        )
                    }}
                </p></template
            >
            <div class="crm-form-action">
                <button class="crm-button">{{ tr("Apply filters") }}</button
                ><Link
                    class="crm-secondary"
                    :href="'/knowledge?section=' + section"
                    >{{ tr("Clear filters") }}</Link
                >
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
            endpoint="/knowledge"
            :base-query="{ ...props.filters, section }"
            :title="tr(sectionLabels[section])"
        />
    </div>
</template>
