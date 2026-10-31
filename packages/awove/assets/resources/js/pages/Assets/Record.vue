<script setup lang="ts">
import { t as tr, translateLabels } from "@/lib/i18n";
import { Head, Link, useForm } from "@inertiajs/vue3";
import { computed } from "vue";
import HistoryList from "../../../../../crm-core/resources/js/components/HistoryList.vue";
import AssetsNav from "../../components/AssetsNav.vue";
import AssetEditor from "../../components/AssetEditor.vue";
import AssignmentEditor from "../../components/AssignmentEditor.vue";
import AssignmentDetailsEditor from "../../components/AssignmentDetailsEditor.vue";
import ReturnEditor from "../../components/ReturnEditor.vue";
import MaintenanceEditor from "../../components/MaintenanceEditor.vue";
import ClassifyIdentity from "../../components/ClassifyIdentity.vue";
import Documents from "../../components/Documents.vue";
import FormErrors from "../../components/FormErrors.vue";
import {
    assignmentTarget,
    historyLabels,
    type Options,
    type AssetRecord,
    type Assignment,
    type MaintenanceRecord,
    type Document,
    type History,
} from "../../types";
const p = defineProps<
    Options & {
        record: AssetRecord;
        editable: boolean;
        history: History[];
        assignments: Assignment[];
        maintenance: MaintenanceRecord[];
        documents: Document[];
        canCreateAssignment: boolean;
        canReturnAssignment: boolean;
        canUpdateAssignment: boolean;
        canCreateMaintenance: boolean;
    }
