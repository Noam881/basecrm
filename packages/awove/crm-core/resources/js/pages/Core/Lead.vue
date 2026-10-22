<script setup lang="ts">
import { Head, Link, useForm, usePage, router } from "@inertiajs/vue3";
import { ref } from "vue";
import {
    date,
    type Lead,
    type Person,
    type Stage,
    type Field,
    type Task,
} from "../../types";
import ExtensionPanel from "../../components/ExtensionPanel.vue";
const props = defineProps<{
    lead: Lead;
    owners: Person[];
    statuses: Stage[];
    fields: Record<string, Field>;
    editable: boolean;
    panels: {
        id: string;
        label: string;
        component: string;
        endpoint: string;
        permission: string;
    }[];
}>();
const page = usePage();
const zone = (page.props.brand as { timezone: string }).timezone;
const capabilities = page.props.capabilities as string[];
const form = useForm({
    title: props.lead.title,
    owner_id: props.lead.owner_id,
    status_id: props.lead.status_id,
    priority: props.lead.priority,
    source: props.lead.source,
    version: props.lead.version,
    custom_fields: props.lead.custom_fields ?? {},
});
const save = () => {
    form.version = props.lead.version;
    form.patch(`/leads/${props.lead.id}`, { preserveScroll: true });
};
const note = useForm({ body: "" });
const task = useForm({
    title: "",
    assignee_id:
        props.lead.owner_id ?? (page.props.auth as { user: Person }).user.id,
    due_at: "",
    remind_at: "",
    priority: "normal",
});
const localDue = ref("");
const localReminder = ref("");
function addTask() {
    task.due_at = new Date(localDue.value)
        .toISOString()
        .replace(".000Z", "+00:00");
    task.remind_at = new Date(localReminder.value)
        .toISOString()
        .replace(".000Z", "+00:00");
    task.post(`/leads/${props.lead.id}/tasks`, {
        preserveScroll: true,
        onSuccess: () => task.reset("title"),
    });
}
function complete(t: Task) {
    router.patch(
        `/tasks/${t.id}`,
        { status: "completed", version: t.version },
        { preserveScroll: true },
    );
}
const reschedule = ref<number | null>(null);
const rescheduleDue = ref("");
const rescheduleReminder = ref("");
function changeTask(t: Task) {
    router.patch(
        `/tasks/${t.id}`,
        {
            status: "open",
            version: t.version,
            due_at: new Date(rescheduleDue.value)
                .toISOString()
                .replace(".000Z", "+00:00"),
            remind_at: new Date(rescheduleReminder.value)
                .toISOString()
                .replace(".000Z", "+00:00"),
        },
        { preserveScroll: true, onSuccess: () => (reschedule.value = null) },
    );
}
</script>
<template>
    <Head :title="lead.title" /><Link href="/leads" class="crm-back"
        >← Back to leads</Link
    >
    <div class="crm-heading">
        <div>
            <p class="crm-eyebrow">LEAD #{{ lead.id }}</p>
            <h1>{{ lead.title }}</h1>
            <p>
                {{ lead.contact?.name ?? lead.company?.name }} ·
                {{ lead.status.name }}
                <span v-if="lead.archived_at">· Archived</span>
            </p>
        </div>
        <button
            v-if="editable && capabilities.includes('records.archive')"
            class="crm-secondary"
            @click="router.post(`/leads/${lead.id}/archive`)"
        >
            Archive lead
        </button>
    </div>
    <div class="crm-profile-grid">
        <div class="crm-stack">
            <section class="crm-card">
                <h2>Lead details</h2>
                <form class="crm-form-grid" @submit.prevent="save">
                    <label
                        >Title<input
                            v-model="form.title"
                            :disabled="!editable"
                            required /></label
                    ><label
                        >Status<select
                            v-model="form.status_id"
                            :disabled="!editable"
                        >
                            <option
                                v-for="s in statuses"
                                :key="s.id"
                                :value="s.id"
                            >
                                {{ s.name }}
                            </option>
                        </select></label
                    ><label
                        >Owner<select
                            v-model="form.owner_id"
                            :disabled="
                                !editable ||
                                !capabilities.includes('leads.assign')
                            "
                        >
                            <option :value="null">Unassigned</option>
                            <option
                                v-for="o in owners"
                                :key="o.id"
                                :value="o.id"
                            >
                                {{ o.name }}
                            </option>
                        </select></label
                    ><label
                        >Source<input
                            v-model="form.source"
                            :disabled="!editable" /></label
                    ><label
                        >Priority<select
                            v-model="form.priority"
                            :disabled="!editable"
                        >
                            <option>low</option>
                            <option>normal</option>
                            <option>high</option>
                        </select></label
                    ><label v-for="(field, key) in fields" :key="key"
                        >{{ field.label
                        }}<select
                            v-if="field.type === 'select'"
                            v-model="form.custom_fields[key]"
                            :disabled="!editable"
                        >
                            <option v-for="o in field.options" :key="o">
                                {{ o }}
                            </option></select
                        ><input
                            v-else-if="field.type === 'boolean'"
                            v-model="form.custom_fields[key]"
                            type="checkbox"
                            :disabled="!editable" /><input
                            v-else
                            v-model="form.custom_fields[key]"
                            :type="field.type"
                            :disabled="!editable"
                    /></label>
                    <div v-if="editable" class="crm-form-action">
                        <button class="crm-button" :disabled="form.processing">
                            Save changes
                        </button>
                    </div>
                </form>
            </section>
            <section class="crm-card">
                <h2>Notes</h2>
                <form
                    v-if="editable"
                    @submit.prevent="
                        note.post(`/leads/${lead.id}/notes`, {
                            preserveScroll: true,
                            onSuccess: () => note.reset(),
                        })
                    "
                >
                    <label
                        >Add a note<textarea
                            v-model="note.body"
                            required
                            rows="3"
                            placeholder="Capture the conversation…"
                        /></label
                    ><button class="crm-button" :disabled="note.processing">
                        Add note
                    </button>
                </form>
                <p v-if="!lead.notes?.length" class="crm-muted">
                    No notes yet.
                </p>
                <article v-for="n in lead.notes" :key="n.id" class="crm-note">
                    <p>{{ n.body }}</p>
                    <small
                        >{{ n.author.name }} ·
                        {{ date(n.created_at, zone) }}</small
                    >
                </article>
            </section>
            <section v-for="panel in panels" :key="panel.id" class="crm-card">
                <h2>{{ panel.label }}</h2>
                <ExtensionPanel
                    :component="panel.component"
                    :endpoint="panel.endpoint"
                    :lead-id="lead.id"
                    :editable="editable"
                />
            </section>
            <section class="crm-card">
                <h2>Activity</h2>
                <article
                    v-for="a in [...(lead.activity ?? [])].reverse()"
                    :key="a.id"
                    class="crm-activity"
                >
                    <span class="crm-task-dot" />
                    <div>
                        <strong>{{
                            a.event.replaceAll(".", " ").replaceAll("_", " ")
                        }}</strong
                        ><small
                            >{{ a.actor?.name ?? "System" }} ·
                            {{ date(a.created_at, zone) }}</small
                        >
                    </div>
                </article>
            </section>
        </div>
        <aside class="crm-stack">
            <section class="crm-card">
                <h2>Customer</h2>
                <div
                    v-for="person in [lead.contact, lead.company].filter(
                        Boolean,
                    )"
                    :key="person?.id"
                    class="crm-note"
                >
                    <strong>{{ person?.name }}</strong>
                    <p>{{ person?.email ?? "No email" }}</p>
                    <p>{{ person?.phone ?? "No phone" }}</p>
                </div>
            </section>
            <section class="crm-card">
                <h2>Follow-ups</h2>
                <p v-if="!lead.tasks?.length" class="crm-muted">
                    Plan the next conversation.
                </p>
                <article v-for="t in lead.tasks" :key="t.id" class="crm-note">
                    <strong>{{ t.title }}</strong>
                    <p>{{ date(t.due_at, zone) }}</p>
                    <small>{{ t.assignee?.name }} · {{ t.status }}</small>
                    <div
                        v-if="editable && t.status === 'open'"
                        class="crm-inline"
                    >
                        <button class="crm-secondary" @click="complete(t)">
                            Complete</button
                        ><button
                            class="crm-secondary"
                            @click="reschedule = t.id"
                        >
                            Reschedule
                        </button>
                    </div>
                    <form
                        v-if="reschedule === t.id"
                        @submit.prevent="changeTask(t)"
                    >
                        <label
                            >New deadline<input
                                v-model="rescheduleDue"
                                type="datetime-local"
                                required /></label
                        ><label
                            >New reminder<input
                                v-model="rescheduleReminder"
                                type="datetime-local"
                                required /></label
                        ><button class="crm-button">
                            Reschedule follow-up
                        </button>
                    </form>
                </article>
                <form
                    v-if="editable"
                    class="crm-stack"
                    @submit.prevent="addTask"
                >
                    <h3>Schedule a follow-up</h3>
                    <label
                        >Follow-up title<input
                            v-model="task.title"
                            required /></label
                    ><label
                        >Assignee<select v-model="task.assignee_id">
                            <option
                                v-for="o in owners"
                                :key="o.id"
                                :value="o.id"
                            >
                                {{ o.name }}
                            </option>
                        </select></label
                    ><label
                        >Due date & time<input
                            v-model="localDue"
                            type="datetime-local"
                            required /></label
                    ><label
                        >Reminder time<input
                            v-model="localReminder"
                            type="datetime-local"
                            required /></label
                    ><small
                        >Times entered in your device timezone; displayed in
                        {{ zone }}.</small
                    ><label
                        >Priority<select v-model="task.priority">
                            <option>low</option>
                            <option>normal</option>
                            <option>high</option>
                        </select></label
                    ><button class="crm-button" :disabled="task.processing">
                        Schedule follow-up
                    </button>
                </form>
            </section>
        </aside>
    </div>
</template>
