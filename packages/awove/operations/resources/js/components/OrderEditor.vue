<script setup lang="ts">
import { useForm, usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import {
    historicalOwner,
    historicalClassification,
    type Options,
    type Order,
} from "../types";
defineOptions({ inheritAttrs: false });
const props = defineProps<Options & { record?: Order }>();
const page = usePage();
const currencyError = computed(
    () => props.currencyError ?? page.props.errors.currency,
);
const form = useForm({
    supplier_relationship_id: props.record?.supplier_relationship_id ?? null,
    owner_id: props.record
        ? props.record.owner_id
        : (page.props.auth as { user: { id: number } }).user.id,
    reference: props.record?.reference ?? "",
    description: props.record?.description ?? "",
    quantity: props.record?.quantity ?? "",
    agreed_total: props.record?.agreed_total ?? "",
    ordered_on: props.record?.ordered_on ?? props.today,
    expected_on: props.record?.expected_on ?? "",
    document_url: props.record?.document_url ?? "",
    outstanding_items: props.record?.outstanding_items ?? "",
    status: props.record?.status ?? "draft",
    version: props.record?.version ?? 1,
});
function save() {
    if (props.record)
        form.patch(`/operations/orders/${props.record.id}`, {
            preserveScroll: true,
        });
    else form.post("/operations/orders");
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <label class="crm-field"
            ><span class="crm-field-label">Supplier</span
            ><select
                v-model="form.supplier_relationship_id"
                :required="!record"
            >
                <option :value="null">Choose supplier</option>
                <option
                    v-for="supplier in historicalClassification(
                        suppliers,
                        record?.supplier,
                    )"
                    :key="supplier.id"
                    :value="supplier.id"
                >
                    {{ supplier.name }}
                </option></select
            ><small
                v-if="form.errors.supplier_relationship_id"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.supplier_relationship_id }}</small
            ></label
        ><label class="crm-field"
            ><span class="crm-field-label">Reference</span
            ><input v-model="form.reference" required maxlength="120" /><small
                v-if="form.errors.reference"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.reference }}</small
            ></label
        ><label class="crm-field crm-form-span"
            ><span class="crm-field-label">Description</span
            ><textarea
                v-model="form.description"
                required
                rows="3"
                maxlength="20000"
            /><small
                v-if="form.errors.description"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.description }}</small
            ></label
        ><label class="crm-field"
            ><span class="crm-field-label">Agreed quantity (optional)</span
            ><input
                v-model="form.quantity"
                inputmode="decimal"
                placeholder="For example, 10.000"
            /><small
                v-if="form.errors.quantity"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.quantity }}</small
            ></label
        ><label class="crm-field"
            ><span class="crm-field-label"
                >Agreed total ({{ record?.currency ?? currency }})</span
            ><input
                v-model="form.agreed_total"
                inputmode="decimal"
                required
                placeholder="0.00"
            /><small
                v-if="form.errors.agreed_total"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.agreed_total }}</small
            ></label
        ><label class="crm-field"
            ><span class="crm-field-label">Owner</span
            ><select v-model="form.owner_id" :disabled="!canAssign">
                <option :value="null">Unassigned</option>
                <option
                    v-for="owner in historicalOwner(owners, record?.owner)"
                    :key="owner.id"
                    :value="owner.id"
                >
                    {{ owner.name }}
                </option></select
            ><small
                v-if="form.errors.owner_id"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.owner_id }}</small
            ></label
        ><label class="crm-field"
            ><span class="crm-field-label">Status</span
            ><select v-model="form.status">
                <option
                    v-for="(label, key) in orderStatuses"
                    :key="key"
                    :value="key"
                    :disabled="
                        ['part-received', 'received'].includes(key) &&
                        key !== record?.status
                    "
                >
                    {{ label }}
                </option></select
            ><small
                >Delivery receipts determine partial and complete receipt
                states.</small
            ><small
                v-if="form.errors.status"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.status }}</small
            ></label
        ><label class="crm-field"
            ><span class="crm-field-label">Order date</span
            ><input v-model="form.ordered_on" type="date" required /><small
                v-if="form.errors.ordered_on"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.ordered_on }}</small
            ></label
        ><label class="crm-field"
            ><span class="crm-field-label">Expected delivery date</span
            ><input v-model="form.expected_on" type="date" /><small
                v-if="form.errors.expected_on"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.expected_on }}</small
            ></label
        ><label class="crm-field crm-form-span"
            ><span class="crm-field-label">Outstanding items</span
            ><textarea
                v-model="form.outstanding_items"
                rows="2"
                maxlength="10000"
            /><small
                v-if="form.errors.outstanding_items"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.outstanding_items }}</small
            ></label
        ><label class="crm-field crm-form-span"
            ><span class="crm-field-label">Document link</span
            ><input
                v-model="form.document_url"
                type="url"
                placeholder="https://"
                maxlength="2048"
            /><small
                v-if="form.errors.document_url"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.document_url }}</small
            ></label
        >
        <p v-if="currencyError" class="crm-form-action crm-notice" role="alert">
            {{ currencyError }}
        </p>
        <div class="crm-form-action">
            <button
                class="crm-button"
                :disabled="form.processing || !!currencyError"
            >
                {{
                    form.processing
                        ? "Saving…"
                        : record
                          ? "Save order"
                          : "Create order"
                }}
            </button>
        </div>
    </form>
</template>
