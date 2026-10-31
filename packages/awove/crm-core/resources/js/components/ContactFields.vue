<script setup lang="ts">
import { t } from "@/lib/i18n";
import type { ContactFormData, ContactOptions, Person } from "../types";

const props = defineProps<{
    form: ContactFormData;
    options: ContactOptions;
    companies: Person[];
    errors?: Record<string, string | undefined>;
    initialNotes?: boolean;
}>();

function setTime(value: string, checked: boolean) {
    if (value === "anytime") {
        props.form.preferred_contact_times = checked ? ["anytime"] : [];
        return;
    }
    const selected = props.form.preferred_contact_times.filter(
        (time) => time !== "anytime" && time !== value,
    );
    props.form.preferred_contact_times = checked
        ? [...selected, value]
        : selected;
}
function toggleWeekends() {
    const days = props.form.preferred_contact_days;
    const withoutWeekends = days.filter(
        (day) => day !== "saturday" && day !== "sunday",
    );
    props.form.preferred_contact_days =
        days.includes("saturday") && days.includes("sunday")
            ? withoutWeekends
            : [...withoutWeekends, "saturday", "sunday"];
}
function errorFor(field: string) {
    return (
        props.errors?.[field] ??
        Object.entries(props.errors ?? {}).find(([key]) =>
            key.startsWith(`${field}.`),
        )?.[1]
    );
}
</script>

