<script setup lang="ts">
import type { LeadAttribution } from "../types";

const props = defineProps<{
    modelValue: LeadAttribution;
    disabled?: boolean;
}>();
const emit = defineEmits<{ "update:modelValue": [value: LeadAttribution] }>();
const fields: { key: keyof LeadAttribution; label: string; type?: string }[] = [
    { key: "landing_page_url", label: "Website / landing page", type: "url" },
    { key: "referrer_name", label: "Referred by" },
    { key: "campaign_name", label: "Campaign name" },
    { key: "campaign_id", label: "Campaign ID" },
    { key: "ad_name", label: "Ad name" },
    { key: "ad_id", label: "Ad ID" },
    { key: "utm_source", label: "UTM source" },
    { key: "utm_medium", label: "UTM medium" },
    { key: "utm_campaign", label: "UTM campaign" },
    { key: "utm_content", label: "UTM content" },
];
const platforms = [
    ["website", "Website"],
    ["facebook", "Facebook"],
    ["instagram", "Instagram"],
    ["linkedin", "LinkedIn"],
    ["google", "Google"],
    ["referral", "Referral"],
    ["direct", "Direct"],
    ["other", "Other"],
];
function change(key: keyof LeadAttribution, event: Event) {
    emit("update:modelValue", {
        ...props.modelValue,
        [key]: (event.target as HTMLInputElement).value,
    });
}
</script>

<template>
    <div class="crm-form-grid">
        <label class="crm-field">
            <span class="crm-field-label">Media / platform</span>
            <select
                :value="modelValue.platform ?? ''"
                :disabled="disabled"
                @change="change('platform', $event)"
            >
                <option value="">Not recorded</option>
                <option
                    v-for="[value, label] in platforms"
                    :key="value"
                    :value="value"
                >
                    {{ label }}
                </option>
            </select>
        </label>
        <label v-for="field in fields" :key="field.key" class="crm-field">
            <span class="crm-field-label">{{ field.label }}</span>
            <input
                :type="field.type ?? 'text'"
                :value="modelValue[field.key] ?? ''"
                :disabled="disabled"
                :maxlength="field.type === 'url' ? 2048 : 200"
                @input="change(field.key, $event)"
            />
        </label>
    </div>
</template>
