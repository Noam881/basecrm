<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { useForm, usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import type { Options, LedgerEntry } from "../types";
defineOptions({ inheritAttrs: false });
const page = usePage();
const props = defineProps<Options & { record?: LedgerEntry }>();
const form = useForm({
    relationship_id: props.record?.relationship_id ?? null,
    reference: props.record?.reference ?? "",
    description: props.record?.description ?? "",
    category: props.record?.category ?? "",
    total: props.record?.total ?? "",
    owner_id: props.record
        ? props.record.owner_id
        : (usePage().props.auth as { user: { id: number } }).user.id,
    due_on: props.record?.due_on ?? "",
    next_action: props.record?.next_action ?? "",
    next_action_on: props.record?.next_action_on ?? "",
    document_url: props.record?.document_url ?? "",
    version: props.record?.version ?? 1,
});
const paid = !!props.record && props.record.paid_amount !== "0.00";
const relationships = computed(() =>
    props.record?.relationship &&
    !props.relationships.some((r) => r.id === props.record?.relationship?.id)
        ? [...props.relationships, props.record.relationship]
        : props.relationships,
);
const owners = computed(() =>
    props.record?.owner &&
    !props.owners.some((o) => o.id === props.record?.owner?.id)
        ? [...props.owners, props.record.owner]
        : props.owners,
);
function save() {
    if (props.record)
        form.patch(`/finance/${props.cap}/${props.record.id}`, {
            preserveScroll: true,
        });
    else form.post(`/finance/${props.cap}`);
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <label class="crm-field"
            ><span class="crm-field-label">{{
                cap === "collections" ? tr("Customer") : tr("Supplier")
            }}</span
            ><select
                v-model="form.relationship_id"
                :required="!record"
                :disabled="paid"
            >
                <option :value="null">
                    {{ tr("Choose existing classification") }}
                </option>
                <option v-for="r in relationships" :key="r.id" :value="r.id">
                    {{ r.name }}
                </option></select
            ><small
                v-if="form.errors.relationship_id"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.relationship_id }}</small
            ></label
        >
        <label class="crm-field"
            ><span class="crm-field-label">{{
                cap === "expenses" ? tr("Document reference") : tr("Reference")
            }}</span
            ><input v-model="form.reference" required maxlength="120" /><small
                v-if="form.errors.reference"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.reference }}</small
            ></label
        >
        <label v-if="cap === 'expenses'" class="crm-field"
            ><span class="crm-field-label">{{ tr("Category") }}</span
            ><input
                v-model="form.category"
                required
                maxlength="120"
                list="finance-expense-categories"
            /><datalist id="finance-expense-categories">
                <option value="Office supplies">
                    {{ tr("Office supplies") }}
                </option>
                <option value="Software">{{ tr("Software") }}</option>
                <option value="Rent">{{ tr("Rent") }}</option>
                <option value="Professional services">
                    {{ tr("Professional services") }}
                </option>
                <option value="Travel">{{ tr("Travel") }}</option>
                <option value="Other">{{ tr("Other") }}</option></datalist
            ><small
                v-if="form.errors.category"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.category }}</small
            ></label
        >
        <label class="crm-field crm-form-span"
            ><span class="crm-field-label">{{ tr("Description") }}</span
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
        >
        <label class="crm-field"
            ><span class="crm-field-label"
                >{{ tr("Total payable (")
                }}{{ record?.currency ?? currency }})</span
            ><input
                v-model="form.total"
                inputmode="decimal"
                required
                :readonly="paid"
                placeholder="0.00"
            /><small
                v-if="form.errors.total"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.total }}</small
            ></label
        >
        <label class="crm-field"
            ><span class="crm-field-label">{{ tr("Owner") }}</span
            ><select v-model="form.owner_id" :disabled="!canAssign">
                <option :value="null">{{ tr("Unassigned") }}</option>
                <option
                    v-for="owner in owners"
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
        >
        <label class="crm-field"
            ><span class="crm-field-label">{{ tr("Due date") }}</span
            ><input v-model="form.due_on" type="date" required /><small
                v-if="form.errors.due_on"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.due_on }}</small
            ></label
        >
        <template v-if="cap === 'collections'"
            ><label class="crm-field crm-form-span"
                ><span class="crm-field-label">{{
                    tr("Next collection action")
                }}</span
                ><textarea
                    v-model="form.next_action"
                    rows="2"
                    maxlength="10000"
                /><small
                    v-if="form.errors.next_action"
                    role="alert"
                    data-slot="input-error"
                    >{{ form.errors.next_action }}</small
                ></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{
                    tr("Next action date")
                }}</span
                ><input v-model="form.next_action_on" type="date" /><small
                    v-if="form.errors.next_action_on"
                    role="alert"
                    data-slot="input-error"
                    >{{ form.errors.next_action_on }}</small
                ></label
            ></template
        >
        <label class="crm-field crm-form-span"
            ><span class="crm-field-label">{{
                cap === "expenses"
                    ? tr("Invoice or receipt link")
                    : tr("Supporting document link")
            }}</span
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
        <p v-if="paid" class="crm-muted crm-form-span">
            {{
                tr(
                    "Recorded payments retain the agreed total and customer/supplier. Refunds, credits and changes to that agreement need a separate agreed policy.",
                )
            }}
        </p>
        <small
            v-if="page.props.errors.currency"
            role="alert"
            class="crm-form-span"
            >{{ page.props.errors.currency }}</small
        >
        <div class="crm-form-action">
            <button class="crm-button" :disabled="form.processing">
                {{
                    form.processing
                        ? tr("Saving…")
                        : record
                          ? tr("Save record")
                          : cap === "collections"
                            ? tr("Create charge")
                            : tr("Create expense")
                }}
            </button>
        </div>
    </form>
</template>
