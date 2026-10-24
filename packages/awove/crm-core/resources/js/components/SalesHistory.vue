<script setup lang="ts">
import { useForm, usePage } from "@inertiajs/vue3";
import { ref } from "vue";
import { date } from "../types";

export interface Quotation {
    id: number;
    number: string;
    amount: string;
    sent_date: string | null;
    status: "draft" | "sent" | "accepted" | "rejected";
    proposal_id: number | null;
    document_url: string | null;
    download_url: string | null;
    accepted_at: string | null;
    version: number;
}
export interface Communication {
    id: number;
    type: string;
    summary: string;
    occurred_at: string;
    staff: { id: number; name: string } | null;
}
const props = defineProps<{
    leadId: number;
    editable: boolean;
    timezone: string;
    communications: Communication[];
    quotations: Quotation[];
    proposals: { id: number; version: number; original_name: string }[];
}>();
const currency = (usePage().props.brand as { currency: string }).currency;
const interactionTime = ref("");
const communication = useForm({ type: "call", occurred_at: "", summary: "" });
function recordCommunication() {
    communication.occurred_at = new Date(interactionTime.value)
        .toISOString()
        .replace(".000Z", "+00:00");
    communication.post(`/leads/${props.leadId}/communications`, {
        preserveScroll: true,
        onSuccess: () => {
            communication.reset("summary");
        },
    });
}
const editing = ref<number | null>(null);
const quotation = useForm({
    number: "",
    amount: "",
    sent_date: "",
    status: "draft",
    proposal_id: null as number | null,
    document_url: "",
    version: 1,
});
const warning = ref("");
function edit(item: Quotation) {
    editing.value = item.id;
    quotation.defaults({
        number: item.number,
        amount: item.amount,
        sent_date: item.sent_date ?? "",
        status: item.status,
        proposal_id: item.proposal_id,
        document_url: item.document_url ?? "",
        version: item.version,
    });
    quotation.reset();
    quotation.clearErrors();
    warning.value = "";
}
function resetQuotation() {
    editing.value = null;
    quotation.defaults({
        number: "",
        amount: "",
        sent_date: "",
        status: "draft",
        proposal_id: null,
        document_url: "",
        version: 1,
    });
    quotation.reset();
    quotation.clearErrors();
    warning.value = "";
}
function saveQuotation() {
    warning.value = "";
    quotation.transform(({ version, ...data }) =>
        editing.value === null ? data : { ...data, version },
    );
    const options = {
        preserveScroll: true,
        onSuccess: resetQuotation,
        onNetworkError: () => {
            warning.value =
                "The connection was interrupted. Reload the page to check whether your quotation was saved. Your draft has been kept.";
            return false;
        },
        onHttpException: (response: { status: number }) => {
            if (response.status === 403) {
                warning.value =
                    "You no longer have permission to edit this quotation. Your draft has been kept.";
                return false;
            }
            if (response.status === 409 || response.status === 422) {
                warning.value =
                    "This quotation changed or was accepted in another session. Reload the page before editing it.";
                return false;
            }
        },
    };
    if (editing.value === null)
        quotation.post(`/leads/${props.leadId}/quotations`, options);
    else
        quotation.patch(
            `/leads/${props.leadId}/quotations/${editing.value}`,
            options,
        );
}
</script>
<template>
    <section class="crm-card crm-flow" aria-labelledby="quotations-title">
        <h2 id="quotations-title">Quotations</h2>
        <p class="crm-muted">
            Record a quotation against an uploaded proposal version or a
            document link. Accepted quotations are retained unchanged; create a
            new quotation for a revision. External links retain the reference,
            not a local copy of the external document.
        </p>
        <form
            v-if="editable"
            class="crm-form-grid"
            @submit.prevent="saveQuotation"
        >
            <label
                ><span class="crm-field-label">Quotation number</span
                ><input v-model="quotation.number" maxlength="100" required
            /></label>
            <label
                ><span class="crm-field-label">Amount ({{ currency }})</span
                ><input
                    v-model="quotation.amount"
                    type="text"
                    inputmode="decimal"
                    pattern="[0-9]+([.][0-9]{1,2})?"
                    maxlength="15"
                    required
            /></label>
            <label
                ><span class="crm-field-label">Quotation status</span
                ><select v-model="quotation.status">
                    <option value="draft">Draft</option>
                    <option value="sent">Sent</option>
                    <option value="accepted">Accepted</option>
                    <option value="rejected">Rejected</option>
                </select></label
            >
            <label
                ><span class="crm-field-label">Sent date</span
                ><input
                    v-model="quotation.sent_date"
                    type="date"
                    :required="['sent', 'accepted'].includes(quotation.status)"
            /></label>
            <label
                ><span class="crm-field-label">Uploaded proposal version</span
                ><select
                    v-model="quotation.proposal_id"
                    @change="quotation.document_url = ''"
                >
                    <option :value="null">Use a document link</option>
                    <option
                        v-for="proposal in proposals"
                        :key="proposal.id"
                        :value="proposal.id"
                    >
                        Version {{ proposal.version }} ·
                        {{ proposal.original_name }}
                    </option>
                </select></label
            >
            <label v-if="quotation.proposal_id === null"
                ><span class="crm-field-label">Document link</span
                ><input
                    v-model="quotation.document_url"
                    type="url"
                    maxlength="2048"
                    required
                    placeholder="https://"
            /></label>
            <div
                v-if="Object.keys(quotation.errors).length || warning"
                class="crm-form-action crm-error"
                role="alert"
            >
                <p v-for="(error, field) in quotation.errors" :key="field">
                    {{ error }}
                </p>
                <p v-if="warning">{{ warning }}</p>
            </div>
            <div class="crm-form-action">
                <button class="crm-button" :disabled="quotation.processing">
                    {{
                        quotation.processing
                            ? "Saving…"
                            : editing === null
                              ? "Add quotation"
                              : "Save quotation"
                    }}</button
                ><button
                    v-if="editing !== null"
                    type="button"
                    class="crm-secondary"
                    @click="resetQuotation"
                >
                    Cancel edit
                </button>
            </div>
        </form>
        <p v-if="!quotations.length" class="crm-muted">
            No quotations recorded.
        </p>
        <article
            v-for="item in quotations"
            :key="item.id"
            class="crm-note crm-flow"
        >
            <div class="crm-inline">
                <strong>{{ item.number }}</strong
                ><span class="crm-badge">{{
                    item.status.charAt(0).toUpperCase() + item.status.slice(1)
                }}</span>
            </div>
            <p>
                Amount {{ item.amount
                }}<span v-if="item.sent_date"> · Sent {{ item.sent_date }}</span
                ><span v-if="item.accepted_at">
                    · Accepted {{ date(item.accepted_at, timezone) }}</span
                >
            </p>
            <div class="crm-form-action">
                <a
                    v-if="item.download_url"
                    class="crm-secondary"
                    :href="item.download_url"
                    >Download retained document</a
                ><a
                    v-else-if="item.document_url"
                    class="crm-secondary"
                    :href="item.document_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    >Open document link</a
                ><button
                    v-if="editable && item.status !== 'accepted'"
                    type="button"
                    class="crm-secondary"
                    @click="edit(item)"
                >
                    Edit quotation
                </button>
            </div>
        </article>
    </section>
    <section class="crm-card crm-flow" aria-labelledby="communications-title">
        <h2 id="communications-title">Communication history</h2>
        <form
            v-if="editable"
            class="crm-form"
            @submit.prevent="recordCommunication"
        >
            <div class="crm-form-grid">
                <label
                    ><span class="crm-field-label">Communication type</span
                    ><select v-model="communication.type">
                        <option value="call">Call</option>
                        <option value="meeting">Meeting</option>
                        <option value="email">Email</option>
                        <option value="message">Message</option>
                    </select></label
                ><label
                    ><span class="crm-field-label"
                        >Communication date and time</span
                    ><input
                        v-model="interactionTime"
                        type="datetime-local"
                        required
                /></label>
            </div>
            <small class="crm-muted"
                >Enter time in your device timezone; history is displayed in
                {{ timezone }}. The staff member is recorded
                automatically.</small
            >
            <label
                ><span class="crm-field-label">Communication summary</span
                ><textarea
                    v-model="communication.summary"
                    required
                    maxlength="10000"
                    rows="3"
                />
            </label>
            <p
                v-for="(error, field) in communication.errors"
                :key="field"
                class="crm-error"
                role="alert"
            >
                {{ error }}
            </p>
            <div class="crm-form-action">
                <button class="crm-button" :disabled="communication.processing">
                    {{
                        communication.processing
                            ? "Recording…"
                            : "Record communication"
                    }}
                </button>
            </div>
        </form>
        <p v-if="!communications.length" class="crm-muted">
            No communications recorded.
        </p>
        <article v-for="item in communications" :key="item.id" class="crm-note">
            <strong>{{
                item.type.charAt(0).toUpperCase() + item.type.slice(1)
            }}</strong>
            <p>{{ item.summary }}</p>
            <small
                >{{ item.staff?.name ?? "Former staff member" }} ·
                {{ date(item.occurred_at, timezone) }}</small
            >
        </article>
    </section>
</template>
