<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { useForm } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import type { MaintenanceRecord, Options } from "../types";
import FormErrors from "./FormErrors.vue";
defineOptions({ inheritAttrs: false });
const props = defineProps<Options & { record: MaintenanceRecord }>();
function initial() {
    return {
        version: props.record.version,
        asset_version: props.record.asset.version,
        cost: props.record.cost ?? "",
    };
}
const form = useForm(initial());
const stale = computed(
    () =>
        form.version !== props.record.version ||
        form.asset_version !== props.record.asset.version,
);
function reset() {
    form.defaults(initial());
    form.reset();
    form.clearErrors();
}
watch(
    () => [props.record.version, props.record.asset.version],
    () => {
        if (!form.isDirty) reset();
    },
);
function save() {
    if (stale.value || props.currencyError) return;
    form.post(`/assets/maintenance/${props.record.id}/cost`, {
        preserveScroll: true,
        onSuccess: reset,
    });
}
</script>
<template>
    <form class="crm-form" @submit.prevent="save">
        <label class="crm-field">
            <span class="crm-field-label"
                >{{ tr("Recorded cost (")
                }}{{ record.currency ?? currency }})</span
            >
            <input
                v-model="form.cost"
                type="text"
                inputmode="decimal"
                required
                :disabled="!!currencyError"
            />
        </label>
        <p class="crm-muted">
            {{
                tr(
                    "Recording or correcting a cost retains each previous value. It creates no financial transaction.",
                )
            }}
        </p>
        <p v-if="stale" class="crm-notice" role="alert">
            {{
                tr(
                    "The asset or maintenance action changed. Reload the latest cost before saving.",
                )
            }}
        </p>
        <FormErrors :errors="form.errors" :notice="currencyError" />
        <div class="crm-form-action">
            <button
                class="crm-button"
                :disabled="form.processing || stale || !!currencyError"
            >
                {{
                    record.cost === null || record.cost === undefined
                        ? tr("Record cost")
                        : tr("Correct recorded cost")
                }}
            </button>
            <button
                v-if="stale"
                class="crm-secondary"
                type="button"
                @click="reset"
            >
                {{ tr("Reload recorded cost") }}
            </button>
        </div>
    </form>
</template>
