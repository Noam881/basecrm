<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { Head, Link, router } from "@inertiajs/vue3";
import { computed } from "vue";
import type {
    Options,
    Kind,
    ProgramRecord,
} from "../../../components/Programs/types";
import { titles } from "../../../components/Programs/types";
import NonprofitNav from "../../../components/NonprofitNav.vue";
import ProgramTabs from "../../../components/Programs/ProgramTabs.vue";
import Editor from "../../../components/Programs/Editor.vue";
import AttendanceEditor from "../../../components/Programs/AttendanceEditor.vue";
import Documents from "../../../components/Programs/Documents.vue";
import HistoryList, {
    type HistoryEntry,
} from "../../../../../../crm-core/resources/js/components/HistoryList.vue";
const p = defineProps<
    Options & {
        kind: Kind;
        record: ProgramRecord;
        program: ProgramRecord | null;
        session?: ProgramRecord;
        attendance: ProgramRecord[];
        sessions: ProgramRecord[];
        feedback: ProgramRecord[];
        history: HistoryEntry[];
        documents: {
            id: number;
            name: string;
            version: number;
            href: string;
            archived_at: string | null;
        }[];
        canArchive: boolean;
    }
>();
const title = computed(
    () =>
        p.record.name ??
        (p.kind === "attendance"
            ? tr("Attendance")
            : (p.record.next_action ?? tr("Feedback"))),
);
function archive() {
    router.post(
        `/nonprofit/programs/${p.kind}/${p.record.id}/archive`,
        {
            version: p.record.version,
            ...(p.program ? { program_version: p.program.version } : {}),
        },
        { preserveScroll: true },
    );
}
</script>
<template>
    <Head :title="title" /><Link
        class="crm-back"
        :href="`/nonprofit/programs?section=${kind}`"
        >← {{ tr(titles[kind]) }}</Link
    >
    <div class="crm-heading">
        <h1>{{ title }}</h1>
        <span v-if="record.archived_at" class="crm-badge">{{
            tr("Archived")
        }}</span
        ><span v-else-if="record.active !== undefined" class="crm-badge">{{
            record.active ? tr("Active") : tr("Inactive")
        }}</span>
    </div>
    <div class="crm-flow">
        <NonprofitNav :sections="sections" /><ProgramTabs
            :can-view-programs="canViewPrograms"
            :can-view-participants="canViewParticipants"
        />
        <section class="crm-card crm-flow">
            <h2>
                {{
                    kind === "attendance"
                        ? tr("Attendance details")
                        : kind === "feedback"
                          ? tr("Feedback details")
                          : tr("{kind} details", { kind: tr(titles[kind]) })
                }}
            </h2>
            <p v-if="program && kind !== 'programs'">
                <Link :href="`/nonprofit/programs/programs/${program.id}`">{{
                    program.name
                }}</Link>
            </p>
            <template v-if="kind === 'attendance' && session && program"
                ><p>
                    {{ tr("Session:") }}
                    <Link
                        :href="`/nonprofit/programs/sessions/${session.id}`"
                        >{{ session.name }}</Link
                    >
                    · {{ session.session_on }}
                </p>
                <AttendanceEditor
                    v-bind="p"
                    :session="session"
                    :program="program" /></template
            ><Editor
                v-else-if="kind !== 'attendance'"
                v-bind="p"
                :kind="kind"
                :program="kind === 'programs' ? null : program"
            />
            <dl
                v-if="kind === 'participants' && record.contact"
                class="crm-detail-grid"
            >
                <div class="crm-detail-item">
                    <dt>{{ tr("Shared contact") }}</dt>
                    <dd>{{ record.contact.name }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>{{ tr("Shared contact email") }}</dt>
                    <dd>{{ record.contact.email ?? tr("Not provided") }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>{{ tr("Shared contact phone") }}</dt>
                    <dd>{{ record.contact.phone ?? tr("Not provided") }}</dd>
                </div>
            </dl>
        </section>
        <section v-if="kind === 'programs'" class="crm-card crm-flow">
            <h2>{{ tr("Sessions") }}</h2>
            <p v-if="!sessions.length">{{ tr("No sessions recorded.") }}</p>
            <article
                v-for="child in sessions"
                :key="child.id"
                class="crm-note crm-flow"
            >
                <div class="crm-inline">
                    <Link :href="`/nonprofit/programs/sessions/${child.id}`">{{
                        child.name
                    }}</Link
                    ><span v-if="child.archived_at" class="crm-badge">{{
                        tr("Archived")
                    }}</span>
                </div>
                <p>
                    {{ child.session_on }} ·
                    {{ child.location?.name ?? tr("Location not specified") }}
                </p>
            </article>
            <details
                v-if="record.editable && canCreate"
                class="crm-form-disclosure"
            >
                <summary>{{ tr("Add session") }}</summary>
                <Editor
                    v-bind="p"
                    kind="sessions"
                    :record="undefined"
                    :program="record"
                />
            </details>
        </section>
        <section v-if="kind === 'sessions'" class="crm-card crm-flow">
            <h2>{{ tr("Recorded attendance") }}</h2>
            <p v-if="!canViewParticipants" class="crm-notice">
                {{
                    tr(
                        "Participant and attendance records require separate roster access.",
                    )
                }}
            </p>
            <p v-else-if="!attendance.length">
                {{ tr("No attendance recorded for visible participants.") }}
            </p>
            <article v-for="row in attendance" :key="row.id" class="crm-note">
                <div class="crm-inline">
                    <Link :href="`/nonprofit/programs/attendance/${row.id}`">{{
                        row.participant?.name
                    }}</Link
                    ><span class="crm-badge">{{
                        statuses[row.status ?? ""]
                    }}</span>
                </div>
                <p v-if="row.notes">{{ row.notes }}</p>
            </article>
            <details
                v-if="record.editable && canManageAttendance && program"
                class="crm-form-disclosure"
            >
                <summary>{{ tr("Record participant attendance") }}</summary>
                <AttendanceEditor
                    v-bind="p"
                    :record="undefined"
                    :session="record"
                    :program="program"
                />
            </details>
        </section>
        <section v-if="kind === 'programs'" class="crm-card crm-flow">
            <h2>{{ tr("Feedback and follow-ups") }}</h2>
            <p v-if="!feedback.length">{{ tr("No feedback recorded.") }}</p>
            <article
                v-for="child in feedback"
                :key="child.id"
                class="crm-note crm-flow"
            >
                <Link :href="`/nonprofit/programs/feedback/${child.id}`"
                    >{{ child.next_action ?? tr("Feedback") }} ·
                    {{ child.feedback_on }}</Link
                >
                <p>{{ child.comments }}</p>
                <p v-if="child.due_on">
                    {{ tr("Follow-up:") }} {{ child.due_on }} ·
                    {{ child.follow_up_owner?.name ?? tr("Unassigned")
                    }}{{ child.completed_on ? tr(" · Completed") : "" }}
                </p>
            </article>
            <details
                v-if="record.editable && canCreate"
                class="crm-form-disclosure"
            >
                <summary>{{ tr("Add feedback") }}</summary>
                <Editor
                    v-bind="p"
                    kind="feedback"
                    :record="undefined"
                    :program="record"
                />
            </details>
        </section>
        <Documents
            v-if="kind === 'programs' && canViewDocuments"
            :program="record"
            :documents="documents"
            :can-manage-documents="canManageDocuments"
        />
        <HistoryList :history="history" />
        <section v-if="canArchive && record.editable" class="crm-card crm-flow">
            <p class="crm-muted">
                {{ tr("Archiving retains this record and its history.") }}
            </p>
            <div class="crm-form-action">
                <button type="button" class="crm-secondary" @click="archive">
                    {{ tr("Archive record") }}
                </button>
            </div>
        </section>
    </div>
</template>