<template>
    <label class="crm-field">
        <span class="crm-field-label">{{ t("Name") }}</span>
        <input
            v-model="form.name"
            required
            maxlength="120"
            autocomplete="name"
            :aria-invalid="!!errorFor('name')"
        />
        <small v-if="errorFor('name')" data-slot="input-error" role="alert">{{
            errorFor("name")
        }}</small>
    </label>
    <label class="crm-field">
        <span class="crm-field-label">{{ t("Email") }}</span>
        <input
            v-model="form.email"
            type="email"
            maxlength="160"
            autocomplete="email"
            :aria-invalid="!!errorFor('email')"
        />
        <small v-if="errorFor('email')" data-slot="input-error" role="alert">{{
            errorFor("email")
        }}</small>
    </label>
    <label class="crm-field">
        <span class="crm-field-label">{{ t("Phone") }}</span>
        <input
            v-model="form.phone"
            type="tel"
            maxlength="40"
            autocomplete="tel"
            :aria-invalid="!!errorFor('phone')"
        />
        <small v-if="errorFor('phone')" data-slot="input-error" role="alert">{{
            errorFor("phone")
        }}</small>
    </label>
    <label class="crm-field">
        <span class="crm-field-label">{{ t("Company") }}</span>
        <select
            v-model="form.company_id"
            :aria-invalid="!!errorFor('company_id')"
        >
            <option :value="null">{{ t("Individual") }}</option>
            <option
                dir="auto"
                v-for="company in companies"
                :key="company.id"
                :value="company.id"
            >
                {{ company.name }}
            </option>
        </select>
        <small
            v-if="errorFor('company_id')"
            data-slot="input-error"
            role="alert"
            >{{ errorFor("company_id") }}</small
        >
    </label>
    <label class="crm-field">
        <span class="crm-field-label">{{ t("Secondary phone") }}</span>
        <input
            v-model="form.secondary_phone"
            type="tel"
            maxlength="40"
            :aria-invalid="!!errorFor('secondary_phone')"
        />
        <small
            v-if="errorFor('secondary_phone')"
            data-slot="input-error"
            role="alert"
            >{{ errorFor("secondary_phone") }}</small
        >
    </label>
    <label class="crm-field">
        <span class="crm-field-label">{{ t("Secondary phone type") }}</span>
        <select
            v-model="form.secondary_phone_label"
            :aria-invalid="!!errorFor('secondary_phone_label')"
        >
            <option value="">{{ t("Not specified") }}</option>
            <option
                v-for="option in options.phone_labels"
                :key="option.value"
                :value="option.value"
            >
                {{ t(option.label) }}
            </option>
        </select>
        <small
            v-if="errorFor('secondary_phone_label')"
            data-slot="input-error"
            role="alert"
            >{{ errorFor("secondary_phone_label") }}</small
        >
    </label>
    <label class="crm-field">
        <span class="crm-field-label">{{ t("Preferred contact method") }}</span>
        <select
            v-model="form.preferred_contact_method"
            :aria-invalid="!!errorFor('preferred_contact_method')"
        >
            <option value="">{{ t("Not specified") }}</option>
            <option
                v-for="option in options.methods"
                :key="option.value"
                :value="option.value"
            >
                {{ t(option.label) }}
            </option>
        </select>
        <small
            v-if="errorFor('preferred_contact_method')"
            data-slot="input-error"
            role="alert"
            >{{ errorFor("preferred_contact_method") }}</small
        >
    </label>
    <label class="crm-field">
        <span class="crm-field-label">{{
            t("How did they hear about us?")
        }}</span>
        <select v-model="form.source" :aria-invalid="!!errorFor('source')">
            <option value="">{{ t("Not specified") }}</option>
            <option
                v-for="option in options.sources"
                :key="option.value"
                :value="option.value"
            >
                {{ t(option.label) }}
            </option>
        </select>
        <small v-if="errorFor('source')" data-slot="input-error" role="alert">{{
            errorFor("source")
        }}</small>
    </label>
    <fieldset class="crm-choice-group crm-form-span">
        <legend>{{ t("Best contact time") }}</legend>
        <div class="crm-choice-options">
            <label
                v-for="option in options.times"
                :key="option.value"
                class="crm-checkbox"
            >
                <input
                    type="checkbox"
                    :value="option.value"
                    :checked="
                        form.preferred_contact_times.includes(option.value)
                    "
                    @change="
                        setTime(
                            option.value,
                            ($event.target as HTMLInputElement).checked,
                        )
                    "
                />
                {{ t(option.label) }}
            </label>
        </div>
        <small class="crm-muted">{{
            t("Choose multiple time windows, or Anytime.")
        }}</small>
        <small
            v-if="errorFor('preferred_contact_times')"
            data-slot="input-error"
            role="alert"
            >{{ errorFor("preferred_contact_times") }}</small
        >
    </fieldset>
    <fieldset class="crm-choice-group crm-form-span">
        <legend>{{ t("Preferred contact days") }}</legend>
        <div class="crm-choice-options">
            <label
                v-for="option in options.days"
                :key="option.value"
                class="crm-checkbox"
            >
                <input
                    v-model="form.preferred_contact_days"
                    type="checkbox"
                    :value="option.value"
                />
                {{ t(option.label) }}
            </label>
            <button
                type="button"
                class="crm-secondary crm-control-sm"
                :aria-pressed="
                    form.preferred_contact_days.includes('saturday') &&
                    form.preferred_contact_days.includes('sunday')
                "
                @click="toggleWeekends"
            >
                {{ t("Weekends") }}
            </button>
        </div>
        <small class="crm-muted">{{
            t(
                "Leave all days unchecked for any day. You can combine weekends with specific days.",
            )
        }}</small>
        <small
            v-if="errorFor('preferred_contact_days')"
            data-slot="input-error"
            role="alert"
            >{{ errorFor("preferred_contact_days") }}</small
        >
    </fieldset>
    <label class="crm-field crm-form-span">
        <span class="crm-field-label">{{ t("Address") }}</span>
        <textarea
            v-model="form.address"
            rows="2"
            maxlength="500"
            autocomplete="street-address"
            :aria-invalid="!!errorFor('address')"
        />
        <small
            v-if="errorFor('address')"
            data-slot="input-error"
            role="alert"
            >{{ errorFor("address") }}</small
        >
    </label>
    <label class="crm-field">
        <span class="crm-field-label">{{ t("City") }}</span>
        <input
            v-model="form.city"
            maxlength="120"
            autocomplete="address-level2"
            :aria-invalid="!!errorFor('city')"
        />
        <small v-if="errorFor('city')" data-slot="input-error" role="alert">{{
            errorFor("city")
        }}</small>
    </label>
    <label class="crm-field">
        <span class="crm-field-label">{{ t("Country") }}</span>
        <input
            v-model="form.country"
            maxlength="120"
            autocomplete="country-name"
            :aria-invalid="!!errorFor('country')"
        />
        <small
            v-if="errorFor('country')"
            data-slot="input-error"
            role="alert"
            >{{ errorFor("country") }}</small
        >
    </label>
    <label v-if="initialNotes" class="crm-field crm-form-span">
        <span class="crm-field-label">{{ t("Notes") }}</span>
        <textarea
            v-model="form.notes"
            rows="3"
            maxlength="10000"
            :placeholder="t('Add background or the first conversation…')"
            :aria-invalid="!!errorFor('notes')"
        />
        <small v-if="errorFor('notes')" data-slot="input-error" role="alert">{{
            errorFor("notes")
        }}</small>
    </label>
</template>
