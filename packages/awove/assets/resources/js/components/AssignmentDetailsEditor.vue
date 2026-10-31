<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { useForm } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import type { AssetRecord, Assignment } from "../types";
import FormErrors from "./FormErrors.vue";
const props = defineProps<{ asset: AssetRecord; assignment: Assignment }>();
function initial() {
    return {
        asset_version: props.asset.version,
        version: props.assignment.version,
        expected_return_on: props.assignment.expected_return_on ?? "",
        notes: props.assignment.notes ?? "",
    };
}
const form = useForm(initial());
const stale = computed(
    () =>
        form.asset_version !== props.asset.version ||
        form.version !== props.assignment.version,
);
function reset() {
    form.defaults(initial());
    form.reset();
    form.clearErrors();
}
watch(
    () => [props.asset.version, props.assignment.version],
    () => {
        if (!form.isDirty) reset();
    },
);
function save() {
    if (stale.value) return;
    form.patch(`/assets/assignments/${props.assignment.id}`, {
        preserveScroll: true,
        onSuccess: reset,
    });
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <label class="crm-field">
            <span class="crm-field-label">{{
                tr("Expected return date")
            }}</span>
            <input
                v-model="form.expected_return_on"
                type="date"
                :min="assignment.assigned_on"
            />
        </label>
        <label class="crm-field crm-form-span">
            <span class="crm-field-label">{{ tr("Assignment notes") }}</span>
            <textarea v-model="form.notes" rows="3" maxlength="10000" />
        </label>
        <p v-if="stale" class="crm-notice crm-form-span" role="alert">
            {{
                tr(
                    "The asset or assignment changed. Reload the latest details before saving.",
                )
            }}
        </p>
        <FormErrors :errors="form.errors" />
        <div class="crm-form-action">
            <button class="crm-button" :disabled="form.processing || stale">
                {{ tr("Save assignment details") }}
            </button>
            <button
                v-if="stale"
                class="crm-secondary"
                type="button"
                @click="reset"
            >
                {{ tr("Reload assignment details") }}
            </button>
        </div>
    </form>
</template>
