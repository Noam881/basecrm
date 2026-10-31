<script setup lang="ts">
import { t } from "@/lib/i18n";
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
        <span class="crm-field-label">{{ t("Employee name") }}</span>
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
        <span class="crm-field-label">{{ t("Reference status") }}</span>
        <select v-model="model.active">
            <option :value="true">{{ t("Active") }}</option>
            <option :value="false">{{ t("Inactive") }}</option>
        </select>
        <small v-if="errors.active" data-slot="input-error" role="alert">{{
            errors.active
        }}</small>
    </label>
    <label class="crm-field">
        <span class="crm-field-label">{{ t("Contact link") }}</span>
        <select v-model="model.contact_action" :disabled="hiddenContact">
            <option value="keep">
                {{
                    editing
                        ? t("Keep current contact link")
                        : t("No contact link")
                }}
            </option>
            <option value="set">{{ t("Choose an existing contact") }}</option>
            <option v-if="editing" value="clear">
                {{ t("Remove contact link") }}
            </option>
        </select>
        <small v-if="hiddenContact" class="crm-muted">{{
            t(
                "The existing contact is unavailable and its link will be retained.",
            )
        }}</small>
        <small v-else-if="currentContact" class="crm-muted"
            >{{ t("Current:") }} {{ currentContact }}</small
        >
    </label>
    <label v-if="model.contact_action === 'set'" class="crm-field">
        <span class="crm-field-label">{{ t("Existing contact") }}</span>
        <select v-model="model.contact_id" required>
            <option :value="null">{{ t("Choose contact") }}</option>
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
    <label class="crm-field">
        <span class="crm-field-label">{{ t("Login account link") }}</span>
        <select v-model="model.user_action" :disabled="hiddenAccount">
            <option value="keep">
                {{
                    editing
                        ? t("Keep current account link")
                        : t("No account link")
                }}
            </option>
            <option value="set">{{ t("Choose an existing account") }}</option>
            <option v-if="editing" value="clear">
                {{ t("Remove account link") }}
            </option>
        </select>
        <small v-if="hiddenAccount" class="crm-muted">{{
            t(
                "The existing account is unavailable and its link will be retained.",
            )
        }}</small>
        <small v-else-if="currentAccount" class="crm-muted"
            >{{ t("Current:") }} {{ currentAccount }}</small
        >
    </label>
    <label v-if="model.user_action === 'set'" class="crm-field">
        <span class="crm-field-label">{{ t("Existing account") }}</span>
        <select v-model="model.user_id" required>
            <option :value="null">{{ t("Choose account") }}</option>
            <option
                dir="auto"
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
        {{
            t(
                "A reference can be used without a login account or contact link. Private employment details belong in the relevant module.",
            )
        }}
    </p>
</template>
