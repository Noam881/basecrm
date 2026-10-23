<script setup lang="ts">
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import { ref } from "vue";
import type { Person, Page } from "../../types";
import DataTable from "../../components/table/DataTable.vue";
import type { TablePayload } from "../../components/table/types";
const props = defineProps<{
    table: TablePayload;
    companyOptions: Person[];
    contacts: Page<Person>;
    companies: Page<Person>;
    q: string;
}>();
const capabilities = usePage().props.capabilities as string[];
const type = ref(props.table.id);
const form = useForm({
    name: "",
    email: "",
    phone: "",
    company_id: null as number | null,
    accept_duplicate: false,
});
function createCustomer() {
    form.transform(({ company_id, ...data }) =>
        type.value === "contacts" ? { ...data, company_id } : data,
    ).post(`/people/${type.value}`, { onSuccess: () => form.reset() });
}
</script>
<template>
    <Head title="Contacts & companies" />
    <div class="crm-heading">
        <h1>Contacts & companies</h1>
    </div>
    <section v-if="capabilities.includes('leads.create')" class="crm-card">
        <h2>Add a customer</h2>
        <form class="crm-form-grid" @submit.prevent="createCustomer">
            <label
                ><span class="crm-field-label">Record type</span
                ><select v-model="type">
                    <option value="contacts">Contact</option>
                    <option value="companies">Company</option>
                </select></label
            ><label
                ><span class="crm-field-label">Name</span
                ><input v-model="form.name" required /></label
            ><label
                ><span class="crm-field-label">Email</span
                ><input v-model="form.email" type="email" /></label
            ><label
                ><span class="crm-field-label">Phone</span
                ><input v-model="form.phone" type="tel" /></label
            ><label v-if="type === 'contacts'"
                ><span class="crm-field-label">Company</span
                ><select v-model="form.company_id">
                    <option :value="null">Individual</option>
                    <option
                        v-for="c in companyOptions"
                        :key="c.id"
                        :value="c.id"
                    >
                        {{ c.name }}
                    </option>
                </select></label
            ><label class="crm-checkbox"
                ><input v-model="form.accept_duplicate" type="checkbox" />Keep a
                separate record if a duplicate is found</label
            >
            <div class="crm-form-action">
                <button class="crm-button" :disabled="form.processing">
                    Create customer
                </button>
            </div>
        </form>
    </section>
    <div class="crm-tabs md-quick-views">
        <Link href="/people?table=contacts">Contacts</Link
        ><Link href="/people?table=companies">Companies</Link>
    </div>
    <DataTable
        :payload="table"
        endpoint="/people"
        :title="table.id === 'contacts' ? 'Contacts' : 'Companies'"
        :base-query="{ table: table.id }"
    />
</template>
