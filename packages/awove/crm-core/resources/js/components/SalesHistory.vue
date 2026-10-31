<script setup lang="ts">
import { t } from "@/lib/i18n";
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
            warning.value = t(
                "The connection was interrupted. Reload the page to check whether your quotation was saved. Your draft has been kept.",
            );
            return false;
        },
        onHttpException: (response: { status: number }) => {
            if (response.status === 403) {
                warning.value = t(
                    "You no longer have permission to edit this quotation. Your draft has been kept.",
                );
                return false;
            }
            if (response.status === 409 || response.status === 422) {
                warning.value = t(
                    "This quotation changed or was accepted in another session. Reload the page before editing it.",
                );
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
        <h2 id="quotations-title">{{ t("Quotations") }}</h2>
        <p class="crm-muted">
            {{
                t(
                    "Record a quotation against an uploaded proposal version or a document link. Accepted quotations are retained unchanged; create a new quotation for a revision. External links retain the reference, not a local copy of the external document.",
                )
            }}
        </p>
        <form
            v-if="editable"
            class="crm-form-grid"
            @submit.prevent="saveQuotation"
        >
            <label
                ><span class="crm-field-label">{{ t("Quotation number") }}</span
                ><input v-model="quotation.number" maxlength="100" required
            /></label>
            <label
                ><span class="crm-field-label"
                    >{{ t("Amount (") }}{{ currency }})</span
                ><input
                    v-model="quotation.amount"
                    type="text"
                    inputmode="decimal"
                    pattern="[0-9]+([.][0-9]{1,2})?"
                    maxlength="15"
                    required
            /></label>
            <label
                ><span class="crm-field-label">{{ t("Quotation status") }}</span
                ><select v-model="quotation.status">
                    <option value="draft">{{ t("Draft") }}</option>
                    <option value="sent">{{ t("Sent") }}</option>
                    <option value="accepted">{{ t("Accepted") }}</option>
                    <option value="rejected">{{ t("Rejected") }}</option>
                </select></label
            >
            <label
                ><span class="crm-field-label">{{ t("Sent date") }}</span
                ><input
                    v-model="quotation.sent_date"
                    type="date"
                    :required="['sent', 'accepted'].includes(quotation.status)"
            /></label>
            <label
                ><span class="crm-field-label">{{
                    t("Uploaded proposal version")
                }}</span
                ><select
                    v-model="quotation.proposal_id"
                    @change="quotation.document_url = ''"
                >
                    <option :value="null">
                        {{ t("Use a document link") }}
                    </option>
                    <option
                        v-for="proposal in proposals"
                        :key="proposal.id"
                        :value="proposal.id"
                    >
                        {{ t("Version") }} {{ proposal.version }} ·
                        {{ proposal.original_name }}
                    </option>
                </select></label
            >
            <label v-if="quotation.proposal_id === null"
                ><span class="crm-field-label">{{ t("Document link") }}</span
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
                            ? t("Saving…")
                            : editing === null
                              ? t("Add quotation")
                              : t("Save quotation")
                    }}</button
                ><button
                    v-if="editing !== null"
                    type="button"
                    class="crm-secondary"
                    @click="resetQuotation"
                >
                    {{ t("Cancel edit") }}
                </button>
            </div>
        </form>
        <p v-if="!quotations.length" class="crm-muted">
            {{ t("No quotations recorded.") }}
        </p>
        <article
            v-for="item in quotations"
            :key="item.id"
            class="crm-note crm-flow"
        >
            <div class="crm-inline">
                <strong>{{ item.number }}</strong
                ><span class="crm-badge">{{
                    t(
                        item.status.charAt(0).toUpperCase() +
                            item.status.slice(1),
                    )
                }}</span>
            </div>
            <p>
                {{ t("Amount") }} {{ item.amount
                }}<span v-if="item.sent_date">
                    {{ t("· Sent") }} {{ item.sent_date }}</span
                ><span v-if="item.accepted_at">
                    {{ t("· Accepted") }}
                    {{ date(item.accepted_at, timezone) }}</span
                >
            </p>
            <div class="crm-form-action">
                <a
                    v-if="item.download_url"
                    class="crm-secondary"
                    :href="item.download_url"
                    >{{ t("Download retained document") }}</a
                ><a
                    v-else-if="item.document_url"
                    class="crm-secondary"
                    :href="item.document_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    >{{ t("Open document link") }}</a
                ><button
                    v-if="editable && item.status !== 'accepted'"
                    type="button"
                    class="crm-secondary"
                    @click="edit(item)"
                >
                    {{ t("Edit quotation") }}
                </button>
            </div>
        </article>
    </section>
    <section class="crm-card crm-flow" aria-labelledby="communications-title">
        <h2 id="communications-title">{{ t("Communication history") }}</h2>
        <form
            v-if="editable"
            class="crm-form"
            @submit.prevent="recordCommunication"
        >
            <div class="crm-form-grid">
                <label
                    ><span class="crm-field-label">{{
                        t("Communication type")
                    }}</span
                    ><select v-model="communication.type">
                        <option value="call">{{ t("Call") }}</option>
                        <option value="meeting">{{ t("Meeting") }}</option>
                        <option value="email">{{ t("Email") }}</option>
                        <option value="message">{{ t("Message") }}</option>
                    </select></label
                ><label
                    ><span class="crm-field-label">{{
                        t("Communication date and time")
                    }}</span
                    ><input
                        v-model="interactionTime"
                        type="datetime-local"
                        required
                /></label>
            </div>
            <small class="crm-muted"
                >{{
                    t(
                        "Enter time in your device timezone; history is displayed in",
                    )
                }}
                {{ timezone
                }}{{
                    t(". The staff member is recorded automatically.")
                }}</small
            >
            <label
                ><span class="crm-field-label">{{
                    t("Communication summary")
                }}</span
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
                            ? t("Recording…")
                            : t("Record communication")
                    }}
                </button>
            </div>
        </form>
        <p v-if="!communications.length" class="crm-muted">
            {{ t("No communications recorded.") }}
        </p>
        <article v-for="item in communications" :key="item.id" class="crm-note">
            <strong>{{
                t(item.type.charAt(0).toUpperCase() + item.type.slice(1))
            }}</strong>
            <p dir="auto">{{ item.summary }}</p>
            <small
                >{{ item.staff?.name ?? t("Former staff member") }} ·
                {{ date(item.occurred_at, timezone) }}</small
            >
        </article>
    </section>
</template>
