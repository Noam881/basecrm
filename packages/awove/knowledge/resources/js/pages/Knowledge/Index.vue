<script setup lang="ts">
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
    <Head title="Knowledge" />
    <div class="crm-heading">
        <h1>Knowledge</h1>
        <div class="crm-form-action">
            <a
                v-if="canExport"
                class="crm-secondary"
                :href="
                    '/knowledge/export?' +
                    queryString({ ...props.filters, section, ...table.state })
                "
                >Export {{ section }} (CSV)</a
            ><button
                v-if="canCreate"
                class="crm-button"
                @click="create = !create"
            >
                {{ create ? "Close form" : "Add record" }}
            </button>
        </div>
    </div>
    <div class="crm-flow">
        <nav class="crm-tabs" aria-label="Knowledge sections">
            <Link
                v-for="s in sections"
                :key="s"
                :href="'/knowledge?section=' + s"
                :aria-current="s === section ? 'page' : undefined"
                >{{ sectionLabels[s] }}</Link
            >
        </nav>
        <div class="crm-metrics">
            <Link
                v-for="(value, key) in metrics"
                :key="key"
                class="crm-card"
                :href="'/knowledge?' + queryString(metricQueries[key])"
                ><span>{{ metricLabels[key] }}</span
                ><strong>{{ value }}</strong></Link
            >
        </div>
        <form class="crm-card crm-form-grid" @submit.prevent="apply">
            <label class="crm-field"
                ><span class="crm-field-label">Owner filter</span
                ><select v-model="filters.owner_id">
                    <option value="">All accessible owners</option>
                    <option
                        v-for="owner in filterOwners"
                        :key="owner.id"
                        :value="owner.id"
                    >
                        {{ owner.name }}
                    </option>
                </select></label
            ><label class="crm-field"
                ><span class="crm-field-label">View</span
                ><select v-model="filters.view">
                    <option value="">All active records</option>
                    <option v-if="section === 'library'" value="current">
                        Current
                    </option>
                    <template v-if="section === 'renewals'"
                        ><option value="open">Open renewals</option>
                        <option value="upcoming">Next 30 days</option>
                        <option value="overdue">Expired</option></template
                    >
                    <option value="missing-owner">Missing owner</option>
                    <option
                        v-if="section !== 'systems' && canViewDocuments"
                        value="missing-document"
                    >
                        Missing document or revision
                    </option>
                    <option value="archived">Archived</option>
                </select></label
            ><template v-if="section === 'library'"
                ><label class="crm-field"
                    ><span class="crm-field-label">Kind filter</span
                    ><select v-model="filters.kind">
                        <option value="">All kinds</option>
                        <option
                            v-for="(label, id) in kinds"
                            :key="id"
                            :value="id"
                        >
                            {{ label }}
                        </option>
                    </select></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Role filter</span
                    ><input v-model="filters.role" maxlength="160" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Category filter</span
                    ><input
                        v-model="filters.category"
                        maxlength="120" /></label></template
            ><template v-if="section !== 'systems'"
                ><label class="crm-field"
                    ><span class="crm-field-label">From date</span
                    ><input v-model="filters.from" type="date" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">To date</span
                    ><input
                        v-model="filters.to"
                        type="date"
                        :min="filters.from || undefined"
                /></label>
                <p class="crm-muted">
                    Periods use updated document dates for the library and
                    expiry dates for renewals. No document age is assumed to be
                    stale.
                </p></template
            >
            <div class="crm-form-action">
                <button class="crm-button">Apply filters</button
                ><Link
                    class="crm-secondary"
                    :href="'/knowledge?section=' + section"
                    >Clear filters</Link
                >
            </div>
        </form>
        <section v-if="create && canCreate" class="crm-card crm-flow">
            <h2>New {{ sectionLabels[section].toLowerCase() }} record</h2>
            <RecordForm :section="section" :options="props" />
        </section>
        <DataTable
            :payload="table"
            endpoint="/knowledge"
            :base-query="{ ...props.filters, section }"
            :title="sectionLabels[section]"
        />
    </div>
</template>
