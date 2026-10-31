<script setup lang="ts">
import { t } from "@/lib/i18n";
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import { ref } from "vue";
import type { Lead, Page, Person, Stage, Field } from "../../types";
import DataTable from "../../components/table/DataTable.vue";
import StageControl from "../../components/StageControl.vue";
import type { TablePayload } from "../../components/table/types";
const props = defineProps<{
    table: TablePayload;
    leads: Page<Lead>;
    contacts: Person[];
    companies: Person[];
    owners: Person[];
    statuses: Stage[];
    fields: Record<string, Field>;
    filters: Record<string, string>;
    preferences: { columns?: string[] };
}>();
const brand = usePage().props.brand as { lead_label: string; currency: string };
const capabilities = usePage().props.capabilities as string[];
const showCreate = ref(false);
const form = useForm({
    title: "",
    contact_id: null as number | null,
    company_id: null as number | null,
    owner_id: props.owners[0]?.id ?? null,
    status_id: props.statuses[0]?.id ?? "new",
    source: "Direct",
    needs: "",
    estimated_value: "",
    priority: "normal",
    custom_fields: {} as Record<string, string | number | boolean>,
});
</script>
<template>
    <Head :title="brand.lead_label" />
    <div class="crm-heading">
        <h1>{{ brand.lead_label }}</h1>
        <div
            v-if="capabilities.includes('leads.create')"
            class="crm-form-action"
        >
            <Link href="/people" class="crm-secondary">{{
                t("Add new contact")
            }}</Link>
            <button class="crm-button" @click="showCreate = !showCreate">
                {{ showCreate ? t("Close form") : t("+ Create lead") }}
            </button>
        </div>
    </div>
    <section v-if="showCreate" class="crm-card">
        <h2>{{ t("New lead") }}</h2>
        <p v-if="!contacts.length && !companies.length">
            {{ t("Start by") }}
            <Link href="/people">{{ t("adding a new contact") }}</Link
            >.
        </p>
        <form class="crm-form-grid" @submit.prevent="form.post('/leads')">
            <div class="crm-field">
                <label
                    ><span class="crm-field-label">{{ t("Contact") }}</span>
                    <select v-model="form.contact_id">
                        <option :value="null">{{ t("No contact") }}</option>
                        <option
                            dir="auto"
                            v-for="p in contacts"
                            :key="p.id"
                            :value="p.id"
                        >
                            {{ p.name }}
                        </option>
                    </select>
                </label>
                <Link href="/people" class="crm-field-supporting-action">{{
                    t("Create a contact")
                }}</Link>
            </div>
            <label
                ><span class="crm-field-label">{{ t("Company") }}</span
                ><select v-model="form.company_id">
                    <option :value="null">{{ t("No company") }}</option>
                    <option
                        dir="auto"
                        v-for="p in companies"
                        :key="p.id"
                        :value="p.id"
                    >
                        {{ p.name }}
                    </option>
                </select></label
            ><label
                ><span class="crm-field-label">{{
                    t("Enquiry title (optional)")
                }}</span
                ><input v-model="form.title" maxlength="160" /></label
            ><label
                ><span class="crm-field-label">{{ t("Needs") }}</span
                ><textarea v-model="form.needs" maxlength="10000" rows="3" />
            </label>
            <label
                ><span class="crm-field-label"
                    >{{ t("Estimated value (") }}{{ brand.currency }})</span
                ><input
                    v-model="form.estimated_value"
                    type="text"
                    inputmode="decimal"
                    pattern="[0-9]+([.][0-9]{1,2})?"
                    maxlength="15"
            /></label>
            <label
                ><span class="crm-field-label">{{ t("Owner") }}</span
                ><select v-model="form.owner_id">
                    <option
                        v-if="capabilities.includes('leads.assign')"
                        :value="null"
                    >
                        {{ t("Unassigned") }}
                    </option>
                    <option
                        dir="auto"
                        v-for="p in owners"
                        :key="p.id"
                        :value="p.id"
                    >
                        {{ p.name }}
                    </option>
                </select></label
            ><label
                ><span class="crm-field-label">{{ t("Status") }}</span
                ><select v-model="form.status_id">
                    <option v-for="s in statuses" :key="s.id" :value="s.id">
                        {{ s.name }}
                    </option>
                </select></label
            ><label
                ><span class="crm-field-label">{{ t("Source") }}</span
                ><input v-model="form.source" required /></label
            ><label
                ><span class="crm-field-label">{{ t("Priority") }}</span
                ><select v-model="form.priority">
                    <option value="low">{{ t("low") }}</option>
                    <option value="normal">{{ t("normal") }}</option>
                    <option value="high">{{ t("high") }}</option>
                </select></label
            ><label v-for="(field, key) in fields" :key="key"
                ><span class="crm-field-label">{{ field.label }}</span
                ><select
                    v-if="field.type === 'select'"
                    v-model="form.custom_fields[key]"
                >
                    <option v-for="o in field.options" :key="o" :value="o">
                        {{ o }}
                    </option></select
                ><input
                    v-else-if="field.type === 'boolean'"
                    v-model="form.custom_fields[key]"
                    type="checkbox" /><input
                    v-else
                    v-model="form.custom_fields[key]"
                    :type="field.type"
            /></label>
            <details class="crm-form-disclosure">
                <summary>{{ t("Source details") }}</summary>
            </details>
            <div class="crm-form-action">
                <button class="crm-button" :disabled="form.processing">
                    {{ t("Create lead") }}
                </button>
            </div>
        </form>
    </section>
    <div class="crm-tabs md-quick-views" :aria-label="t('Lead quick views')">
        <Link
            href="/leads"
            :aria-current="!filters.view ? 'page' : undefined"
            >{{ t("All leads") }}</Link
        ><Link
            href="/leads?view=missing"
            :aria-current="filters.view === 'missing' ? 'page' : undefined"
            >{{ t("Missing follow-up") }}</Link
        ><Link
            v-if="capabilities.includes('leads.view.all')"
            href="/leads?view=unassigned"
            :aria-current="filters.view === 'unassigned' ? 'page' : undefined"
            >{{ t("Unassigned") }}</Link
        ><Link
            href="/leads?view=archived"
            :aria-current="filters.view === 'archived' ? 'page' : undefined"
            >{{ t("Archived") }}</Link
        >
    </div>
    <DataTable
        :payload="table"
        endpoint="/leads"
        :title="brand.lead_label"
        :base-query="filters"
    >
        <template #cell-proposal="{ row }">
            <Link
                v-if="row.cells.proposal"
                :href="`/leads/${row.id}#proposals`"
                class="md-table-record"
                >{{ row.cells.proposal }}</Link
            >
            <span v-else>—</span>
        </template>
        <template #cell-status="{ row }">
            <StageControl
                v-if="row.status_id && typeof row.version === 'number'"
                :lead-id="row.id"
                :record-name="
                    String(
                        row.cells.contact ??
                            row.cells.company ??
                            row.title ??
                            t('Lead {value0}', {
                                value0: String(row.id ?? ''),
                            }),
                    )
                "
                :status-id="row.status_id"
                :version="row.version"
                :statuses="statuses"
                :editable="row.editable === true"
            />
            <span v-else class="md-cell-chip">{{ row.cells.status }}</span>
        </template>
    </DataTable>
</template>
