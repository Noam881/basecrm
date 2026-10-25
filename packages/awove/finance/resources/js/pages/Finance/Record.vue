<script setup lang="ts">
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import FinanceNav from "../../components/FinanceNav.vue";
import LedgerEditor from "../../components/LedgerEditor.vue";
import LedgerHistory from "../../components/LedgerHistory.vue";
import type { Options, LedgerEntry, Payment, History } from "../../types";
const page = usePage();
const props = defineProps<
    Options & { record: LedgerEntry; payments: Payment[]; history: History[] }
>();
const payment = useForm({
    version: props.record.version,
    amount: "",
    paid_on: props.today,
    reference: "",
    notes: "",
    submission_key: crypto.randomUUID(),
});
const cancellation = useForm({
    version: props.record.version,
    cancellation_reason: "",
});
function recordPayment() {
    payment.version = props.record.version;
    payment.post(`/finance/${props.cap}/${props.record.id}/payments`, {
        preserveScroll: true,
        onSuccess: () => {
            payment.reset("amount", "reference", "notes");
            payment.submission_key = crypto.randomUUID();
        },
    });
}
function cancel() {
    cancellation.version = props.record.version;
    cancellation.post(`/finance/${props.cap}/${props.record.id}/cancel`, {
        preserveScroll: true,
    });
}
</script>
<template>
    <Head :title="record.reference" /><Link
        class="crm-back"
        :href="`/finance/${cap}`"
        >←
        {{
            cap === "collections" ? "Customer charges" : "Supplier expenses"
        }}</Link
    >
    <div class="crm-heading">
        <h1>{{ record.reference }}</h1>
        <span class="crm-badge">{{ statuses[record.status] }}</span>
    </div>
    <div class="crm-flow">
        <FinanceNav :sections="sections" />
        <section class="crm-card crm-flow">
            <dl class="crm-detail-grid">
                <div class="crm-detail-item">
                    <dt>
                        {{ cap === "collections" ? "Customer" : "Supplier" }}
                    </dt>
                    <dd>
                        {{
                            record.relationship?.name ??
                            "Classification unavailable"
                        }}
                    </dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Owner</dt>
                    <dd>{{ record.owner?.name ?? "Unassigned" }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Total payable</dt>
                    <dd>{{ record.total }} {{ record.currency }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Recorded payments</dt>
                    <dd>{{ record.paid_amount }} {{ record.currency }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Remaining balance</dt>
                    <dd>{{ record.remaining_amount }} {{ record.currency }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Due date</dt>
                    <dd>{{ record.due_on }}</dd>
                </div>
                <div v-if="record.category" class="crm-detail-item">
                    <dt>Category</dt>
                    <dd>{{ record.category }}</dd>
                </div>
            </dl>
            <p>{{ record.description }}</p>
            <p v-if="record.next_action">
                Next action: {{ record.next_action }} ·
                {{ record.next_action_on }}
            </p>
            <a
                v-if="record.document_link"
                class="crm-secondary"
                :href="record.document_link"
                target="_blank"
                rel="noopener noreferrer"
                >Open document</a
            >
            <p v-if="record.cancelled_at">
                Cancelled · {{ record.cancellation_reason }}. Retained balance
                is excluded from expected payments.
            </p>
        </section>
        <section v-if="record.editable" class="crm-card crm-flow">
            <h2>Edit record</h2>
            <LedgerEditor :key="record.version" v-bind="props" />
        </section>
        <section
            v-if="
                record.editable &&
                canRecordPayment &&
                record.remaining_amount !== '0.00'
            "
            class="crm-card crm-flow"
            aria-label="Record payment"
        >
            <h2>
                {{
                    cap === "collections"
                        ? "Record received payment"
                        : "Record supplier payment"
                }}
            </h2>
            <form class="crm-form-grid" @submit.prevent="recordPayment">
                <label class="crm-field"
                    ><span class="crm-field-label"
                        >Payment amount ({{ record.currency }})</span
                    ><input
                        v-model="payment.amount"
                        inputmode="decimal"
                        required
                        placeholder="0.00"
                    /><small
                        v-if="payment.errors.amount"
                        role="alert"
                        data-slot="input-error"
                        >{{ payment.errors.amount }}</small
                    ></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Payment date</span
                    ><input
                        v-model="payment.paid_on"
                        type="date"
                        required
                    /><small
                        v-if="payment.errors.paid_on"
                        role="alert"
                        data-slot="input-error"
                        >{{ payment.errors.paid_on }}</small
                    ></label
                ><label class="crm-field"
                    ><span class="crm-field-label"
                        >Payment reference (optional)</span
                    ><input
                        v-model="payment.reference"
                        maxlength="120" /></label
                ><label class="crm-field crm-form-span"
                    ><span class="crm-field-label">Payment notes</span
                    ><textarea
                        v-model="payment.notes"
                        rows="2"
                        maxlength="10000"
                    /></label
                ><small
                    v-if="page.props.errors.currency"
                    role="alert"
                    class="crm-form-span"
                    >{{ page.props.errors.currency }}</small
                >
                <div class="crm-form-action">
                    <button class="crm-button" :disabled="payment.processing">
                        {{ payment.processing ? "Saving…" : "Record payment" }}
                    </button>
                </div>
            </form>
        </section>
        <section
            v-if="record.editable && canCancel && record.paid_amount === '0.00'"
            class="crm-card crm-flow"
        >
            <details class="crm-form-disclosure">
                <summary>Cancel unpaid record</summary>
                <form class="crm-form-grid" @submit.prevent="cancel">
                    <label class="crm-field crm-form-span"
                        ><span class="crm-field-label">Cancellation reason</span
                        ><textarea
                            v-model="cancellation.cancellation_reason"
                            rows="2"
                            required
                            maxlength="10000"
                        /><small
                            v-if="cancellation.errors.cancellation_reason"
                            role="alert"
                            data-slot="input-error"
                            >{{
                                cancellation.errors.cancellation_reason
                            }}</small
                        ></label
                    ><small v-if="page.props.errors.currency" role="alert">{{
                        page.props.errors.currency
                    }}</small>
                    <div class="crm-form-action">
                        <button
                            class="crm-secondary"
                            :disabled="cancellation.processing"
                        >
                            Cancel record
                        </button>
                    </div>
                </form>
            </details>
        </section>
        <section class="crm-card crm-flow">
            <h2>Recorded payments</h2>
            <p v-if="!payments.length">No payments recorded.</p>
            <article v-for="entry in payments" :key="entry.id">
                <strong
                    >{{ entry.amount }} {{ record.currency }} ·
                    {{ entry.paid_on }}</strong
                >
                <p v-if="entry.reference">{{ entry.reference }}</p>
                <p v-if="entry.notes">{{ entry.notes }}</p>
                <small>{{ entry.actor?.name ?? "Former staff" }}</small>
            </article>
        </section>
        <LedgerHistory :history="history" />
    </div>
</template>
