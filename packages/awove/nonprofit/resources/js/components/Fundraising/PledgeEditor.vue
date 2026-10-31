<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import type { Options, Constituent, Pledge } from "./types";
import Errors from "./Errors.vue";
defineOptions({ inheritAttrs: false });
const p = defineProps<
    Options & { constituent: Constituent; record?: Pledge; editable?: boolean }
>();
function initial() {
    return {
        version: p.record?.version ?? 1,
        constituent_version: p.constituent.version,
        reference: p.record?.reference ?? "",
        amount: p.record?.amount ?? "",
        expected_on: p.record?.expected_on ?? "",
        restrictions: p.record?.restrictions ?? "",
    };
}
const f = useForm(initial());
const stale = computed(
    () =>
        f.constituent_version !== p.constituent.version ||
        (!!p.record && f.version !== p.record.version),
);
function reset() {
    f.defaults(initial());
    f.reset();
    f.clearErrors();
}
watch(
    () => [p.constituent.version, p.record?.version],
    () => {
        if (!f.isDirty) reset();
    },
);
function save() {
    if (stale.value) return;
    f.transform((d) =>
        p.record
            ? d
            : {
                  constituent_version: d.constituent_version,
                  reference: d.reference,
                  amount: d.amount,
                  expected_on: d.expected_on,
                  restrictions: d.restrictions,
              },
    );
    if (p.record)
        f.patch(`/nonprofit/fundraising/pledges/${p.record.id}`, {
            preserveScroll: true,
            onSuccess: reset,
        });
    else
        f.post(
            `/nonprofit/fundraising/constituents/${p.constituent.id}/pledges`,
            { preserveScroll: true, onSuccess: reset },
        );
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <fieldset
            class="crm-form-grid crm-form-span"
            :disabled="(!!record && !editable) || !!currencyError"
        >
            <label class="crm-field"
                ><span class="crm-field-label">Pledge reference (optional)</span
                ><input v-model="f.reference" maxlength="120" /></label
            ><label class="crm-field"
                ><span class="crm-field-label"
                    >Pledged amount ({{ record?.currency ?? currency }})</span
                ><input
                    v-model="f.amount"
                    required
                    inputmode="decimal"
                    type="text"
                    aria-label="Pledged amount" /></label
            ><label class="crm-field"
                ><span class="crm-field-label">Expected donation date</span
                ><input v-model="f.expected_on" required type="date" /></label
            ><label class="crm-field crm-form-span"
                ><span class="crm-field-label">Donation restrictions</span
                ><textarea
                    v-model="f.restrictions"
                    rows="3"
                    maxlength="10000"
                    placeholder="Leave blank if unrestricted"
                />
            </label>
        </fieldset>
        <p class="crm-muted crm-form-span">
            A pledge is an expectation, not a receipt. Saving creates no Finance
            transaction.
        </p>
        <p v-if="currencyError" class="crm-notice crm-form-span" role="alert">
            {{ currencyError }}
        </p>
        <p v-if="stale" class="crm-notice crm-form-span" role="alert">
            The pledge or fundraising context changed. Reload before saving.
        </p>
        <Errors :errors="f.errors" />
        <div v-if="!record || editable" class="crm-form-action">
            <button
                class="crm-button"
                :disabled="stale || f.processing || !!currencyError"
            >
                {{ record ? "Save pledge" : "Add pledge" }}</button
            ><button
                v-if="stale"
                class="crm-secondary"
                type="button"
                @click="reset"
            >
                Reload pledge form
            </button>
        </div>
    </form>
</template>
