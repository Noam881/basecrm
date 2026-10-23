<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import type { Person } from "../../types";
const props = defineProps<{
    identity: Person;
    type: "contacts" | "companies";
    editable: boolean;
    companyOptions: Person[];
    returnTo: string;
    saveUrl: string;
}>();
const label = props.type === "contacts" ? "contact" : "company";
const form = useForm({
    name: props.identity.name,
    email: props.identity.email ?? "",
    phone: props.identity.phone ?? "",
    ...(props.type === "contacts"
        ? { company_id: props.identity.company_id ?? null }
        : {}),
    accept_duplicate: false,
});
</script>
<template>
    <Head :title="identity.name" />
    <Link :href="returnTo" class="crm-back">← Back</Link>
    <div class="crm-heading">
        <h1>{{ identity.name }}</h1>
    </div>
    <section class="crm-card crm-flow">
        <h2>
            {{ type === "contacts" ? "Contact details" : "Company details" }}
        </h2>
        <p v-if="!editable" class="crm-muted">
            Your account has view access to this record.
        </p>
        <form class="crm-form-grid" @submit.prevent="form.patch(saveUrl)">
            <label
                ><span class="crm-field-label">Name</span
                ><input
                    v-model="form.name"
                    :disabled="!editable"
                    required
                    maxlength="120"
            /></label>
            <label
                ><span class="crm-field-label">Email</span
                ><input
                    v-model="form.email"
                    :disabled="!editable"
                    type="email"
                    maxlength="160"
            /></label>
            <label
                ><span class="crm-field-label">Phone</span
                ><input
                    v-model="form.phone"
                    :disabled="!editable"
                    type="tel"
                    maxlength="40"
            /></label>
            <label v-if="type === 'contacts'"
                ><span class="crm-field-label">Company</span
                ><select v-model="form.company_id" :disabled="!editable">
                    <option :value="null">Individual</option>
                    <option
                        v-for="company in companyOptions"
                        :key="company.id"
                        :value="company.id"
                    >
                        {{ company.name }}
                    </option>
                </select></label
            >
            <label v-if="editable" class="crm-checkbox"
                ><input v-model="form.accept_duplicate" type="checkbox" />Keep a
                separate record if a duplicate is found</label
            >
            <div class="crm-form-action">
                <button
                    v-if="editable"
                    class="crm-button"
                    :disabled="form.processing"
                >
                    Save changes</button
                ><Link :href="returnTo" class="crm-secondary">{{
                    editable ? "Cancel" : "Back to records"
                }}</Link>
            </div>
        </form>
    </section>
</template>
