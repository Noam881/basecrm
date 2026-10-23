<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from "@inertiajs/vue3";
import { ref } from "vue";
import type { Lead, Page, Person, Stage, Field } from "../../types";
import DataTable from "../../components/table/DataTable.vue";
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
const brand = usePage().props.brand as { lead_label: string };
const capabilities = usePage().props.capabilities as string[];
const showCreate = ref(false);
const form = useForm({
    title: "",
    contact_id: null as number | null,
    company_id: null as number | null,
    owner_id: props.owners[0]?.id ?? null,
    status_id: props.statuses[0]?.id ?? "new",
    source: "Direct",
    priority: "normal",
    custom_fields: {} as Record<string, string | number | boolean>,
});
</script>
<template>
    <Head :title="brand.lead_label" />
    <div class="crm-heading">
        <h1>{{ brand.lead_label }}</h1>
        <button
            v-if="capabilities.includes('leads.create')"
            class="crm-button"
            @click="showCreate = !showCreate"
        >
            {{ showCreate ? "Close form" : "+ Create lead" }}
        </button>
    </div>
    <section v-if="showCreate" class="crm-card">
        <h2>New lead</h2>
        <p v-if="!contacts.length && !companies.length">
            Start by <Link href="/people">creating a contact or company</Link>.
        </p>
        <form class="crm-form-grid" @submit.prevent="form.post('/leads')">
            <label
                ><span class="crm-field-label">Contact</span
                ><select v-model="form.contact_id">
                    <option :value="null">No contact</option>
                    <option v-for="p in contacts" :key="p.id" :value="p.id">
                        {{ p.name }}
                    </option>
                </select></label
            ><label
                ><span class="crm-field-label">Company</span
                ><select v-model="form.company_id">
                    <option :value="null">No company</option>
                    <option v-for="p in companies" :key="p.id" :value="p.id">
                        {{ p.name }}
                    </option>
                </select></label
            ><label
                ><span class="crm-field-label">Enquiry title (optional)</span
                ><input v-model="form.title" maxlength="160" /></label
            ><label
                ><span class="crm-field-label">Owner</span
                ><select v-model="form.owner_id">
                    <option
                        v-if="capabilities.includes('leads.assign')"
                        :value="null"
                    >
                        Unassigned
                    </option>
                    <option v-for="p in owners" :key="p.id" :value="p.id">
                        {{ p.name }}
                    </option>
                </select></label
            ><label
                ><span class="crm-field-label">Status</span
                ><select v-model="form.status_id">
                    <option v-for="s in statuses" :key="s.id" :value="s.id">
                        {{ s.name }}
                    </option>
                </select></label
            ><label
                ><span class="crm-field-label">Source</span
                ><input v-model="form.source" required /></label
            ><label
                ><span class="crm-field-label">Priority</span
                ><select v-model="form.priority">
                    <option>low</option>
                    <option>normal</option>
                    <option>high</option>
                </select></label
            ><label v-for="(field, key) in fields" :key="key"
                ><span class="crm-field-label">{{ field.label }}</span
                ><select
                    v-if="field.type === 'select'"
                    v-model="form.custom_fields[key]"
                >
                    <option v-for="o in field.options" :key="o">
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
            <div class="crm-form-action">
                <button class="crm-button" :disabled="form.processing">
                    Create lead
                </button>
            </div>
        </form>
    </section>
    <div class="crm-tabs md-quick-views" aria-label="Lead quick views">
        <Link href="/leads">All leads</Link
        ><Link href="/leads?view=missing">Missing follow-up</Link
        ><Link
            v-if="capabilities.includes('leads.view.all')"
            href="/leads?view=unassigned"
            >Unassigned</Link
        ><Link href="/leads?view=archived">Archived</Link>
    </div>
    <DataTable
        :payload="table"
        endpoint="/leads"
        :title="brand.lead_label"
        :base-query="filters"
    />
</template>
