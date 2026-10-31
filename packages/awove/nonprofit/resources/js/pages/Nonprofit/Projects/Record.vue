<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { Head, Link, router, useForm, usePage } from "@inertiajs/vue3";
import NonprofitNav from "../../../components/NonprofitNav.vue";
import ProjectForm from "../../../components/Projects/ProjectForm.vue";
import WorkEditor from "../../../components/Projects/WorkEditor.vue";
import MeetingEditor from "../../../components/Projects/MeetingEditor.vue";
import ConnectionsForms from "../../../components/Projects/ConnectionsForms.vue";
import Errors from "../../../components/Projects/Errors.vue";
import HistoryList from "../../../../../../crm-core/resources/js/components/HistoryList.vue";
import type {
    Options,
    Project,
    WorkRecord,
    Meeting,
    History,
    Budget,
    Document,
    SourceOptions,
    Linked,
    Resource,
    Payment,
    Actuals,
} from "../../../components/Projects/types";
const p = defineProps<
    Options & {
        record: Project;
        history: History[];
        budgets: Budget[];
        meetings: Meeting[];
        work: Record<string, WorkRecord[]>;
        resources: Linked<Resource>[];
        resourceOptions: Record<string, SourceOptions<Resource>>;
        payments: Linked<Payment>[];
        paymentOptions: Record<string, SourceOptions<Payment>>;
        actuals: Actuals;
        documents: Document[];
    }
