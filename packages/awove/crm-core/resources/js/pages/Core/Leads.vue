<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from "@inertiajs/vue3";
import { ref } from "vue";
import type { Lead, Page, Person, Stage, Field } from "../../types";
import Pagination from "../../components/Pagination.vue";
const props = defineProps<{
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
const filters = ref({ ...props.filters });
const apply = () =>
    router.get("/leads", filters.value, { preserveState: true });
const columns = ref(
    props.preferences.columns ?? [
        "title",
        "contact",
        "status",
        "owner",
        "priority",
        "next_action_at",
    ],
);
const available = [
    "title",
    "contact",
    "status",
    "owner",
    "priority",
    "source",
    "next_action_at",
];
const saveColumns = () =>
    router.post(
        "/preferences",
        { columns: columns.value },
        { preserveScroll: true },
    );
function move(column: string, delta: number) {
    const i = columns.value.indexOf(column);
    const j = i + delta;
    if (j < 0 || j >= columns.value.length) return;
    [columns.value[i], columns.value[j]] = [columns.value[j], columns.value[i]];
    saveColumns();
}
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
        <div>
            <p class="crm-eyebrow">BUILD LASTING RELATIONSHIPS</p>
            <h1>{{ brand.lead_label }}</h1>
            <p>Every conversation has a next step.</p>
        </div>
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
                ><span class="crm-field-label">Title</span
                ><input v-model="form.title" required maxlength="160" /></label
            ><label
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
    <section class="crm-card">
        <form class="crm-filters" @submit.prevent="apply">
            <label
                ><span class="crm-field-label">Search</span
                ><input
                    v-model="filters.q"
                    placeholder="Search lead titles" /></label
            ><label
                ><span class="crm-field-label">Status</span
                ><select v-model="filters.status" @change="apply">
                    <option value="">All stages</option>
                    <option v-for="s in statuses" :key="s.id" :value="s.id">
                        {{ s.name }}
                    </option>
                </select></label
            ><label
                ><span class="crm-field-label">Owner</span
                ><select v-model="filters.owner" @change="apply">
                    <option value="">All available</option>
                    <option v-for="o in owners" :key="o.id" :value="o.id">
                        {{ o.name }}
                    </option>
                </select></label
            ><label
                ><span class="crm-field-label">Priority</span
                ><select v-model="filters.priority" @change="apply">
                    <option value="">All priorities</option>
                    <option>low</option>
                    <option>normal</option>
                    <option>high</option>
                </select></label
            ><label
                ><span class="crm-field-label">Source</span
                ><input
                    v-model="filters.source"
                    placeholder="Any source" /></label
            ><label
                ><span class="crm-field-label">Sort</span
                ><select v-model="filters.sort" @change="apply">
                    <option value="">Newest</option>
                    <option value="title">Title</option>
                    <option value="updated_at">Last updated</option>
                    <option value="priority">Priority</option>
                </select></label
            ><label
                ><span class="crm-field-label">Direction</span
                ><select v-model="filters.direction" @change="apply">
                    <option value="desc">Descending</option>
                    <option value="asc">Ascending</option>
                </select></label
            ><button class="crm-secondary">Apply</button>
        </form>
        <div class="crm-tabs">
            <Link href="/leads">All leads</Link
            ><Link href="/leads?view=missing">Missing follow-up</Link
            ><Link
                v-if="capabilities.includes('leads.view.all')"
                href="/leads?view=unassigned"
                >Unassigned</Link
            ><Link href="/leads?view=archived">Archived</Link>
        </div>
        <details class="crm-columns">
            <summary>Customize columns</summary>
            <div v-for="column in available" :key="column">
                <label
                    ><input
                        v-model="columns"
                        type="checkbox"
                        :value="column"
                        @change="saveColumns"
                    />{{ column.replaceAll("_", " ") }}</label
                ><button
                    v-if="columns.includes(column)"
                    :aria-label="`Move ${column} left`"
                    @click="move(column, -1)"
                >
                    ←</button
                ><button
                    v-if="columns.includes(column)"
                    :aria-label="`Move ${column} right`"
                    @click="move(column, 1)"
                >
                    →
                </button>
            </div>
        </details>
        <div v-if="!leads.data.length" class="crm-empty">
            <span class="crm-empty-icon">↗</span>
            <h3>A fresh start.</h3>
            <p>
                No leads match this view. Create a lead or adjust your filters.
            </p>
        </div>
        <div v-else class="crm-table-scroll">
            <table>
                <thead>
                    <tr>
                        <th v-for="column in columns" :key="column">
                            {{ column.replaceAll("_", " ") }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="lead in leads.data" :key="lead.id">
                        <td v-for="column in columns" :key="column">
                            <Link
                                v-if="column === 'title'"
                                :href="`/leads/${lead.id}`"
                                ><strong>{{ lead.title }}</strong></Link
                            ><span
                                v-else-if="column === 'status'"
                                class="crm-badge"
                                >{{ lead.status.name }}</span
                            ><span v-else-if="column === 'owner'">{{
                                lead.owner?.name ?? "Unassigned"
                            }}</span
                            ><span v-else-if="column === 'contact'">{{
                                lead.contact?.name ?? "—"
                            }}</span
                            ><span v-else-if="column === 'next_action_at'">{{
                                lead.next_action_at
                                    ? new Date(
                                          lead.next_action_at,
                                      ).toLocaleDateString()
                                    : "No follow-up"
                            }}</span
                            ><span v-else>{{
                                lead[column as "priority" | "source"]
                            }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :page="leads" />
    </section>
</template>
