<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { useForm, usePage } from "@inertiajs/vue3";
import { ref } from "vue";
import EmployeeReferenceSelect from "../../../../crm-core/resources/js/components/EmployeeReferenceSelect.vue";
import type { Options, HrRecord, Section } from "../types";
const props = defineProps<{
    section: Section;
    options: Options;
    record?: HrRecord;
}>();
const form = useForm({
    owner_id:
        props.record?.owner_id ??
        (usePage().props.auth as { user: { id: number } }).user.id,
    employee_reference_id:
        props.record?.employee_reference_id ?? (null as number | null),
    manager_employee_reference_id:
        props.record?.manager_employee_reference_id ?? (null as number | null),
    role_name: props.record?.role_name ?? "",
    email: props.record?.email ?? "",
    phone: props.record?.phone ?? "",
    role_description: props.record?.role_description ?? "",
    responsibilities: props.record?.responsibilities ?? "",
    start_on: props.record?.start_on ?? props.options.today,
    employment_status: props.record?.employment_status ?? "active",
    description: props.record?.description ?? "",
    opened_on: props.record?.opened_on ?? props.options.today,
    status: props.record?.status ?? "open",
    vacancy_id: props.record?.vacancy_id ?? (null as number | null),
    name: props.record?.name ?? "",
    stage: props.record?.stage ?? "new",
    notes: props.record?.notes ?? "",
    next_action_on: props.record?.next_action_on ?? "",
    kind: props.record?.kind ?? "onboarding",
    due_on: props.record?.due_on ?? props.options.today,
    final_work_on: props.record?.final_work_on ?? "",
    final_pay_info: props.record?.final_pay_info ?? "",
    version: props.record?.version ?? 1,
});
const warning = ref("");
function save() {
    form.transform((data) => {
        const fields: Record<Section, string[]> = {
            employees: [
                "employee_reference_id",
                "manager_employee_reference_id",
                "role_name",
                "email",
                "phone",
                "role_description",
                "responsibilities",
                "start_on",
                "employment_status",
            ],
            vacancies: ["role_name", "description", "opened_on", "status"],
            candidates: [
                "vacancy_id",
                "name",
                "email",
                "phone",
                "stage",
                "notes",
                "next_action_on",
                "employee_reference_id",
            ],
            cases: [
                "employee_reference_id",
                "kind",
                "name",
                "due_on",
                "final_work_on",
            ],
        };
        const values: Record<string, unknown> = { owner_id: data.owner_id };
        for (const key of fields[props.section])
            values[key] = data[key as keyof typeof data];
        if (props.section === "cases" && data.kind === "onboarding")
            delete values.final_work_on;
        if (
            props.section === "cases" &&
            data.kind === "offboarding" &&
            props.options.canEditFinalPay
        )
            values.final_pay_info = data.final_pay_info;
        if (props.record) {
            values.version = data.version;
            if (["employees", "cases"].includes(props.section))
                delete values.employee_reference_id;
            if (props.record.manager_restricted)
                delete values.manager_employee_reference_id;
            if (props.record.vacancy_restricted)
                values.vacancy_id_action = "keep";
            if (props.record.employee_restricted)
                values.employee_reference_id_action = "keep";
        }
        return values;
    });
    const opts = {
        preserveScroll: true,
        onHttpException: (response: { status: number }) => {
            if ([403, 409].includes(response.status)) {
                warning.value = tr(
                    "This HR record or your access changed. Your edits are kept. Reload before saving.",
                );
                return false;
            }
        },
    };
    if (props.record)
        form.patch(`/hr/${props.section}/${props.record.id}`, opts);
    else form.post(`/hr/${props.section}`, opts);
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <template v-if="section === 'employees' || section === 'cases'">
            <div v-if="record" class="crm-field">
                <span class="crm-field-label">{{ tr("Employee") }}</span>
                <p>
                    {{
                        record.employee?.name ??
                        tr("Restricted employee reference")
                    }}<span v-if="record.employee && !record.employee.active">
                        {{ tr("· Inactive") }}</span
                    >
                </p>
            </div>
            <EmployeeReferenceSelect
                v-else
                v-model="form.employee_reference_id"
                :options="
                    section === 'cases' && form.kind === 'offboarding'
                        ? options.retainedEmployees
                        : options.employees
                "
                :can-manage="options.canManageEmployees"
                :error="form.errors.employee_reference_id"
            />
        </template>
        <template v-if="section === 'employees'">
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Role") }}</span
                ><input v-model="form.role_name" required maxlength="180"
            /></label>
            <EmployeeReferenceSelect
                v-if="!record?.manager_restricted"
                v-model="form.manager_employee_reference_id"
                :options="options.employees"
                :selected="record?.manager"
                :label="tr('Manager (optional)')"
                :required="false"
            />
            <p v-else class="crm-muted">
                {{
                    tr(
                        "The existing manager reference is restricted and will be kept.",
                    )
                }}
            </p>
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Start date") }}</span
                ><input v-model="form.start_on" type="date" required
            /></label>
            <label class="crm-field"
                ><span class="crm-field-label">{{
                    tr("Employment status")
                }}</span
                ><select v-model="form.employment_status">
                    <option
                        v-for="(label, id) in options.employmentStatuses"
                        :key="id"
                        :value="id"
                    >
                        {{ label }}
                    </option>
                </select></label
            >
            <label class="crm-field crm-form-span"
                ><span class="crm-field-label">{{
                    tr("Role description")
                }}</span
                ><textarea
                    v-model="form.role_description"
                    rows="3"
                    maxlength="20000"
                />
            </label>
            <label class="crm-field crm-form-span"
                ><span class="crm-field-label">{{
                    tr("Responsibilities")
                }}</span
                ><textarea
                    v-model="form.responsibilities"
                    rows="3"
                    required
                    maxlength="20000"
                />
            </label>
        </template>
        <template v-if="section === 'vacancies'">
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Vacancy role") }}</span
                ><input v-model="form.role_name" required maxlength="180"
            /></label>
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Opened date") }}</span
                ><input v-model="form.opened_on" type="date" required
            /></label>
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Vacancy status") }}</span
                ><select v-model="form.status">
                    <option
                        v-for="(label, id) in options.vacancyStatuses"
                        :key="id"
                        :value="id"
                    >
                        {{ label }}
                    </option>
                </select></label
            >
            <label class="crm-field crm-form-span"
                ><span class="crm-field-label">{{ tr("Description") }}</span
                ><textarea
                    v-model="form.description"
                    rows="3"
                    required
                    maxlength="20000"
                />
            </label>
        </template>
        <template v-if="section === 'candidates'">
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Candidate name") }}</span
                ><input v-model="form.name" required maxlength="180"
            /></label>
            <label v-if="!record?.vacancy_restricted" class="crm-field"
                ><span class="crm-field-label">{{ tr("Vacancy") }}</span
                ><select v-model="form.vacancy_id" required>
                    <option :value="null" disabled>
                        {{ tr("Choose a vacancy") }}
                    </option>
                    <option
                        v-if="
                            record?.vacancy &&
                            !options.vacancies.some(
                                (v) => v.id === record?.vacancy_id,
                            )
                        "
                        :value="record.vacancy_id"
                    >
                        {{ record.vacancy.role_name }} {{ tr("(retained)") }}
                    </option>
                    <option
                        v-for="vacancy in options.vacancies"
                        :key="vacancy.id"
                        :value="vacancy.id"
                    >
                        {{ vacancy.role_name }}
                    </option>
                </select></label
            >
            <p v-else class="crm-muted">
                {{ tr("The existing vacancy is restricted and will be kept.") }}
            </p>
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Candidate stage") }}</span
                ><select v-model="form.stage">
                    <option
                        v-for="(label, id) in options.stages"
                        :key="id"
                        :value="id"
                    >
                        {{ label }}
                    </option>
                </select></label
            >
            <label class="crm-field"
                ><span class="crm-field-label">{{
                    tr("Next action date")
                }}</span
                ><input v-model="form.next_action_on" type="date"
            /></label>
            <EmployeeReferenceSelect
                v-if="form.stage === 'hired' && !record?.employee_restricted"
                v-model="form.employee_reference_id"
                :options="options.employees"
                :selected="record?.employee"
                :label="tr('Resolved employee (optional)')"
                :required="false"
                :can-manage="options.canManageEmployees"
            />
            <p v-if="form.stage === 'hired'" class="crm-muted">
                {{
                    tr(
                        "Choose an existing employee explicitly, or create a reference and return. Hiring never matches people by name or creates a login.",
                    )
                }}
            </p>
            <label class="crm-field crm-form-span"
                ><span class="crm-field-label">{{ tr("Candidate notes") }}</span
                ><textarea v-model="form.notes" rows="3" maxlength="20000" />
            </label>
        </template>
        <template v-if="section === 'employees' || section === 'candidates'">
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Private email") }}</span
                ><input v-model="form.email" type="email" maxlength="254"
            /></label>
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Private phone") }}</span
                ><input v-model="form.phone" type="tel" maxlength="80"
            /></label>
            <p class="crm-muted">
                {{
                    tr(
                        "These HR contact details are restricted to this record and are not added to the shared contacts directory.",
                    )
                }}
            </p>
        </template>
        <template v-if="section === 'cases'">
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Case name") }}</span
                ><input v-model="form.name" required maxlength="180"
            /></label>
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Purpose") }}</span
                ><select v-model="form.kind" :disabled="!!record">
                    <option value="onboarding">{{ tr("Onboarding") }}</option>
                    <option value="offboarding">{{ tr("Offboarding") }}</option>
                </select></label
            >
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Case due date") }}</span
                ><input v-model="form.due_on" type="date" required
            /></label>
            <label v-if="form.kind === 'offboarding'" class="crm-field"
                ><span class="crm-field-label">{{
                    tr("Final working date")
                }}</span
                ><input v-model="form.final_work_on" type="date" required
            /></label>
            <label
                v-if="form.kind === 'offboarding' && options.canEditFinalPay"
                class="crm-field crm-form-span"
                ><span class="crm-field-label">{{
                    tr("Manual final-pay information")
                }}</span
                ><textarea
                    v-model="form.final_pay_info"
                    rows="3"
                    maxlength="20000"
                />
            </label>
            <p v-if="form.kind === 'offboarding'" class="crm-muted">
                {{
                    tr(
                        "Record handover and access/equipment return work in the checklist. Completion does not revoke accounts, calculate pay or notify payroll automatically.",
                    )
                }}
            </p>
        </template>
        <label class="crm-field"
            ><span class="crm-field-label">{{ tr("Responsible staff") }}</span
            ><select v-model="form.owner_id" :disabled="!options.canAssign">
                <option
                    v-for="owner in options.owners"
                    :key="owner.id"
                    :value="owner.id"
                >
                    {{ owner.name }}
                </option>
            </select></label
        >
        <div
            v-if="Object.keys(form.errors).length || warning"
            class="crm-form-action crm-error"
            role="alert"
        >
            <p v-for="(error, key) in form.errors" :key="key">{{ error }}</p>
            <p v-if="warning">{{ warning }}</p>
        </div>
        <div class="crm-form-action">
            <button class="crm-button" :disabled="form.processing || !!warning">
                {{
                    form.processing
                        ? tr("Saving…")
                        : record
                          ? tr("Save record")
                          : tr("Create record")
                }}
            </button>
        </div>
    </form>
</template>
