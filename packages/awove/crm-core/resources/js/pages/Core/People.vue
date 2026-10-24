<script setup lang="ts">
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import { contactFormData, type Person, type ContactOptions } from "../../types";
import ContactFields from "../../components/ContactFields.vue";
const props = defineProps<{
    recordType: "contacts" | "companies";
    companyOptions: Person[];
    contactOptions: ContactOptions;
}>();
const capabilities = usePage().props.capabilities as string[];
const type = ref(props.recordType);
const title = computed(() =>
    type.value === "contacts" ? "Add new contact" : "Add new company",
);
const form = useForm({
    ...contactFormData(),
    notes: "",
    accept_duplicate: false,
});
watch(type, () => form.clearErrors());
function createCustomer() {
    form.transform((data) =>
        type.value === "contacts"
            ? data
            : {
                  name: data.name,
                  email: data.email,
                  phone: data.phone,
                  notes: data.notes,
                  accept_duplicate: data.accept_duplicate,
              },
    ).post(`/people/${type.value}`, { onSuccess: () => form.reset() });
}
</script>
<template>
    <Head :title="title" />
    <Link href="/leads" class="crm-back">← Back to leads</Link>
    <div class="crm-heading">
        <h1>{{ title }}</h1>
    </div>
    <section
        v-if="capabilities.includes('leads.create')"
        class="crm-card crm-flow"
    >
        <h2>
            {{ type === "contacts" ? "Contact details" : "Company details" }}
        </h2>
        <form class="crm-form-grid" @submit.prevent="createCustomer">
            <label class="crm-field crm-form-span">
                <span class="crm-field-label">Record type</span>
                <select v-model="type">
                    <option value="contacts">Contact</option>
                    <option value="companies">Company</option>
                </select>
            </label>
            <ContactFields
                v-if="type === 'contacts'"
                :form="form"
                :options="contactOptions"
                :companies="companyOptions"
                :errors="form.errors"
            />
            <template v-else>
                <label class="crm-field">
                    <span class="crm-field-label">Name</span>
                    <input
                        v-model="form.name"
                        required
                        maxlength="120"
                        autocomplete="organization"
                        :aria-invalid="!!form.errors.name"
                    />
                    <small
                        v-if="form.errors.name"
                        data-slot="input-error"
                        role="alert"
                        >{{ form.errors.name }}</small
                    >
                </label>
                <label class="crm-field">
                    <span class="crm-field-label">Email</span>
                    <input
                        v-model="form.email"
                        type="email"
                        maxlength="160"
                        autocomplete="email"
                        :aria-invalid="!!form.errors.email"
                    />
                    <small
                        v-if="form.errors.email"
                        data-slot="input-error"
                        role="alert"
                        >{{ form.errors.email }}</small
                    >
                </label>
                <label class="crm-field">
                    <span class="crm-field-label">Phone</span>
                    <input
                        v-model="form.phone"
                        type="tel"
                        maxlength="40"
                        autocomplete="tel"
                        :aria-invalid="!!form.errors.phone"
                    />
                    <small
                        v-if="form.errors.phone"
                        data-slot="input-error"
                        role="alert"
                        >{{ form.errors.phone }}</small
                    >
                </label>
            </template>
            <label class="crm-field crm-form-span">
                <span class="crm-field-label">Notes</span>
                <textarea
                    v-model="form.notes"
                    rows="3"
                    maxlength="10000"
                    placeholder="Add background or the first conversation…"
                    :aria-invalid="!!form.errors.notes"
                />
                <small
                    v-if="form.errors.notes"
                    data-slot="input-error"
                    role="alert"
                    >{{ form.errors.notes }}</small
                >
            </label>
            <label class="crm-checkbox crm-form-span">
                <input v-model="form.accept_duplicate" type="checkbox" />
                Keep a separate record if a duplicate is found
            </label>
            <div class="crm-form-action">
                <button class="crm-button" :disabled="form.processing">
                    {{
                        type === "contacts"
                            ? "Create contact"
                            : "Create company"
                    }}
                </button>
                <Link href="/leads" class="crm-secondary">Cancel</Link>
            </div>
        </form>
    </section>
</template>