>();
const openAssignment = computed(() =>
    p.assignments.find((a) => a.returned_on === null),
);
const openMaintenance = computed(() =>
    p.maintenance.some((m) => !m.archived_at && m.status !== "completed"),
);
const assignmentAllowed = computed(
    () =>
        p.canCreateAssignment &&
        !openAssignment.value &&
        ["available", "in-use"].includes(p.record.status),
);
const archival = useForm({ version: p.record.version });
function archive() {
    archival.version = p.record.version;
    archival.post(`/assets/register/${p.record.id}/archive`, {
        preserveScroll: true,
    });
}
</script>
<template>
    <Head :title="record.name" /><Link
        class="crm-back"
        href="/assets?section=register"
        >← {{ moduleLabel }}</Link
    >
    <div class="crm-heading">
        <h1>{{ record.name }}</h1>
        <span class="crm-badge">{{
            record.archived_at ? tr("Archived") : statuses[record.status]
        }}</span>
    </div>
    <div class="crm-flow">
        <p class="crm-muted">{{ tr("Asset ID:") }} {{ record.code }}</p>
        <AssetsNav :sections="sections" :module-label="moduleLabel" />
        <section class="crm-card crm-flow">
            <h2>{{ tr("Register details") }}</h2>
            <AssetEditor v-bind="p" />
        </section>
        <ClassifyIdentity
            v-if="canClassify && (canCreateAssignment || canCreateMaintenance)"
            v-bind="p"
            :roles="[
                ...(canCreateAssignment ? ['customer'] : []),
                ...(canCreateMaintenance ? ['supplier'] : []),
            ]"
        />
        <section class="crm-card crm-flow">
            <h2>{{ tr("Assignments and returns") }}</h2>
            <p v-if="!assignments.length" class="crm-muted">
                {{ tr("No assignments recorded.") }}
            </p>
            <article
                v-for="assignment in assignments"
                :key="assignment.id"
                class="crm-note crm-flow"
            >
                <div class="crm-heading">
                    <h3>{{ assignmentTarget(assignment) }}</h3>
                    <span class="crm-badge">{{
                        assignment.returned_on
                            ? tr("Returned")
                            : tr("Open assignment")
                    }}</span>
                </div>
                <dl class="crm-detail-grid">
                    <div class="crm-detail-item">
                        <dt>{{ tr("Assignment target") }}</dt>
                        <dd>
                            {{
                                assignment.target_type.charAt(0).toUpperCase() +
                                assignment.target_type.slice(1)
                            }}
                        </dd>
                    </div>
                    <div class="crm-detail-item">
                        <dt>{{ tr("Assignment date") }}</dt>
                        <dd>{{ assignment.assigned_on }}</dd>
                    </div>
                    <div class="crm-detail-item">
                        <dt>{{ tr("Expected return date") }}</dt>
                        <dd>
                            {{
                                assignment.expected_return_on ??
                                tr("Not specified")
                            }}
                        </dd>
                    </div>
                    <div v-if="assignment.returned_on" class="crm-detail-item">
                        <dt>{{ tr("Actual return date") }}</dt>
                        <dd>{{ assignment.returned_on }}</dd>
                    </div>
                    <div
                        v-if="assignment.return_condition"
                        class="crm-detail-item"
                    >
                        <dt>{{ tr("Condition on return") }}</dt>
                        <dd>{{ assignment.return_condition }}</dd>
                    </div>
                </dl>
                <p v-if="assignment.notes">{{ assignment.notes }}</p>
                <details
                    v-if="!assignment.returned_on && canUpdateAssignment"
                    class="crm-form-disclosure"
                >
                    <summary>{{ tr("Edit expected return") }}</summary>
                    <AssignmentDetailsEditor
                        :asset="record"
                        :assignment="assignment"
                    />
                </details>
                <details
                    v-if="!assignment.returned_on && canReturnAssignment"
                    class="crm-form-disclosure"
                >
                    <summary>{{ tr("Record asset return") }}</summary>
                    <ReturnEditor
                        :asset="record"
                        :assignment="assignment"
                        :today="today"
                    />
                </details>
            </article>
            <details v-if="assignmentAllowed" class="crm-form-disclosure">
                <summary>{{ tr("Assign this asset") }}</summary>
                <AssignmentEditor v-bind="p" :asset="record" />
            </details>
            <p
                v-else-if="canCreateAssignment && !openAssignment"
                class="crm-muted"
            >
                {{ tr("An assignment can start when the asset is") }}
                {{ statuses.available }} {{ tr("or") }}
                {{ statuses["in-use"] }}.
            </p>
        </section>
        <section class="crm-card crm-flow">
            <h2>{{ tr("Maintenance") }}</h2>
            <p v-if="!maintenance.length" class="crm-muted">
                {{ tr("No maintenance actions recorded.") }}
            </p>
            <article
                v-for="action in maintenance"
                :key="action.id"
                class="crm-note crm-flow"
            >
                <div class="crm-inline">
                    <Link
                        class="crm-inline-link"
                        :href="`/assets/maintenance/${action.id}`"
                        >{{ action.action }}</Link
                    >
                    <span class="crm-badge">{{
                        action.archived_at
                            ? tr("Archived")
                            : maintenanceStatuses[action.status]
                    }}</span>
                </div>
                <dl class="crm-detail-grid">
                    <div class="crm-detail-item">
                        <dt>{{ tr("Responsible staff member") }}</dt>
                        <dd>{{ action.owner?.name ?? tr("Unassigned") }}</dd>
                    </div>
                    <div class="crm-detail-item">
                        <dt>{{ tr("Due date") }}</dt>
                        <dd>{{ action.due_on }}</dd>
                    </div>
                    <div v-if="action.completed_on" class="crm-detail-item">
                        <dt>{{ tr("Completion date") }}</dt>
                        <dd>{{ action.completed_on }}</dd>
                    </div>
                    <div v-if="action.next_due_on" class="crm-detail-item">
                        <dt>{{ tr("Manual next due date") }}</dt>
                        <dd>{{ action.next_due_on }}</dd>
                    </div>
                </dl>
            </article>
            <details v-if="canCreateMaintenance" class="crm-form-disclosure">
                <summary>{{ tr("Plan maintenance action") }}</summary>
                <MaintenanceEditor
                    v-bind="p"
                    :record="undefined"
                    :asset="record"
                />
            </details>
        </section>
        <Documents
            :asset-id="record.id"
            :documents="documents"
            :editable="editable"
            :can-view-documents="canViewDocuments"
            :can-manage-documents="canManageDocuments"
        /><HistoryList
            :history="history"
            :field-labels="translateLabels(historyLabels)"
        />
        <form
            v-if="editable && canArchive"
            class="crm-card crm-form"
            @submit.prevent="archive"
        >
            <p v-if="openAssignment || openMaintenance">
                {{
                    tr(
                        "Return the open assignment and resolve or archive maintenance before archiving this asset.",
                    )
                }}
            </p>
            <p v-else>
                {{
                    tr(
                        "Archiving retains the asset's assignments, maintenance, documents and history as read-only records.",
                    )
                }}
            </p>
            <FormErrors :errors="archival.errors" />
            <div class="crm-form-action">
                <button
                    class="crm-secondary"
                    :disabled="
                        archival.processing ||
                        !!openAssignment ||
                        openMaintenance
                    "
                >
                    {{ tr("Archive asset") }}
                </button>
            </div>
        </form>
    </div>
</template>
