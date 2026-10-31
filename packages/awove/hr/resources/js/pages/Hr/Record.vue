<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import RecordForm from "../../components/RecordForm.vue";
import ChecklistForm from "../../components/ChecklistForm.vue";
import HistoryList from "../../components/History.vue";
import {
    sectionLabels,
    type Options,
    type Section,
    type HrRecord,
    type ChecklistItem,
    type History,
} from "../../types";
const props = defineProps<
    Options & {
        section: Section;
        record: HrRecord;
        editable: boolean;
        archivable: boolean;
        history: History[];
        documents: {
            id: number;
            name: string;
            href: string;
            version: number;
            archived_at: string | null;
        }[];
        items: ChecklistItem[];
        employeeVersion: number | null;
    }
>();
const title = computed(
    () =>
        props.record.name ??
        props.record.employee?.name ??
        props.record.role_name ??
        tr("HR record"),
);
const document = useForm({ name: "", url: "" });
const complete = useForm({
    version: props.record.version,
    completed_on: props.today,
    deactivate_employee: false,
    employee_version: props.employeeVersion,
});
const warning = ref("");
watch(
    () => props.record.version,
    (value) => {
        complete.version = value;
        complete.employee_version = props.employeeVersion;
    },
);
const fields: Record<string, string> = {
    role_name: "Role",
    email: "Private email",
    phone: "Private phone",
    role_description: "Role description",
    responsibilities: "Responsibilities",
    start_on: "Start date",
    employment_status: "Employment status",
    description: "Description",
    opened_on: "Opened date",
    status: "Status",
    stage: "Candidate stage",
    notes: "Candidate notes",
    next_action_on: "Next action date",
    kind: "Purpose",
    due_on: "Due date",
    final_work_on: "Final working date",
    final_pay_info: "Manual final-pay information",
    completed_on: "Completion date",
};
function displayValue(field: string): string {
    const value = String(props.record[field as keyof HrRecord] ?? "—");
    if (field === "stage") return props.stages[value] ?? value;
    if (field === "employment_status")
        return props.employmentStatuses[value] ?? value;
    if (field === "status") return props.vacancyStatuses[value] ?? value;
    if (field === "kind")
        return value === "offboarding" ? tr("Offboarding") : tr("Onboarding");
    return value;
}
function addDocument() {
    document.post(`/hr/${props.section}/${props.record.id}/documents`, {
        preserveScroll: true,
        onSuccess: () => document.reset(),
    });
}
function finish() {
    complete.post(`/hr/cases/${props.record.id}/complete`, {
        preserveScroll: true,
        onHttpException: (response) => {
            if ([403, 409].includes(response.status)) {
                warning.value = tr(
                    "The case or employee reference changed. Reload before completing it.",
                );
                return false;
            }
        },
    });
}
function archive() {
    router.post(
        `/hr/${props.section}/${props.record.id}/archive`,
        { version: props.record.version },
        {
            preserveScroll: true,
            onError: (errors) => {
                warning.value = Object.values(errors).join(" ");
            },
        },
    );
}
</script>
<template>
    <Head :title="title" /><Link
        class="crm-back"
        :href="'/hr?section=' + section"
        >{{ tr("← Back to") }}
        {{ tr(sectionLabels[section]).toLowerCase() }}</Link
    >
    <div class="crm-heading">
        <h1>{{ title }}</h1>
        <button v-if="archivable" class="crm-secondary" @click="archive">
            {{ tr("Archive record") }}
        </button>
    </div>
    <div class="crm-flow">
        <p v-if="warning" class="crm-notice" role="alert">{{ warning }}</p>
        <section class="crm-card crm-flow">
            <h2>{{ tr("Record details") }}</h2>
            <p>
                {{ tr("Responsible staff:") }}
                {{ record.owner?.name ?? tr("Former staff") }}
            </p>
            <p v-if="record.archived_at" class="crm-muted">
                {{ tr("Archived · Historical information retained") }}
            </p>
            <p v-if="record.completed_on" class="crm-notice">
                {{ tr("Case completed") }} {{ record.completed_on
                }}{{ tr(". Checklist history is retained.") }}
            </p>
            <RecordForm
                v-if="editable"
                :key="record.version"
                :section="section"
                :record="record"
                :options="props"
            />
            <dl v-else class="crm-detail-grid">
                <div v-if="record.employee">
                    <dt>{{ tr("Employee") }}</dt>
                    <dd>
                        {{ record.employee.name
                        }}{{ record.employee.active ? "" : tr(" · Inactive") }}
                    </dd>
                </div>
                <template v-for="(label, field) in fields" :key="field"
                    ><div
                        v-if="
                            record[field as keyof HrRecord] !== undefined &&
                            record[field as keyof HrRecord] !== null
                        "
                    >
                        <dt>{{ tr(label) }}</dt>
                        <dd>
                            {{ displayValue(field) }}
                        </dd>
                    </div></template
                >
            </dl>
        </section>
        <section v-if="section === 'cases'" class="crm-card crm-flow">
            <h2>{{ tr("Case checklist") }}</h2>
            <p class="crm-muted">
                {{
                    tr(
                        "Only checklist work you can access is listed. Case completion checks every required item. Access work and final-pay transfer are manual records.",
                    )
                }}
            </p>
            <p v-if="!items.length" class="crm-muted">
                {{ tr("No accessible checklist items yet.") }}
            </p>
            <article v-for="item in items" :key="item.id" class="crm-note">
                <Link :href="item.href!">{{ item.title }}</Link>
                <p>
                    {{ tr(categories[item.category]) }} ·
                    {{ item.required ? tr("Required") : tr("Optional") }} ·
                    {{ checklistStatuses[item.status] ?? item.status }}
                    {{ tr("· Due") }} {{ item.due_on
                    }}<span v-if="item.completed_on">
                        {{ tr("· Completed") }} {{ item.completed_on }}</span
                    ><span v-if="item.archived_at">
                        {{ tr("· Archived") }}</span
                    >
                </p>
            </article>
            <details v-if="editable && canCreate">
                <summary>{{ tr("Add checklist item") }}</summary>
                <ChecklistForm :options="props" :case-id="record.id" />
            </details>
        </section>
        <section
            v-if="section === 'cases' && editable"
            class="crm-card crm-flow"
        >
            <h2>{{ tr("Complete case") }}</h2>
            <p>
                {{
                    tr(
                        "Complete required work and complete or explicitly skip optional work before recording a completion date.",
                    )
                }}
            </p>
            <form class="crm-form-grid" @submit.prevent="finish">
                <label class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Case completion date")
                    }}</span
                    ><input
                        v-model="complete.completed_on"
                        type="date"
                        required /></label
                ><label
                    v-if="record.kind === 'offboarding' && canManageEmployees"
                    class="crm-checkbox"
                    ><input
                        v-model="complete.deactivate_employee"
                        type="checkbox"
                    />{{
                        tr("Retain the employee reference as inactive")
                    }}</label
                >
                <p v-if="record.kind === 'offboarding'" class="crm-muted">
                    {{
                        tr(
                            "This changes only the employee reference if selected. It does not disable a login or revoke external access.",
                        )
                    }}
                </p>
                <div
                    v-if="Object.keys(complete.errors).length"
                    class="crm-form-action crm-error"
                    role="alert"
                >
                    <p v-for="(error, key) in complete.errors" :key="key">
                        {{ error }}
                    </p>
                </div>
                <div class="crm-form-action">
                    <button
                        class="crm-button"
                        :disabled="complete.processing || !!warning"
                    >
                        {{ tr("Complete case") }}
                    </button>
                </div>
            </form>
        </section>
        <section
            v-if="section !== 'vacancies' && canViewDocuments"
            class="crm-card crm-flow"
        >
            <h2>
                {{
                    section === "candidates"
                        ? tr("CV and documents")
                        : tr("Restricted documents")
                }}
            </h2>
            <p v-if="!documents.length" class="crm-muted">
                {{ tr("No document references yet.") }}
            </p>
            <article
                v-for="entry in documents"
                :key="entry.id"
                class="crm-note crm-flow"
            >
                <a
                    :href="entry.href"
                    target="_blank"
                    rel="noopener noreferrer"
                    >{{ entry.name }}</a
                ><span v-if="entry.archived_at">
                    {{ tr("· Archived, retained") }}</span
                >
                <div
                    v-else-if="editable && canManageDocuments"
                    class="crm-form-action"
                >
                    <button
                        class="crm-secondary"
                        @click="
                            router.post(
                                '/hr/documents/' + entry.id + '/archive',
                                { version: entry.version },
                                { preserveScroll: true },
                            )
                        "
                    >
                        {{ tr("Archive document reference") }}
                    </button>
                </div>
            </article>
            <form
                v-if="editable && canManageDocuments"
                class="crm-form-grid"
                @submit.prevent="addDocument"
            >
                <label class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Document name")
                    }}</span
                    ><input
                        v-model="document.name"
                        maxlength="180"
                        required /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("HTTPS document link")
                    }}</span
                    ><input
                        v-model="document.url"
                        type="url"
                        maxlength="2048"
                        required
                /></label>
                <div
                    v-if="Object.keys(document.errors).length"
                    class="crm-form-action crm-error"
                    role="alert"
                >
                    <p v-for="(error, key) in document.errors" :key="key">
                        {{ error }}
                    </p>
                </div>
                <div class="crm-form-action">
                    <button class="crm-button" :disabled="document.processing">
                        {{ tr("Add document reference") }}
                    </button>
                </div>
            </form>
        </section>
        <HistoryList :history="history" />
    </div>
</template>
