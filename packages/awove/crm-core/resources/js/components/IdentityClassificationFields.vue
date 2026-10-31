<script setup lang="ts">
import { t } from "@/lib/i18n";
import { computed, ref, watch } from "vue";

export interface IdentityClassificationDraft {
    contact_id: number | null;
    company_id: number | null;
    role: string;
    responsible_id: number | null;
    main_contact_id: number | null;
    active: boolean;
    notes: string;
}
const model = defineModel<IdentityClassificationDraft>({ required: true });
const props = defineProps<{
    contacts: { id: number; name: string; company_id?: number | null }[];
    companies: { id: number; name: string }[];
    owners: { id: number; name: string }[];
    canAssign: boolean;
    lockedIdentity?: boolean;
    roles?: string[];
    errors: Record<string, string | undefined>;
}>();
const kind = ref(model.value.company_id ? "company" : "contact");
const labels: Record<string, string> = {
    customer: t("Customer"),
    supplier: t("Supplier"),
    donor: t("Donor"),
    partner: t("Partner"),
    prospect: t("Prospect"),
};
const roleOptions = computed(() =>
    (props.roles ?? Object.keys(labels)).map((key) => ({
        key,
        label: labels[key] ?? key,
    })),
);
const companyContacts = computed(() =>
    props.contacts.filter(
        (contact) => contact.company_id === model.value.company_id,
    ),
);
watch(kind, () => {
    if (props.lockedIdentity) return;
    model.value.contact_id = null;
    model.value.company_id = null;
    model.value.main_contact_id = null;
});
watch(
    () => model.value.company_id,
    () => {
        if (!props.lockedIdentity) model.value.main_contact_id = null;
    },
);
</script>

<template>
    <label class="crm-field">
        <span class="crm-field-label">{{ t("Identity type") }}</span>
        <select v-model="kind" :disabled="lockedIdentity">
            <option value="contact">{{ t("Contact") }}</option>
            <option value="company">{{ t("Company") }}</option>
        </select>
    </label>
    <label v-if="kind === 'contact'" class="crm-field">
        <span class="crm-field-label">{{ t("Contact") }}</span>
        <select v-model="model.contact_id" required :disabled="lockedIdentity">
            <option :value="null">{{ t("Choose a contact") }}</option>
            <option
                dir="auto"
                v-for="contact in contacts"
                :key="contact.id"
                :value="contact.id"
            >
                {{ contact.name }}
            </option>
        </select>
        <small v-if="errors.contact_id" data-slot="input-error" role="alert">{{
            errors.contact_id
        }}</small>
    </label>
    <label v-else class="crm-field">
        <span class="crm-field-label">{{ t("Company") }}</span>
        <select v-model="model.company_id" required :disabled="lockedIdentity">
            <option :value="null">{{ t("Choose a company") }}</option>
            <option
                dir="auto"
                v-for="company in companies"
                :key="company.id"
                :value="company.id"
            >
                {{ company.name }}
            </option>
        </select>
        <small v-if="errors.company_id" data-slot="input-error" role="alert">{{
            errors.company_id
        }}</small>
    </label>
    <label class="crm-field">
        <span class="crm-field-label">{{ t("Classification") }}</span>
        <select v-model="model.role" required :disabled="lockedIdentity">
            <option
                v-for="role in roleOptions"
                :key="role.key"
                :value="role.key"
            >
                {{ role.label }}
            </option>
        </select>
        <small v-if="errors.role" data-slot="input-error" role="alert">{{
            errors.role
        }}</small>
    </label>
    <label class="crm-field">
        <span class="crm-field-label">{{ t("Responsible person") }}</span>
        <select v-model="model.responsible_id" :disabled="!canAssign">
            <option :value="null">{{ t("Unassigned") }}</option>
            <option
                dir="auto"
                v-for="owner in owners"
                :key="owner.id"
                :value="owner.id"
            >
                {{ owner.name }}
            </option>
        </select>
        <small
            v-if="errors.responsible_id"
            data-slot="input-error"
            role="alert"
            >{{ errors.responsible_id }}</small
        >
    </label>
    <label v-if="kind === 'company'" class="crm-field">
        <span class="crm-field-label">{{ t("Main contact") }}</span>
        <select v-model="model.main_contact_id">
            <option :value="null">{{ t("No main contact") }}</option>
            <option
                dir="auto"
                v-for="contact in companyContacts"
                :key="contact.id"
                :value="contact.id"
            >
                {{ contact.name }}
            </option>
        </select>
        <small
            v-if="errors.main_contact_id"
            data-slot="input-error"
            role="alert"
            >{{ errors.main_contact_id }}</small
        >
    </label>
    <label class="crm-field">
        <span class="crm-field-label">{{ t("Relationship status") }}</span>
        <select v-model="model.active">
            <option :value="true">{{ t("Active") }}</option>
            <option :value="false">{{ t("Inactive") }}</option>
        </select>
    </label>
    <label class="crm-field crm-form-span">
        <span class="crm-field-label">{{ t("Relationship notes") }}</span>
        <textarea v-model="model.notes" rows="3" maxlength="10000" />
        <small v-if="errors.notes" data-slot="input-error" role="alert">{{
            errors.notes
        }}</small>
    </label>
</template>
