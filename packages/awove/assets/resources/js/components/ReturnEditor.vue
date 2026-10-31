<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import type { AssetRecord, Assignment } from "../types";
import FormErrors from "./FormErrors.vue";
defineOptions({ inheritAttrs: false });
const p = defineProps<{
    asset: AssetRecord;
    assignment: Assignment;
    today: string;
}>();
const f = useForm({
    asset_version: p.asset.version,
    version: p.assignment.version,
    returned_on: p.today,
    return_condition: "",
});
const stale = computed(
    () =>
        f.asset_version !== p.asset.version ||
        f.version !== p.assignment.version,
);
watch(
    () => [p.asset.version, p.assignment.version],
    () => {
        if (!f.isDirty) {
            f.defaults({
                asset_version: p.asset.version,
                version: p.assignment.version,
            });
            f.reset("asset_version", "version");
        }
    },
);
function reset() {
    f.defaults({
        asset_version: p.asset.version,
        version: p.assignment.version,
        returned_on: p.today,
        return_condition: "",
    });
    f.reset();
    f.clearErrors();
}
function save() {
    if (stale.value) return;
    f.post(`/assets/assignments/${p.assignment.id}/return`, {
        preserveScroll: true,
        onSuccess: reset,
    });
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <label class="crm-field"
            ><span class="crm-field-label">Actual return date</span
            ><input
                v-model="f.returned_on"
                type="date"
                required
                :min="assignment.assigned_on"
                :max="today" /></label
        ><label class="crm-field crm-form-span"
            ><span class="crm-field-label">Condition on return</span
            ><textarea
                v-model="f.return_condition"
                required
                rows="3"
                maxlength="10000"
            />
        </label>
        <p class="crm-muted crm-form-span">
            Recording a return closes this assignment. Maintenance still in
            progress keeps the asset under maintenance.
        </p>
        <p v-if="stale" class="crm-notice crm-form-span" role="alert">
            The asset or assignment changed. Reload this form before recording
            the return.
        </p>
        <FormErrors :errors="f.errors" />
        <div class="crm-form-action">
            <button class="crm-button" :disabled="f.processing || stale">
                Record return</button
            ><button
                v-if="stale"
                class="crm-secondary"
                type="button"
                @click="reset"
            >
                Reload return form
            </button>
        </div>
    </form>
</template>
