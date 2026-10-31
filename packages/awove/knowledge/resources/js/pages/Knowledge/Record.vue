<script setup lang="ts">
import { t as tr, translateLabels } from "@/lib/i18n";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import RecordForm from "../../components/RecordForm.vue";
import HistoryList from "../../../../../crm-core/resources/js/components/HistoryList.vue";
import {
    sectionLabels,
    type Section,
    type Options,
    type KnowledgeRecord,
    type InstructionOption,
} from "../../types";
const props = defineProps<
    Options & {
        section: Section;
        record: KnowledgeRecord;
        editable: boolean;
        history: {
            id: number;
            event: string;
            changed_fields: string[];
            actor: { id: number; name: string } | null;
            created_at: string;
        }[];
        documents: {
            id: number;
            name: string;
            version: number;
            href: string;
            archived_at: string | null;
        }[];
        renewals: {
            id: number;
            previous_expiry_on: string;
            renewed_on: string;
            next_expiry_on: string | null;
            summary: string | null;
            actor: { id: number; name: string } | null;
        }[];
        instructionOptions: InstructionOption[];
        instructions: {
            id: number;
            version: number;
            label: string;
            href: string;
        }[];
    }
>();
const title = computed(
    () => props.record.title ?? props.record.name ?? tr("Knowledge record"),
);
const warning = ref("");
const document = useForm({ name: "", url: "" });
const renewal = useForm({
    version: props.record.version,
    renewed_on: props.today,
    next_expiry_on: "",
    next_remind_on: "",
    summary: "",
});
const instruction = useForm({
    version: props.record.version,
    reference_key: "",
});
watch(
    () => props.record.version,
    (v) => {
        renewal.version = v;
        instruction.version = v;
    },
);
function addDocument() {
    document.post(`/knowledge/${props.section}/${props.record.id}/documents`, {
        preserveScroll: true,
        onSuccess: () => document.reset(),
    });
}
function renew() {
    renewal.post(`/knowledge/renewals/${props.record.id}/renew`, {
        preserveScroll: true,
        onSuccess: () =>
            renewal.reset("next_expiry_on", "next_remind_on", "summary"),
        onHttpException: (response) => {
            if (response.status === 409) {
                warning.value = tr(
                    "This renewal changed. Reload before recording completion.",
                );
                return false;
            }
        },
    });
}
function addInstruction() {
    const selected = props.instructionOptions.find(
        (o) => o.key === instruction.reference_key,
    );
    if (!selected) return;
    instruction
        .transform((data) => ({
            version: data.version,
            reference_type: selected.type,
            reference_id: selected.id,
            reference_version: selected.version,
        }))
        .post(`/knowledge/library/${props.record.id}/instructions`, {
            preserveScroll: true,
            onSuccess: () => instruction.reset("reference_key"),
        });
}
function archive() {
    router.post(
        `/knowledge/${props.section}/${props.record.id}/archive`,
        { version: props.record.version },
        {
            preserveScroll: true,
            onError: (errors) =>
                (warning.value = Object.values(errors).join(" ")),
        },
    );
}
const fields: Record<string, string> = {
    kind: "Kind",
    title: "Title or topic",
    category: "Category",
    relevant_role: "Relevant role",
    purpose: "Purpose",
    use_classification: "Use classification",
    revision: "Current version",
    revised_on: "Revision date",
    updated_on: "Updated document date",
    status: "Status",
    name: "Name",
    provider: "Provider",
    expires_on: "Expiry date",
    remind_on: "Reminder date",
    notes: "Notes",
    password_manager_reference: "Password-manager item reference",
};
const events = {
    created: "Record created",
    updated: "Record updated",
    archived: "Record archived",
    renewed: "Renewal recorded",
    "document.added": "Document reference added",
    "document.archived": "Document reference archived",
    "instruction.linked": "Instruction linked",
    "instruction.unlinked": "Instruction unlinked",
};
</script>
<template>
    <Head :title="title" /><Link
        class="crm-back"
        :href="'/knowledge?section=' + section"
        >{{ tr("← Back to") }}
        {{ tr(sectionLabels[section]).toLowerCase() }}</Link
    >
    <div class="crm-heading">
        <h1>{{ title }}</h1>
        <button
            v-if="editable && canArchive"
            class="crm-secondary"
            @click="archive"
        >
            {{ tr("Archive record") }}
        </button>
    </div>
    <div class="crm-flow">
        <p v-if="warning" class="crm-notice" role="alert">{{ warning }}</p>
        <section class="crm-card crm-flow">
            <h2>{{ tr("Record details") }}</h2>
            <p>
                {{ section === "systems" ? tr("Access owner") : tr("Owner") }}:
                {{ record.owner?.name ?? tr("Unassigned") }}
            </p>
            <p v-if="record.archived_at" class="crm-muted">
                {{ tr("Archived · Historical information retained") }}
            </p>
            <RecordForm
                v-if="editable"
                :key="record.version"
                :section="section"
                :record="record"
                :options="props"
            />
            <dl v-else class="crm-detail-grid">
                <template v-for="(label, field) in fields" :key="field"
                    ><div
                        v-if="
                            record[field as keyof KnowledgeRecord] !==
                                undefined &&
                            record[field as keyof KnowledgeRecord] !== null
                        "
                    >
                        <dt>{{ tr(label) }}</dt>
                        <dd>
                            {{
                                field === "status"
                                    ? section === "library"
                                        ? statuses[String(record.status)]
                                        : renewalStatuses[String(record.status)]
                                    : field === "kind"
                                      ? kinds[String(record.kind)]
                                      : record[field as keyof KnowledgeRecord]
                            }}
                        </dd>
                    </div></template
                >
            </dl>
            <a
                v-if="record.login_href && canViewSystemReferences"
                class="crm-secondary"
                :href="record.login_href"
                target="_blank"
                rel="noopener noreferrer"
                >{{ tr("Open public login page") }}</a
            >
            <p
                v-if="section === 'systems' && canViewSystemReferences"
                class="crm-muted"
            >
                {{
                    tr(
                        "This record points to your password manager. No password or secret key belongs in the CRM.",
                    )
                }}
            </p>
        </section>
        <section v-if="section === 'renewals'" class="crm-card crm-flow">
            <h2>{{ tr("Renewal history") }}</h2>
            <p v-if="!renewals.length" class="crm-muted">
                {{ tr("No completed renewal has been recorded.") }}
            </p>
            <article v-for="event in renewals" :key="event.id" class="crm-note">
                <p>
                    {{ tr("Renewed") }} {{ event.renewed_on }} ·
                    {{ event.actor?.name ?? tr("Former staff") }}
                </p>
                <p>
                    {{ tr("Previous expiry") }} {{ event.previous_expiry_on }} ·
                    {{
                        event.next_expiry_on
                            ? tr("Next expiry {date}", {
                                  date: event.next_expiry_on,
                              })
                            : tr("No next expiry scheduled")
                    }}
                </p>
                <p v-if="event.summary">{{ event.summary }}</p>
            </article>
            <form
                v-if="editable && record.status !== 'renewed'"
                class="crm-form-grid"
                @submit.prevent="renew"
            >
                <h3>{{ tr("Record renewal") }}</h3>
                <label class="crm-field"
                    ><span class="crm-field-label">{{ tr("Renewed on") }}</span
                    ><input
                        v-model="renewal.renewed_on"
                        type="date"
                        :max="today"
                        required /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Next expiry date (optional)")
                    }}</span
                    ><input
                        v-model="renewal.next_expiry_on"
                        type="date" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Next reminder date (optional)")
                    }}</span
                    ><input
                        v-model="renewal.next_remind_on"
                        type="date"
                        :max="renewal.next_expiry_on || undefined" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Renewal summary")
                    }}</span
                    ><textarea
                        v-model="renewal.summary"
                        rows="3"
                        maxlength="10000"
                    />
                </label>
                <p class="crm-muted">
                    {{
                        tr(
                            "Enter the next dates explicitly. Leaving the next expiry blank records a completed renewal without generating another occurrence.",
                        )
                    }}
                </p>
                <div
                    v-if="Object.keys(renewal.errors).length"
                    class="crm-form-action crm-error"
                    role="alert"
                >
                    <p v-for="(error, key) in renewal.errors" :key="key">
                        {{ error }}
                    </p>
                </div>
                <div class="crm-form-action">
                    <button class="crm-button" :disabled="renewal.processing">
                        {{ tr("Record renewal") }}
                    </button>
                </div>
            </form>
        </section>
        <section
            v-if="section !== 'systems' && canViewDocuments"
            class="crm-card crm-flow"
        >
            <h2>{{ tr("Documents and materials") }}</h2>
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
                ><span v-if="entry.archived_at" class="crm-muted">{{
                    tr("Archived, retained")
                }}</span>
                <div
                    v-else-if="editable && canManageDocuments"
                    class="crm-form-action"
                >
                    <button
                        class="crm-secondary"
                        @click="
                            router.post(
                                '/knowledge/documents/' + entry.id + '/archive',
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
                        maxlength="2048"
                        type="url"
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
        <section v-if="section === 'library'" class="crm-card crm-flow">
            <h2>{{ tr("Linked work and routines") }}</h2>
            <p class="crm-muted">
                {{
                    tr(
                        "Only links you can access are shown. Actual completion remains with the owning work or routine.",
                    )
                }}
            </p>
            <p v-if="!instructions.length" class="crm-muted">
                {{ tr("No accessible work links yet.") }}
            </p>
            <article
                v-for="entry in instructions"
                :key="entry.id"
                class="crm-note crm-flow"
            >
                <Link :href="entry.href">{{ entry.label }}</Link>
                <div v-if="editable" class="crm-form-action">
                    <button
                        class="crm-secondary"
                        @click="
                            router.post(
                                '/knowledge/instructions/' +
                                    entry.id +
                                    '/archive',
                                {
                                    version: record.version,
                                    link_version: entry.version,
                                },
                                { preserveScroll: true },
                            )
                        "
                    >
                        {{ tr("Remove instruction link") }}
                    </button>
                </div>
            </article>
            <form
                v-if="editable && instructionOptions.length"
                class="crm-form"
                @submit.prevent="addInstruction"
            >
                <label class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Work or routine")
                    }}</span
                    ><select v-model="instruction.reference_key" required>
                        <option value="">
                            {{ tr("Choose accessible work") }}
                        </option>
                        <option
                            v-for="entry in instructionOptions"
                            :key="entry.key"
                            :value="entry.key"
                        >
                            {{ entry.label }}
                        </option>
                    </select></label
                >
                <div
                    v-if="Object.keys(instruction.errors).length"
                    class="crm-form-action crm-error"
                    role="alert"
                >
                    <p v-for="(error, key) in instruction.errors" :key="key">
                        {{ error }}
                    </p>
                </div>
                <div class="crm-form-action">
                    <button
                        class="crm-button"
                        :disabled="instruction.processing"
                    >
                        {{ tr("Link instruction") }}
                    </button>
                </div>
            </form>
        </section>
        <HistoryList
            :history="history"
            :event-labels="translateLabels(events)"
            :field-labels="
                translateLabels({
                    ...fields,
                    owner_id: 'Owner',
                    documents: 'Documents',
                    instructions: 'Linked instructions',
                    login_url: 'Public login page',
                })
            "
        />
    </div>
</template>
