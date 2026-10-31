<script setup lang="ts">
import { useForm, usePage } from "@inertiajs/vue3";
import EmployeeReferenceSelect from "../../../../crm-core/resources/js/components/EmployeeReferenceSelect.vue";
import type { PayrollOptions, PayrollRecord } from "../payroll";
defineOptions({ inheritAttrs: false });
const props = defineProps<PayrollOptions & { record?: PayrollRecord }>();
const schema = props.record?.input_schema ?? props.inputSchema;
const form = useForm({
    employee_reference_id: props.record?.employee_reference_id ?? null,
    owner_id: props.record
        ? props.record.owner_id
        : (usePage().props.auth as { user: { id: number } }).user.id,
    period_start_on: props.record?.period_start_on ?? "",
    period_end_on: props.record?.period_end_on ?? "",
    status: props.record?.status ?? "pending-input",
    inputs: Object.fromEntries(
        schema.map((f) => [f.key, props.record?.inputs[f.key] ?? ""]),
    ) as Record<string, string | null>,
    notes: props.record?.notes ?? "",
    sent_on: props.record?.sent_on ?? "",
    version: props.record?.version ?? 1,
});
function save() {
    form.transform((data) => ({
        ...data,
        ...(props.record && data.employee_reference_id === null
            ? { employee_reference_id: undefined }
            : {}),
    }));
    if (props.record)
        form.patch(`/finance/payroll/${props.record.id}`, {
            preserveScroll: true,
        });
    else form.post("/finance/payroll");
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <EmployeeReferenceSelect
            v-if="!record || record.employee"
            v-model="form.employee_reference_id"
            :options="employees"
            :selected="record?.employee"
            :can-manage="canManageEmployees"
            :error="form.errors.employee_reference_id"
        />
        <p v-else class="crm-muted">
            Employee reference is restricted; the existing link will be
            retained.
        </p>
        <label v-if="canAssign" class="crm-field"
            ><span class="crm-field-label">Owner</span
            ><select v-model="form.owner_id">
                <option :value="null">Unassigned</option>
                <option
                    v-for="owner in owners"
                    :key="owner.id"
                    :value="owner.id"
                >
                    {{ owner.name }}
                </option>
            </select></label
        >
        <label class="crm-field"
            ><span class="crm-field-label">Period start</span
            ><input v-model="form.period_start_on" type="date" required /><small
                v-if="form.errors.period_start_on"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.period_start_on }}</small
            ></label
        >
        <label class="crm-field"
            ><span class="crm-field-label">Period end</span
            ><input v-model="form.period_end_on" type="date" required /><small
                v-if="form.errors.period_end_on"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.period_end_on }}</small
            ></label
        >
        <label class="crm-field"
            ><span class="crm-field-label">Status</span
            ><select v-model="form.status">
                <option
                    v-for="(label, key) in statuses"
                    :key="key"
                    :value="key"
                >
                    {{ label }}
                </option>
            </select></label
        >
        <label v-if="form.status === 'sent-to-payroll'" class="crm-field"
            ><span class="crm-field-label">Manually sent date</span
            ><input v-model="form.sent_on" type="date" required /><small
                v-if="form.errors.sent_on"
                role="alert"
                data-slot="input-error"
                >{{ form.errors.sent_on }}</small
            ></label
        >
        <div class="crm-form-span crm-flow">
            <h3>Agreed manual inputs</h3>
            <p class="crm-muted">
                {{ record?.currency ?? currency }} · Record reviewed inputs for
                this period. No payroll amount is calculated.
            </p>
        </div>
        <label v-for="field in schema" :key="field.key" class="crm-field"
            ><span class="crm-field-label"
                >{{ field.label
                }}{{ field.unit ? ` (${field.unit})` : "" }}</span
            ><textarea
                v-if="field.type === 'text'"
                v-model="form.inputs[field.key]"
                rows="3"
                maxlength="4000"
                :required="field.required && form.status !== 'pending-input'"
            /><input
                v-else
                v-model="form.inputs[field.key]"
                :type="field.type === 'date' ? 'date' : 'text'"
                :inputmode="
                    ['decimal', 'money'].includes(field.type)
                        ? 'decimal'
                        : undefined
                "
                :required="field.required && form.status !== 'pending-input'"
            /><small
                v-if="form.errors[`inputs.${field.key}`]"
                role="alert"
                data-slot="input-error"
                >{{ form.errors[`inputs.${field.key}`] }}</small
            ></label
        >
        <label class="crm-field crm-form-span"
            ><span class="crm-field-label">Notes</span
            ><textarea v-model="form.notes" rows="3" maxlength="20000" />
        </label>
        <div
            v-if="Object.keys(form.errors).length"
            class="crm-form-span crm-notice"
            role="alert"
        >
            <p v-for="(error, key) in form.errors" :key="key">{{ error }}</p>
        </div>
        <p v-if="currencyError" class="crm-form-span crm-notice" role="alert">
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
                          ? "Save preparation"
                          : "Create preparation"
                }}
            </button>
        </div>
    </form>
</template>
