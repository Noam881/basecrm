<script setup lang="ts">
import { t } from "@/lib/i18n";
import { Head, Link, useForm, usePage, router } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import {
    date,
    type Lead,
    type Person,
    type Stage,
    type Field,
    type Task,
} from "../../types";
import ExtensionPanel from "../../components/ExtensionPanel.vue";
import StageControl from "../../components/StageControl.vue";
import LeadProposals from "../../components/LeadProposals.vue";
import SalesHistory, {
    type Communication,
    type Quotation,
} from "../../components/SalesHistory.vue";
const props = defineProps<{
    lead: Lead;
    owners: Person[];
    statuses: Stage[];
    fields: Record<string, Field>;
    editable: boolean;
    proposals: {
        id: number;
        version: number;
        original_name: string;
        mime_type: string;
        size_bytes: number;
        created_at: string;
        uploaded_by: { id: number; name: string } | null;
        download_url: string;
    }[];
    sales: { communications: Communication[]; quotations: Quotation[] };
    identityPermissions: { contact: boolean; company: boolean };
    panels: {
        id: string;
        label: string;
        component: string;
        endpoint: string;
    }[];
}>();
const page = usePage();
const zone = (page.props.brand as { timezone: string }).timezone;
const currency = (page.props.brand as { currency: string }).currency;
const capabilities = page.props.capabilities as string[];
const customerName = computed(
    () =>
        props.lead.contact?.name ??
        props.lead.company?.name ??
        props.lead.title,
);
function leadDetails(lead: Lead) {
    return {
        title: lead.title,
        owner_id: lead.owner_id,
        priority: lead.priority,
        source: lead.source,
        needs: lead.needs ?? "",
        estimated_value: lead.estimated_value ?? "",
        closing_date: lead.closing_date ?? "",
        final_value: lead.final_value ?? "",
        loss_reason: lead.loss_reason ?? "",
        custom_fields: JSON.parse(
            JSON.stringify(lead.custom_fields ?? {}),
        ) as Lead["custom_fields"],
    };
}
function detailFingerprint(details: ReturnType<typeof leadDetails>) {
    const sorted = (values: object) =>
        Object.entries(values).sort(([left], [right]) =>
            left.localeCompare(right),
        );
    return JSON.stringify({
        ...details,
        custom_fields: sorted(details.custom_fields),
    });
}
const form = useForm({
    ...leadDetails(props.lead),
    version: props.lead.version,
});
let detailBaseline = detailFingerprint(leadDetails(props.lead));
const detailsChanged = ref(false);
const detailsWarning = ref("");
const detailsPermissionDenied = ref(false);
const reloadingDetails = ref(false);
const detailsDisabled = computed(
    () =>
        !props.editable ||
        form.processing ||
        reloadingDetails.value ||
        detailsPermissionDenied.value,
);
function markDetailsChanged(
    message = t(
        "Lead details changed in another session. Your unsaved edits are kept. Reload lead details before saving. Reloading will replace your unsaved lead details.",
    ),
) {
    detailsChanged.value = true;
    detailsWarning.value = message;
}
// A stage refresh may advance the row version without making an older detail draft safe to save.
watch(
    () =>
        [
            props.lead.version,
            detailFingerprint(leadDetails(props.lead)),
        ] as const,
    ([version, fingerprint]) => {
        if (detailsChanged.value) return;
        if (fingerprint === detailBaseline) {
            form.version = version;
            form.defaults("version", version);
        } else {
            markDetailsChanged();
        }
    },
);
function acceptDetails(lead: Lead) {
    const details = leadDetails(lead);
    detailBaseline = detailFingerprint(details);
    form.defaults({ ...details, version: lead.version });
    form.reset();
    form.clearErrors();
    detailsChanged.value = false;
    detailsWarning.value = "";
    detailsPermissionDenied.value = false;
}
function detailsException(response: { status: number }) {
    if (response.status === 409) {
        markDetailsChanged();
        return false;
    }
    if (response.status === 403) {
        detailsPermissionDenied.value = true;
        markDetailsChanged(
            t(
                "You no longer have permission to edit this lead. Your unsaved details are kept and have not been saved.",
            ),
        );
        return false;
    }
}
function save() {
    if (detailsDisabled.value || detailsChanged.value) return;
    form.patch(`/leads/${props.lead.id}`, {
        preserveScroll: true,
        errorBag: "lead_details",
        onSuccess: (savedPage) => acceptDetails(savedPage.props.lead as Lead),
        onHttpException: detailsException,
        onNetworkError: () => {
            markDetailsChanged(
                t(
                    "The connection was interrupted. Reload lead details to check whether your changes were saved. Reloading will replace your unsaved lead details.",
                ),
            );
            return false;
        },
    });
}
function reloadDetails() {
    if (
        form.processing ||
        reloadingDetails.value ||
        detailsPermissionDenied.value
    )
        return;
    reloadingDetails.value = true;
    router.reload({
        only: ["lead", "editable"],
        onSuccess: (savedPage) => acceptDetails(savedPage.props.lead as Lead),
        onHttpException: detailsException,
        onNetworkError: () => {
            markDetailsChanged(
                t(
                    "Lead details could not be reloaded. Your unsaved edits are kept. Check your connection and try again.",
                ),
            );
            return false;
        },
        onFinish: () => {
            reloadingDetails.value = false;
        },
    });
}
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
    <Head :title="customerName" /><Link href="/leads" class="crm-back">{{
        t("← Back to leads")
    }}</Link>
    <div class="crm-heading">
        <h1>{{ customerName }}</h1>
        <button
            v-if="editable && capabilities.includes('records.archive')"
            class="crm-secondary"
            @click="router.post(`/leads/${lead.id}/archive`)"
        >
            {{ t("Archive lead") }}
        </button>
    </div>
    <div class="crm-profile-grid">
        <div class="crm-stack">
            <section class="crm-card crm-flow">
                <h2>{{ t("Lead details") }}</h2>
                <p class="crm-muted">
                    {{ t("Lead #") }}{{ lead.id }} ·
                    {{ lead.contact?.name ?? lead.company?.name }} ·
                    {{ lead.status.name }}
                    <span v-if="lead.archived_at">{{ t("· Archived") }}</span>
                </p>
                <p class="crm-muted">
                    {{ t("Created") }}
                    <time :datetime="lead.created_at">{{
                        date(lead.created_at, zone)
                    }}</time>
                </p>
                <div class="crm-field">
                    <span>{{ t("Stage") }}</span>
                    <StageControl
                        :lead-id="lead.id"
                        :record-name="customerName"
                        :status-id="lead.status_id"
                        :version="lead.version"
                        :statuses="statuses"
                        :editable="editable"
                    />
                </div>
                <form class="crm-form-grid" @submit.prevent="save">
                    <label
                        ><span class="crm-field-label">{{
                            t("Enquiry title (optional)")
                        }}</span
                        ><input
                            v-model="form.title"
                            :disabled="detailsDisabled"
                            maxlength="160" /></label
                    ><label
                        ><span class="crm-field-label">{{ t("Owner") }}</span
                        ><select
                            v-model="form.owner_id"
                            :disabled="
                                detailsDisabled ||
                                !capabilities.includes('leads.assign')
                            "
                        >
                            <option :value="null">{{ t("Unassigned") }}</option>
                            <option
                                dir="auto"
                                v-for="o in owners"
                                :key="o.id"
                                :value="o.id"
                            >
                                {{ o.name }}
                            </option>
                        </select></label
                    ><label
                        ><span class="crm-field-label">{{ t("Source") }}</span
                        ><input
                            v-model="form.source"
                            :disabled="detailsDisabled" /></label
                    ><label
                        ><span class="crm-field-label">{{ t("Priority") }}</span
                        ><select
                            v-model="form.priority"
                            :disabled="detailsDisabled"
                        >
                            <option value="low">{{ t("low") }}</option>
                            <option value="normal">{{ t("normal") }}</option>
                            <option value="high">{{ t("high") }}</option>
                        </select></label
                    ><label
                        ><span class="crm-field-label">{{ t("Needs") }}</span
                        ><textarea
                            v-model="form.needs"
                            :disabled="detailsDisabled"
                            maxlength="10000"
                            rows="3"
                        />
                    </label>
                    <label
                        ><span class="crm-field-label"
                            >{{ t("Estimated value (") }}{{ currency }})</span
                        ><input
                            v-model="form.estimated_value"
                            :disabled="detailsDisabled"
                            type="text"
                            inputmode="decimal"
                            pattern="[0-9]+([.][0-9]{1,2})?"
                            maxlength="15"
                    /></label>
                    <label
                        ><span class="crm-field-label">{{
                            t("Closing date")
                        }}</span
                        ><input
                            v-model="form.closing_date"
                            :disabled="detailsDisabled"
                            type="date"
                    /></label>
                    <label
                        ><span class="crm-field-label"
                            >{{ t("Final deal value (") }}{{ currency }})</span
                        ><input
                            v-model="form.final_value"
                            :disabled="detailsDisabled"
                            type="text"
                            inputmode="decimal"
                            pattern="[0-9]+([.][0-9]{1,2})?"
                            maxlength="15"
                    /></label>
                    <label
                        ><span class="crm-field-label">{{
                            t("Loss reason")
                        }}</span
                        ><textarea
                            v-model="form.loss_reason"
                            :disabled="detailsDisabled"
                            maxlength="10000"
                            rows="2"
                        />
                    </label>
                    <p class="crm-muted">
                        {{
                            t(
                                "Record the closing date and final value for won deals, or the reason when a deal is lost. Amounts use the installation currency.",
                            )
                        }}
                    </p>
                    <label v-for="(field, key) in fields" :key="key"
                        ><span class="crm-field-label">{{ field.label }}</span
                        ><select
                            v-if="field.type === 'select'"
                            v-model="form.custom_fields[key]"
                            :disabled="detailsDisabled"
                        >
                            <option
                                v-for="o in field.options"
                                :key="o"
                                :value="o"
                            >
                                {{ o }}
                            </option></select
                        ><input
                            v-else-if="field.type === 'boolean'"
                            v-model="form.custom_fields[key]"
                            type="checkbox"
                            :disabled="detailsDisabled" /><input
                            v-else
                            v-model="form.custom_fields[key]"
                            :type="field.type"
                            :disabled="detailsDisabled"
                    /></label>
                    <div
                        v-if="Object.keys(form.errors).length"
                        class="crm-form-action crm-error"
                        role="alert"
                    >
                        <p v-for="(error, field) in form.errors" :key="field">
                            {{ error }}
                        </p>
                    </div>
                    <div v-if="detailsWarning" class="crm-form-action">
                        <div class="crm-notice-panel crm-flow" role="alert">
                            <p>{{ detailsWarning }}</p>
                            <div
                                v-if="!detailsPermissionDenied"
                                class="crm-form-action"
                            >
                                <button
                                    type="button"
                                    class="crm-secondary"
                                    :disabled="reloadingDetails"
                                    @click="reloadDetails"
                                >
                                    {{
                                        reloadingDetails
                                            ? t("Reloading…")
                                            : t("Reload lead details")
                                    }}
                                </button>
                            </div>
                        </div>
                    </div>
                    <div v-if="editable" class="crm-form-action">
                        <button
                            class="crm-button"
                            :disabled="detailsDisabled || detailsChanged"
                        >
                            {{ t("Save changes") }}
                        </button>
                    </div>
                </form>
            </section>
            <LeadProposals
                :lead-id="lead.id"
                :editable="editable"
                :proposals="proposals"
                :timezone="zone"
            />
            <SalesHistory
                :lead-id="lead.id"
                :editable="editable"
                :timezone="zone"
                :communications="sales.communications"
                :quotations="sales.quotations"
                :proposals="proposals"
            />
            <section class="crm-card crm-flow">
                <h2>{{ t("Notes") }}</h2>
                <form
                    v-if="editable"
                    class="crm-form"
                    @submit.prevent="
                        note.post(`/leads/${lead.id}/notes`, {
                            preserveScroll: true,
                            onSuccess: () => note.reset(),
                        })
                    "
                >
                    <label
                        ><span class="crm-field-label">{{
                            t("Add a note")
                        }}</span
                        ><textarea
                            v-model="note.body"
                            required
                            rows="3"
                            :placeholder="t('Capture the conversation…')"
                        /></label
                    ><button class="crm-button" :disabled="note.processing">
                        {{ t("Add note") }}
                    </button>
                </form>
                <p v-if="!lead.notes?.length" class="crm-muted">
                    {{ t("No notes yet.") }}
                </p>
                <article v-for="n in lead.notes" :key="n.id" class="crm-note">
                    <p dir="auto">{{ n.body }}</p>
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
                <h2>{{ t("Activity") }}</h2>
                <article
                    v-for="a in [...(lead.activity ?? [])].reverse()"
                    :key="a.id"
                    class="crm-activity"
                >
                    <span class="crm-task-dot" />
                    <div>
                        <strong>{{
                            t(a.event.replaceAll(".", " ").replaceAll("_", " "))
                        }}</strong
                        ><small
                            >{{ a.actor?.name ?? t("System") }} ·
                            {{ date(a.created_at, zone) }}</small
                        >
                    </div>
                </article>
            </section>
        </div>
        <aside class="crm-stack">
            <section class="crm-card">
                <h2>{{ t("Customer") }}</h2>
                <div
                    v-for="item in [
                        {
                            type: 'contacts',
                            editLabel: t('Edit contact'),
                            person: lead.contact,
                            editable: identityPermissions.contact,
                        },
                        {
                            type: 'companies',
                            editLabel: t('Edit company'),
                            person: lead.company,
                            editable: identityPermissions.company,
                        },
                    ].filter((item) => item.person)"
                    :key="item.type"
                    class="crm-note"
                >
                    <strong
                        ><Link
                            :href="`/people/${item.type}/${item.person?.id}`"
                            >{{ item.person?.name }}</Link
                        ></strong
                    >
                    <p>{{ item.person?.email ?? t("No email") }}</p>
                    <p>{{ item.person?.phone ?? t("No phone") }}</p>
                    <div v-if="item.editable" class="crm-form-action">
                        <Link
                            :href="`/people/${item.type}/${item.person?.id}?from_lead=${lead.id}`"
                            class="crm-secondary"
                            >{{ item.editLabel }}</Link
                        >
                    </div>
                </div>
            </section>
            <section class="crm-card crm-flow">
                <h2>{{ t("Follow-ups") }}</h2>
                <p v-if="!lead.tasks?.length" class="crm-muted">
                    {{ t("No follow-ups scheduled.") }}
                </p>
                <article
                    v-for="task in lead.tasks"
                    :key="task.id"
                    class="crm-note"
                >
                    <strong dir="auto">{{ task.title }}</strong>
                    <p>{{ date(task.due_at, zone) }}</p>
                    <small
                        >{{ task.assignee?.name }} · {{ t(task.status) }}</small
                    >
                    <div
                        v-if="editable && task.status === 'open'"
                        class="crm-inline"
                    >
                        <button class="crm-secondary" @click="complete(task)">
                            {{ t("Complete") }}</button
                        ><button
                            class="crm-secondary"
                            @click="reschedule = task.id"
                        >
                            {{ t("Reschedule") }}
                        </button>
                    </div>
                    <form
                        v-if="reschedule === task.id"
                        class="crm-form"
                        @submit.prevent="changeTask(task)"
                    >
                        <label
                            ><span class="crm-field-label">{{
                                t("New deadline")
                            }}</span
                            ><input
                                v-model="rescheduleDue"
                                type="datetime-local"
                                required /></label
                        ><label
                            ><span class="crm-field-label">{{
                                t("New reminder")
                            }}</span
                            ><input
                                v-model="rescheduleReminder"
                                type="datetime-local"
                                required /></label
                        ><button class="crm-button">
                            {{ t("Reschedule follow-up") }}
                        </button>
                    </form>
                </article>
                <form
                    v-if="editable"
                    class="crm-form"
                    @submit.prevent="addTask"
                >
                    <h3>{{ t("Schedule a follow-up") }}</h3>
                    <label
                        ><span class="crm-field-label">{{
                            t("Follow-up title")
                        }}</span
                        ><input v-model="task.title" required /></label
                    ><label
                        ><span class="crm-field-label">{{ t("Assignee") }}</span
                        ><select v-model="task.assignee_id">
                            <option
                                dir="auto"
                                v-for="o in owners"
                                :key="o.id"
                                :value="o.id"
                            >
                                {{ o.name }}
                            </option>
                        </select></label
                    ><label
                        ><span class="crm-field-label">{{
                            t("Due date & time")
                        }}</span
                        ><input
                            v-model="localDue"
                            type="datetime-local"
                            required /></label
                    ><label
                        ><span class="crm-field-label">{{
                            t("Reminder time")
                        }}</span
                        ><input
                            v-model="localReminder"
                            type="datetime-local"
                            required /></label
                    ><small
                        >{{
                            t(
                                "Times entered in your device timezone; displayed in",
                            )
                        }}
                        {{ zone }}.</small
                    ><label
                        ><span class="crm-field-label">{{ t("Priority") }}</span
                        ><select v-model="task.priority">
                            <option value="low">{{ t("low") }}</option>
                            <option value="normal">{{ t("normal") }}</option>
                            <option value="high">{{ t("high") }}</option>
                        </select></label
                    ><button class="crm-button" :disabled="task.processing">
                        {{ t("Schedule follow-up") }}
                    </button>
                </form>
            </section>
        </aside>
    </div>
</template>
