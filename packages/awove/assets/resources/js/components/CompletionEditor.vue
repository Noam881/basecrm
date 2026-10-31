<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { useForm } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import type { MaintenanceRecord, Options } from "../types";
import FormErrors from "./FormErrors.vue";
defineOptions({ inheritAttrs: false });
const p = defineProps<Options & { record: MaintenanceRecord }>();
function initial() {
    return {
        version: p.record.version,
        asset_version: p.record.asset.version,
        completed_on: p.today,
        cost: "",
        next_due_on: "",
        asset_status: "",
    };
}
const f = useForm(initial());
const stale = computed(
    () =>
        f.version !== p.record.version ||
        f.asset_version !== p.record.asset.version,
);
function reset() {
    f.defaults(initial());
    f.reset();
    f.clearErrors();
}
watch(
    () => [p.record.version, p.record.asset.version],
    () => {
        if (!f.isDirty) reset();
    },
);
function save() {
    if (stale.value) return;
    f.transform((data) => ({
        version: data.version,
        asset_version: data.asset_version,
        completed_on: data.completed_on,
        next_due_on: data.next_due_on,
        asset_status: data.asset_status,
        ...(p.canManageCosts && !p.currencyError ? { cost: data.cost } : {}),
    })).post(`/assets/maintenance/${p.record.id}/complete`, {
        preserveScroll: true,
        onSuccess: reset,
    });
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <label class="crm-field"
            ><span class="crm-field-label">{{ tr("Completion date") }}</span
            ><input
                v-model="f.completed_on"
                type="date"
                required
                :max="today" /></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{
                tr("Asset state after completion")
            }}</span
            ><select
                v-model="f.asset_status"
                required
                :aria-label="tr('Asset state after completion')"
            >
                <option value="">
                    {{ tr("Choose the asset's current state") }}
                </option>
                <option
                    v-for="key in ['available', 'in-use', 'under-maintenance']"
                    :key="key"
                    :value="key"
                >
                    {{ statuses[key] }}
                </option>
            </select></label
        ><label v-if="canManageCosts && !currencyError" class="crm-field"
            ><span class="crm-field-label"
                >{{ tr("Recorded cost (") }}{{ currency }})</span
            ><input
                v-model="f.cost"
                type="text"
                inputmode="decimal"
                placeholder="0.00" /></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{
                tr("Manual next due date")
            }}</span
            ><input v-model="f.next_due_on" type="date"
        /></label>
        <p class="crm-muted crm-form-span">
            {{ tr("Use") }} {{ statuses["in-use"] }}
            {{ tr("for an open assignment, or") }}
            {{ statuses["under-maintenance"] }}
            {{
                tr(
                    "while other work remains in progress. Completion and any recorded cost are retained unchanged. Recording a cost creates no Finance transaction; the next date creates no automatic recurrence.",
                )
            }}
        </p>
        <p
            v-if="canManageCosts && currencyError"
            class="crm-notice crm-form-span"
            role="alert"
        >
            {{ currencyError }}
        </p>
        <p v-if="stale" class="crm-notice crm-form-span" role="alert">
            {{
                tr(
                    "The asset or maintenance action changed. Reload the form before recording completion.",
                )
            }}
        </p>
        <FormErrors :errors="f.errors" />
        <div class="crm-form-action">
            <button class="crm-button" :disabled="f.processing || stale">
                {{ tr("Complete maintenance") }}</button
            ><button
                v-if="stale"
                class="crm-secondary"
                type="button"
                @click="reset"
            >
                {{ tr("Reload completion form") }}
            </button>
        </div>
    </form>
</template>
