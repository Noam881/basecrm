<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import HistoryList from "../../../../../crm-core/resources/js/components/HistoryList.vue";
import FinanceNav from "../../components/FinanceNav.vue";
import PayrollEditor from "../../components/PayrollEditor.vue";
import type {
    PayrollOptions,
    PayrollRecord,
    PayrollHistory,
} from "../../payroll";
const props = defineProps<
    PayrollOptions & {
        record: PayrollRecord;
        editable: boolean;
        documents: {
            id: number;
            label: string;
            href: string;
            created_at: string;
        }[];
        history: PayrollHistory[];
    }
>();
const document = useForm({ label: "", url: "", version: props.record.version });
const archive = useForm({ version: props.record.version });
function addDocument() {
    document.version = props.record.version;
    document.post(`/finance/payroll/${props.record.id}/documents`, {
        preserveScroll: true,
        onSuccess: () => document.reset("label", "url"),
    });
}
function archiveRecord() {
    archive.version = props.record.version;
    archive.post(`/finance/payroll/${props.record.id}/archive`, {
        preserveScroll: true,
    });
}
</script>
<template>
    <Head title="Payroll preparation" /><Link
        href="/finance/payroll"
        class="crm-back"
        >← Back to payroll preparations</Link
    >
    <div class="crm-heading">
        <h1>
            {{ record.employee?.name ?? "Restricted employee" }} · Payroll
            preparation
        </h1>
        <button
            v-if="editable && canArchive"
            class="crm-secondary"
            :disabled="archive.processing"
            @click="archiveRecord"
        >
            Archive preparation
        </button>
    </div>
    <div class="crm-stack">
        <FinanceNav :sections="sections" />
        <p v-if="record.archived_at" class="crm-notice">
            Archived · Retained for this employee and pay period.
        </p>
        <section class="crm-card crm-flow">
            <h2>{{ record.period_start_on }} – {{ record.period_end_on }}</h2>
            <p>
                <span class="crm-badge">{{ statuses[record.status] }}</span>
            </p>
            <PayrollEditor
                v-if="editable"
                :key="record.version"
                v-bind="props"
            />
            <dl v-else class="crm-detail-grid">
                <div v-for="field in record.input_schema" :key="field.key">
                    <dt>
                        {{ field.label
                        }}{{ field.unit ? ` (${field.unit})` : "" }}
                    </dt>
                    <dd>{{ record.inputs[field.key] ?? "Not recorded" }}</dd>
                </div>
                <div>
                    <dt>Notes</dt>
                    <dd>{{ record.notes ?? "None" }}</dd>
                </div>
                <div>
                    <dt>Manually sent date</dt>
                    <dd>{{ record.sent_on ?? "Not recorded" }}</dd>
                </div>
            </dl>
        </section>
        <section class="crm-card crm-flow">
            <h2>Supporting documents</h2>
            <p v-if="!documents.length" class="crm-muted">
                No supporting documents recorded.
            </p>
            <article v-for="item in documents" :key="item.id" class="crm-note">
                <a
                    :href="item.href"
                    target="_blank"
                    rel="noopener noreferrer"
                    >{{ item.label }}</a
                >
            </article>
            <form
                v-if="editable"
                class="crm-form-grid"
                @submit.prevent="addDocument"
            >
                <label class="crm-field"
                    ><span class="crm-field-label">Document label</span
                    ><input
                        v-model="document.label"
                        required
                        maxlength="180" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Document link</span
                    ><input
                        v-model="document.url"
                        type="url"
                        required
                        maxlength="2048"
                        placeholder="https://"
                /></label>
                <div
                    v-if="Object.keys(document.errors).length"
                    class="crm-form-span crm-notice"
                    role="alert"
                >
                    <p v-for="(error, key) in document.errors" :key="key">
                        {{ error }}
                    </p>
                </div>
                <div class="crm-form-action">
                    <button class="crm-button" :disabled="document.processing">
                        Add supporting document
                    </button>
                </div>
            </form>
        </section>
        <HistoryList
            :field-labels="{
                employee_reference_id: 'Employee',
                owner_id: 'Owner',
                input_schema: 'Agreed input definitions',
                period_start_on: 'Period start',
                period_end_on: 'Period end',
                sent_on: 'Manually sent date',
            }"
            :history="
                history.map((entry) => ({
                    ...entry,
                    changed_fields: entry.details.changed_fields,
                }))
            "
        />
    </div>
</template>
