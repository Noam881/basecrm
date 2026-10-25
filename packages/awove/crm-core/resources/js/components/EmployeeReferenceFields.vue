<script setup lang="ts">
export interface EmployeeReferenceDraft {
    name: string;
    active: boolean;
    contact_id: number | null;
    user_id: number | null;
    contact_action: string;
    user_action: string;
}
const model = defineModel<EmployeeReferenceDraft>({ required: true });
defineProps<{
    contacts: { id: number; name: string }[];
    accounts: { id: number; name: string }[];
    hiddenContact?: boolean;
    hiddenAccount?: boolean;
    currentContact?: string;
    currentAccount?: string;
    editing?: boolean;
    errors: Record<string, string | undefined>;
}>();
</script>
<template>
    <label class="crm-field">
        <span class="crm-field-label">Employee name</span>
        <input
            v-model="model.name"
            required
            maxlength="180"
            :aria-invalid="!!errors.name"
            autocomplete="off"
        />
        <small v-if="errors.name" data-slot="input-error" role="alert">{{
            errors.name
        }}</small>
    </label>
    <label class="crm-field">
        <span class="crm-field-label">Reference status</span>
        <select v-model="model.active">
            <option :value="true">Active</option>
            <option :value="false">Inactive</option>
        </select>
        <small v-if="errors.active" data-slot="input-error" role="alert">{{
            errors.active
        }}</small>
    </label>
    <label class="crm-field">
        <span class="crm-field-label">Contact link</span>
        <select v-model="model.contact_action" :disabled="hiddenContact">
            <option value="keep">
                {{ editing ? "Keep current contact link" : "No contact link" }}
            </option>
            <option value="set">Choose an existing contact</option>
            <option v-if="editing" value="clear">Remove contact link</option>
        </select>
        <small v-if="hiddenContact" class="crm-muted"
            >The existing contact is unavailable and its link will be
            retained.</small
        >
        <small v-else-if="currentContact" class="crm-muted"
            >Current: {{ currentContact }}</small
        >
    </label>
    <label v-if="model.contact_action === 'set'" class="crm-field">
        <span class="crm-field-label">Existing contact</span>
        <select v-model="model.contact_id" required>
            <option :value="null">Choose contact</option>
            <option
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
    <label class="crm-field">
        <span class="crm-field-label">Login account link</span>
        <select v-model="model.user_action" :disabled="hiddenAccount">
            <option value="keep">
                {{ editing ? "Keep current account link" : "No account link" }}
            </option>
            <option value="set">Choose an existing account</option>
            <option v-if="editing" value="clear">Remove account link</option>
        </select>
        <small v-if="hiddenAccount" class="crm-muted"
            >The existing account is unavailable and its link will be
            retained.</small
        >
        <small v-else-if="currentAccount" class="crm-muted"
            >Current: {{ currentAccount }}</small
        >
    </label>
    <label v-if="model.user_action === 'set'" class="crm-field">
        <span class="crm-field-label">Existing account</span>
        <select v-model="model.user_id" required>
            <option :value="null">Choose account</option>
            <option
                v-for="account in accounts"
                :key="account.id"
                :value="account.id"
            >
                {{ account.name }}
            </option>
        </select>
        <small v-if="errors.user_id" data-slot="input-error" role="alert">{{
            errors.user_id
        }}</small>
    </label>
    <p class="crm-form-span crm-muted">
        A reference can be used without a login account or contact link. Private
        employment details belong in the relevant module.
    </p>
</template>
