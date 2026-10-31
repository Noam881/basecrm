<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { useForm, usePage } from "@inertiajs/vue3";
import WorkFields from "../../../../crm-core/resources/js/components/WorkFields.vue";
import {
    historicalOwner,
    historicalClassification,
    type Options,
    type Work,
} from "../types";
defineOptions({ inheritAttrs: false });
const props = defineProps<Options & { record?: Work }>();
const form = useForm({
    title: props.record?.title ?? "",
    description: props.record?.description ?? "",
    owner_id: props.record
        ? props.record.owner_id
        : (usePage().props.auth as { user: { id: number } }).user.id,
    priority: props.record?.priority ?? "normal",
    due_on: props.record?.due_on ?? "",
    status: props.record?.status ?? "new",
    completed_on: props.record?.completed_on ?? "",
    customer_relationship_id: props.record?.customer_relationship_id ?? null,
    notes: props.record?.notes ?? "",
    document_url: props.record?.document_url ?? "",
    version: props.record?.version ?? 1,
});
function save() {
    if (props.record)
        form.patch(`/operations/requests/${props.record.id}`, {
            preserveScroll: true,
        });
    else form.post("/operations/requests");
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <WorkFields
            :model-value="form"
            :owners="historicalOwner(owners, record?.owner)"
            :statuses="statuses"
            :completed-statuses="['done']"
            :errors="form.errors"
            :can-assign="canAssign"
        /><label class="crm-field"
            ><span class="crm-field-label">{{ tr("Customer (optional)") }}</span
            ><select v-model="form.customer_relationship_id">
                <option :value="null">{{ tr("No customer") }}</option>
                <option
                    v-for="customer in historicalClassification(
                        customers,
                        record?.customer,
                    )"
                    :key="customer.id"
                    :value="customer.id"
                >
                    {{ customer.name }}
                </option></select
            ><small
                v-if="form.errors.customer_relationship_id"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.customer_relationship_id }}</small
            ></label
        ><label class="crm-field crm-form-span"
            ><span class="crm-field-label">{{ tr("Notes") }}</span
            ><textarea v-model="form.notes" rows="3" maxlength="10000" /><small
                v-if="form.errors.notes"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.notes }}</small
            ></label
        ><label class="crm-field crm-form-span"
            ><span class="crm-field-label">{{ tr("Document link") }}</span
            ><input
                v-model="form.document_url"
                type="url"
                maxlength="2048"
                :placeholder="tr('https://')"
            /><small
                v-if="form.errors.document_url"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.document_url }}</small
            ></label
        >
        <div class="crm-form-action">
            <button class="crm-button" :disabled="form.processing">
                {{
                    form.processing
                        ? tr("Saving…")
                        : record
                          ? tr("Save request")
                          : tr("Create request")
                }}
            </button>
        </div>
    </form>
</template>
