<script setup lang="ts">
import { t } from "@/lib/i18n";
import { computed } from "vue";
import type { LeadLifecycleFormData } from "../types";

defineProps<{
    form: LeadLifecycleFormData;
    errors: Partial<Record<keyof LeadLifecycleFormData, string>>;
    disabled?: boolean;
}>();
const dates = computed(
    () =>
        [
            { key: "contract_sent_on", label: t("Contract sent date") },
            { key: "invoice_sent_on", label: t("Invoice sent date") },
            { key: "payment_received_on", label: t("Payment received date") },
            { key: "process_started_on", label: t("Process start date") },
        ] as const,
);
</script>
<template>
    <label class="crm-field">
        <span class="crm-field-label">{{ t("Business sector") }}</span>
        <input
            v-model="form.business_sector"
            maxlength="120"
            :disabled="disabled"
            :aria-invalid="!!errors.business_sector"
        />
        <small
            v-if="errors.business_sector"
            data-slot="input-error"
            role="alert"
            >{{ errors.business_sector }}</small
        >
    </label>
    <label class="crm-field">
        <span class="crm-field-label">{{ t("Product sold") }}</span>
        <input
            v-model="form.product_sold"
            maxlength="1000"
            :disabled="disabled"
            :aria-invalid="!!errors.product_sold"
        />
        <small
            v-if="errors.product_sold"
            data-slot="input-error"
            role="alert"
            >{{ errors.product_sold }}</small
        >
    </label>
    <label v-for="field in dates" :key="field.key" class="crm-field">
        <span class="crm-field-label">{{ field.label }}</span>
        <input
            v-model="form[field.key]"
            type="date"
            :disabled="disabled"
            :aria-invalid="!!errors[field.key]"
        />
        <small v-if="errors[field.key]" data-slot="input-error" role="alert">{{
            errors[field.key]
        }}</small>
    </label>
    <label class="crm-field">
        <span class="crm-field-label">{{ t("Contract link") }}</span>
        <input
            v-model="form.contract_url"
            type="url"
            maxlength="2048"
            dir="ltr"
            :disabled="disabled"
            :aria-invalid="!!errors.contract_url"
            placeholder="https://"
        />
        <small
            v-if="errors.contract_url"
            data-slot="input-error"
            role="alert"
            >{{ errors.contract_url }}</small
        >
    </label>
    <label class="crm-field">
        <span class="crm-field-label">{{ t("Privacy policy accepted") }}</span>
        <select
            v-model="form.privacy_policy_accepted"
            :disabled="disabled"
            :aria-invalid="!!errors.privacy_policy_accepted"
        >
            <option :value="null">{{ t("Not recorded") }}</option>
            <option :value="true">{{ t("Yes") }}</option>
            <option :value="false">{{ t("No") }}</option>
        </select>
        <small
            v-if="errors.privacy_policy_accepted"
            data-slot="input-error"
            role="alert"
            >{{ errors.privacy_policy_accepted }}</small
        >
    </label>
</template>
