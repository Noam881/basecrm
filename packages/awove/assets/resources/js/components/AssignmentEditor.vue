<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import EmployeeReferenceSelect from "../../../../crm-core/resources/js/components/EmployeeReferenceSelect.vue";
import type { AssetRecord, Options } from "../types";
import FormErrors from "./FormErrors.vue";
defineOptions({ inheritAttrs: false });
const p = defineProps<Options & { asset: AssetRecord }>();
const f = useForm({
    asset_version: p.asset.version,
    target_type: "employee",
    employee_reference_id: null as number | null,
    customer_relationship_id: null as number | null,
    location: "",
    assigned_on: p.today,
    expected_return_on: "",
    notes: "",
});
const stale = computed(() => f.asset_version !== p.asset.version);
watch(
    () => p.asset.version,
    () => {
        if (!f.isDirty) {
            f.defaults("asset_version", p.asset.version);
            f.reset("asset_version");
        }
    },
);
watch(
    () => f.target_type,
    () => {
        f.employee_reference_id = null;
        f.customer_relationship_id = null;
        f.location = "";
    },
);
function save() {
    if (stale.value) return;
    f.post(`/assets/register/${p.asset.id}/assignments`, {
        preserveScroll: true,
        onSuccess: () => {
            f.defaults("asset_version", p.asset.version);
            f.reset();
        },
    });
}
function reload() {
    f.defaults("asset_version", p.asset.version);
    f.reset();
    f.clearErrors();
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <label class="crm-field"
            ><span class="crm-field-label">Assignment target</span
            ><select v-model="f.target_type" aria-label="Assignment target">
                <option value="employee">Employee</option>
                <option value="customer">Customer</option>
                <option value="location">Location</option>
            </select></label
        ><EmployeeReferenceSelect
            v-if="f.target_type === 'employee'"
            v-model="f.employee_reference_id"
            :options="employees"
            :can-manage="canManageEmployees"
            :error="f.errors.employee_reference_id"
        /><label v-else-if="f.target_type === 'customer'" class="crm-field"
            ><span class="crm-field-label">Customer</span
            ><select
                v-model="f.customer_relationship_id"
                required
                aria-label="Customer"
            >
                <option :value="null">Choose a customer</option>
                <option v-for="c in customers" :key="c.id" :value="c.id">
                    {{ c.name }}
                </option>
            </select></label
        ><label v-else class="crm-field"
            ><span class="crm-field-label">Assignment location</span
            ><input v-model="f.location" required maxlength="180" /></label
        ><label class="crm-field"
            ><span class="crm-field-label">Assignment date</span
            ><input
                v-model="f.assigned_on"
                type="date"
                required
                :max="today" /></label
        ><label class="crm-field"
            ><span class="crm-field-label">Expected return date</span
            ><input
                v-model="f.expected_return_on"
                type="date"
                :min="f.assigned_on || undefined" /></label
        ><label class="crm-field crm-form-span"
            ><span class="crm-field-label">Assignment notes</span
            ><textarea v-model="f.notes" rows="3" maxlength="10000" />
        </label>
        <p v-if="stale" class="crm-notice crm-form-span" role="alert">
            The asset changed. Reload the assignment form before saving.
        </p>
        <FormErrors :errors="f.errors" />
        <div class="crm-form-action">
            <button class="crm-button" :disabled="f.processing || stale">
                Assign asset</button
            ><button
                v-if="stale"
                class="crm-secondary"
                type="button"
                @click="reload"
            >
                Reload assignment form
            </button>
        </div>
    </form>
</template>