>();
const archive = useForm({ version: p.record.version });
const document = useForm({
    project_version: p.record.version,
    name: "",
    url: "",
});
const page = usePage();
function remove() {
    archive.version = p.record.version;
    archive.post(`/nonprofit/projects/${p.record.id}/archive`);
}
function addDocument() {
    document.project_version = p.record.version;
    document.post(`/nonprofit/projects/${p.record.id}/documents`, {
        preserveScroll: true,
        onSuccess: () => document.reset("name", "url"),
    });
}
function archiveDocument(d: Document) {
    router.post(
        `/nonprofit/project-documents/${d.id}/archive`,
        { version: d.version, project_version: p.record.version },
        { preserveScroll: true },
    );
}
function unlink(
    type: "resources" | "payments",
    link: Linked<Resource | Payment>,
) {
    if (link.id === undefined || link.version === undefined) return;
    router.post(
        `/nonprofit/projects/${p.record.id}/${type}/${link.id}/archive`,
        { version: link.version, project_version: p.record.version },
        { preserveScroll: true },
    );
}
</script>
<template>
    <Head :title="record.name" /><Link
        class="crm-back"
        href="/nonprofit/projects"
        >{{ tr("← Projects") }}</Link
    >
    <div class="crm-heading">
        <h1>{{ record.name }}</h1>
        <span class="crm-badge">{{
            record.archived_at ? tr("Archived") : statuses[record.status]
        }}</span>
    </div>
    <div class="crm-flow">
        <NonprofitNav :sections="sections" />
        <section class="crm-card crm-flow">
            <h2>{{ tr("Project plan") }}</h2>
            <ProjectForm v-bind="p" />
        </section>
        <p v-if="!record.active" class="crm-notice">
            {{
                tr(
                    "Related work, meetings and links are retained read only. Reopen an unarchived project to resume work.",
                )
            }}
        </p>
        <section v-if="canViewBudget" class="crm-card crm-flow">
            <h2>{{ tr("Budget and actual payments") }}</h2>
            <dl class="crm-detail-grid">
                <div class="crm-detail-item">
                    <dt>{{ tr("Planned budget") }}</dt>
                    <dd>
                        {{
                            actuals.planned_budget === null
                                ? tr("Not entered")
                                : actuals.planned_budget +
                                  " " +
                                  actuals.currency
                        }}
                    </dd>
                </div>
                <div class="crm-detail-item">
                    <dt>{{ tr("Actual incoming receipts") }}</dt>
                    <dd>
                        {{
                            actuals.incoming.available
                                ? actuals.incoming.total +
                                  " " +
                                  actuals.currency
                                : tr("Unavailable")
                        }}
                    </dd>
                </div>
                <div class="crm-detail-item">
                    <dt>{{ tr("Actual outgoing payments") }}</dt>
                    <dd>
                        {{
                            actuals.outgoing.available
                                ? actuals.outgoing.total +
                                  " " +
                                  actuals.currency
                                : tr("Unavailable")
                        }}
                    </dd>
                </div>
            </dl>
            <p class="crm-muted">
                {{
                    tr(
                        "Actuals include whole linked Finance payments only. Planned budgets, receipts and outgoing payments remain separate. A missing capability or inaccessible linked record makes that actual total unavailable.",
                    )
                }}
            </p>
            <form
                class="crm-form-grid"
                method="get"
                :action="`/nonprofit/projects/${record.id}`"
            >
                <label class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Payment date from")
                    }}</span
                    ><input
                        type="date"
                        name="actual_from"
                        :value="actuals.from ?? ''" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Payment date to")
                    }}</span
                    ><input
                        type="date"
                        name="actual_to"
                        :value="actuals.to ?? ''"
                /></label>
                <div class="crm-form-action">
                    <button class="crm-secondary">
                        {{ tr("Apply payment dates") }}
                    </button>
                </div>
            </form>
            <details v-if="budgets.length" class="crm-form-disclosure">
                <summary>{{ tr("Budget history") }}</summary>
                <article
                    v-for="budget in budgets"
                    :key="budget.id"
                    class="crm-note"
                >
                    <strong>{{ budget.amount }} {{ budget.currency }}</strong>
                    <p>{{ budget.actor?.name ?? tr("Former staff") }}</p>
                </article>
            </details>
            <article
                v-for="(link, index) in payments"
                :key="link.id ?? index"
                class="crm-note"
            >
                <template v-if="link.target"
                    ><Link :href="link.target.href">{{
                        link.target.label
                    }}</Link>
                    <p>
                        {{
                            link.target.direction === "incoming"
                                ? tr("Incoming receipt")
                                : tr("Outgoing supplier payment")
                        }}
                    </p>
                    <div
                        v-if="
                            record.editable && record.active && canManageBudget
                        "
                        class="crm-form-action"
                    >
                        <button
                            class="crm-secondary"
                            @click="unlink('payments', link)"
                        >
                            {{ tr("Remove payment association") }}
                        </button>
                    </div></template
                >
                <p v-else class="crm-muted">
                    {{
                        tr(
                            "A retained financial association is unavailable with your current access.",
                        )
                    }}
                </p>
            </article>
        </section>
        <section
            v-for="kind in ['project-tasks', 'project-milestones']"
            :key="kind"
            class="crm-card crm-flow"
        >
            <h2>
                {{
                    kind === "project-tasks"
                        ? tr("Tasks and follow-ups")
                        : tr("Milestones")
                }}
            </h2>
            <p v-if="!work[kind]?.length" class="crm-muted">
                {{ tr("No accessible work recorded.") }}
            </p>
            <article
                v-for="item in work[kind] ?? []"
                :key="item.id"
                class="crm-note crm-flow"
            >
                <div class="crm-inline">
                    <Link :href="item.record_url">{{ item.title }}</Link
                    ><span class="crm-badge">{{
                        item.archived_at
                            ? tr("Archived")
                            : workStatuses[item.status]
                    }}</span>
                </div>
                <p>
                    {{ item.owner?.name ?? tr("Unassigned") }} {{ tr("· Due") }}
                    {{ item.due_on
                    }}<span v-if="item.completed_on">
                        {{ tr("· Completed") }} {{ item.completed_on }}</span
                    >
                </p>
            </article>
            <details
                v-if="record.editable && record.active && canCreate"
                class="crm-form-disclosure"
            >
                <summary>
                    {{
                        kind === "project-tasks"
                            ? tr("Add project task")
                            : tr("Add milestone")
                    }}
                </summary>
                <WorkEditor
                    v-bind="p"
                    :project="record"
                    :kind="kind"
                    :record="undefined"
                />
            </details>
        </section>
        <section class="crm-card crm-flow">
            <h2>{{ tr("Meetings and decisions") }}</h2>
            <p v-if="!meetings.length" class="crm-muted">
                {{ tr("No meeting summaries recorded.") }}
            </p>
            <article
                v-for="meeting in meetings"
                :key="meeting.id"
                class="crm-note crm-flow"
            >
                <h3>{{ meeting.held_on }}</h3>
                <p>{{ meeting.summary }}</p>
                <p v-if="meeting.decisions">
                    <strong>{{ tr("Decisions:") }} </strong
                    >{{ meeting.decisions }}
                </p>
                <p v-if="meeting.follow_up">
                    <Link :href="meeting.follow_up.record_url">{{
                        meeting.follow_up.title
                    }}</Link>
                    · {{ meeting.follow_up.owner?.name ?? tr("Unassigned") }}
                    {{ tr("· Due") }} {{ meeting.follow_up.due_on }}
                </p>
                <p v-else-if="meeting.follow_up_restricted" class="crm-muted">
                    {{ tr("A follow-up is retained with restricted access.") }}
                </p>
                <details
                    v-if="record.editable && record.active"
                    class="crm-form-disclosure"
                >
                    <summary>{{ tr("Edit meeting") }}</summary>
                    <MeetingEditor
                        v-bind="p"
                        :project="record"
                        :meeting="meeting"
                    />
                </details>
            </article>
            <details
                v-if="record.editable && record.active && canCreate"
                class="crm-form-disclosure"
            >
                <summary>{{ tr("Record a meeting") }}</summary>
                <MeetingEditor v-bind="p" :project="record" />
            </details>
        </section>
        <section class="crm-card crm-flow">
            <h2>{{ tr("Equipment and purchases") }}</h2>
            <p v-if="!resources.length" class="crm-muted">
                {{ tr("No existing resources linked.") }}
            </p>
            <article
                v-for="(link, index) in resources"
                :key="link.id ?? index"
                class="crm-note"
            >
                <template v-if="link.target"
                    ><Link :href="link.target.href">{{
                        link.target.label
                    }}</Link>
                    <div
                        v-if="record.editable && record.active"
                        class="crm-form-action"
                    >
                        <button
                            class="crm-secondary"
                            @click="unlink('resources', link)"
                        >
                            {{ tr("Remove resource link") }}
                        </button>
                    </div></template
                >
                <p v-else class="crm-muted">
                    {{
                        tr(
                            "A retained resource is unavailable with your current access.",
                        )
                    }}
                </p>
            </article>
            <ConnectionsForms
                v-if="record.editable && record.active"
                v-bind="p"
                :project="record"
            />
        </section>
        <section v-if="canViewDocuments" class="crm-card crm-flow">
            <h2>{{ tr("Documents") }}</h2>
            <p v-if="!documents.length" class="crm-muted">
                {{ tr("No document links recorded.") }}
            </p>
            <article v-for="d in documents" :key="d.id" class="crm-note">
                <Link :href="d.href">{{ d.name }}</Link>
                <p v-if="d.archived_at" class="crm-muted">
                    {{ tr("Archived") }}
                </p>
                <div
                    v-if="
                        !d.archived_at &&
                        record.editable &&
                        record.active &&
                        canManageDocuments
                    "
                    class="crm-form-action"
                >
                    <button class="crm-secondary" @click="archiveDocument(d)">
                        {{ tr("Archive document") }}
                    </button>
                </div>
            </article>
            <details
                v-if="record.editable && record.active && canManageDocuments"
                class="crm-form-disclosure"
            >
                <summary>{{ tr("Add document link") }}</summary>
                <form class="crm-form" @submit.prevent="addDocument">
                    <label class="crm-field"
                        ><span class="crm-field-label">{{
                            tr("Document name")
                        }}</span
                        ><input
                            v-model="document.name"
                            required
                            maxlength="180" /></label
                    ><label class="crm-field"
                        ><span class="crm-field-label">{{
                            tr("Document URL")
                        }}</span
                        ><input
                            v-model="document.url"
                            required
                            type="url"
                            maxlength="2048" /></label
                    ><Errors :errors="document.errors" />
                    <div class="crm-form-action">
                        <button
                            class="crm-button"
                            :disabled="document.processing"
                        >
                            {{ tr("Add document") }}
                        </button>
                    </div>
                </form>
            </details>
        </section>
        <HistoryList :history="history" /><Errors :errors="page.props.errors" />
        <form
            v-if="record.editable && canArchive"
            class="crm-card crm-form"
            @submit.prevent="remove"
        >
            <p>
                {{
                    tr(
                        "Archiving retains the plan, work, meeting summaries, financial associations and history. It creates no financial transaction.",
                    )
                }}
            </p>
            <Errors :errors="archive.errors" />
            <div class="crm-form-action">
                <button class="crm-secondary" :disabled="archive.processing">
                    {{ tr("Archive project") }}
                </button>
            </div>
        </form>
    </div>
</template>
