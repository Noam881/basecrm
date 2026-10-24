<script setup lang="ts">
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import OperationsNav from "../../components/OperationsNav.vue";
import OrderEditor from "../../components/OrderEditor.vue";
import HistoryList from "../../components/HistoryList.vue";
import type { Options, Order, Receipt, History } from "../../types";
const props = defineProps<
    Options & { record: Order; receipts: Receipt[]; history: History[] }
>();
const receipt = useForm({
    version: props.record.version,
    received_on: props.today,
    quantity: "",
    complete: false,
    description: "",
    outstanding_items: props.record.outstanding_items ?? "",
    submission_key: crypto.randomUUID(),
});
function recordReceipt() {
    receipt.version = props.record.version;
    receipt.post(`/operations/orders/${props.record.id}/receipts`, {
        preserveScroll: true,
        onSuccess: () => {
            receipt.reset("quantity", "description");
            receipt.submission_key = crypto.randomUUID();
            receipt.version = props.record.version;
        },
    });
}
function archive() {
    if (confirm("Archive this order and retain its receipts?"))
        router.post(`/operations/orders/${props.record.id}/archive`, {
            version: props.record.version,
        });
}
</script>
<template>
    <Head :title="record.reference" /><Link
        class="crm-back"
        href="/operations/orders"
        >← Supplier orders</Link
    >
    <div class="crm-heading">
        <h1>{{ record.reference }}</h1>
        <span class="crm-badge">{{ orderStatuses[record.status] }}</span>
    </div>
    <div class="crm-flow">
        <OperationsNav :can-view-directory="canViewDirectory" />
        <section class="crm-card crm-flow">
            <dl class="crm-detail-grid">
                <div class="crm-detail-item">
                    <dt>Supplier</dt>
                    <dd>
                        <Link
                            v-if="record.supplier"
                            :href="`/operations/directory/${record.supplier.id}`"
                            >{{ record.supplier.name }}</Link
                        ><template v-else>Supplier unavailable</template>
                    </dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Owner</dt>
                    <dd>{{ record.owner?.name ?? "Unassigned" }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Agreed total</dt>
                    <dd>{{ record.agreed_total }} {{ record.currency }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Expected delivery</dt>
                    <dd>
                        {{ record.expected_on ?? "Not scheduled"
                        }}<strong v-if="record.overdue"> · Overdue</strong>
                    </dd>
                </div>
                <div v-if="record.quantity" class="crm-detail-item">
                    <dt>Quantity received / agreed</dt>
                    <dd>
                        {{ record.received_quantity }} / {{ record.quantity }}
                    </dd>
                </div>
                <div v-if="record.outstanding_quantity" class="crm-detail-item">
                    <dt>Outstanding quantity</dt>
                    <dd>{{ record.outstanding_quantity }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Receipt completion date</dt>
                    <dd>{{ record.received_on ?? "Not fully received" }}</dd>
                </div>
            </dl>
            <p>{{ record.description }}</p>
            <p v-if="record.outstanding_items">
                Outstanding: {{ record.outstanding_items }}
            </p>
            <a
                v-if="record.document_link"
                class="crm-secondary"
                :href="record.document_link"
                target="_blank"
                rel="noopener noreferrer"
                >Open document</a
            >
            <p v-if="record.archived_at">
                Archived · retained read-only record.
            </p>
        </section>
        <section v-if="record.editable" class="crm-card crm-flow">
            <h2>Edit order</h2>
            <OrderEditor :key="record.version" v-bind="props" />
            <div v-if="canArchive" class="crm-form-action">
                <button class="crm-secondary" @click="archive">
                    Archive order
                </button>
            </div>
        </section>
        <section
            v-if="
                record.editable &&
                ['ordered', 'part-received'].includes(record.status)
            "
            class="crm-card crm-flow"
            aria-label="Record delivery receipt"
        >
            <h2>Record receipt</h2>
            <form class="crm-form-grid" @submit.prevent="recordReceipt">
                <label class="crm-field"
                    ><span class="crm-field-label">Received date</span
                    ><input
                        v-model="receipt.received_on"
                        type="date"
                        required
                    /><small
                        v-if="receipt.errors.received_on"
                        role="alert"
                        data-slot="input-error"
                        >{{ receipt.errors.received_on }}</small
                    ></label
                ><label v-if="record.quantity" class="crm-field"
                    ><span class="crm-field-label">Received quantity</span
                    ><input
                        v-model="receipt.quantity"
                        inputmode="decimal"
                        required
                    /><small
                        v-if="receipt.errors.quantity"
                        role="alert"
                        data-slot="input-error"
                        >{{ receipt.errors.quantity }}</small
                    ></label
                ><label v-else class="crm-checkbox"
                    ><input v-model="receipt.complete" type="checkbox" />All
                    items received</label
                ><label class="crm-field crm-form-span"
                    ><span class="crm-field-label">Receipt description</span
                    ><textarea
                        v-model="receipt.description"
                        rows="3"
                        required
                        maxlength="10000"
                    /><small
                        v-if="receipt.errors.description"
                        role="alert"
                        data-slot="input-error"
                        >{{ receipt.errors.description }}</small
                    ></label
                ><label class="crm-field crm-form-span"
                    ><span class="crm-field-label">Items still outstanding</span
                    ><textarea
                        v-model="receipt.outstanding_items"
                        rows="2"
                        maxlength="10000"
                    /><small
                        v-if="receipt.errors.outstanding_items"
                        role="alert"
                        data-slot="input-error"
                        >{{ receipt.errors.outstanding_items }}</small
                    ></label
                >
                <div class="crm-form-action">
                    <button class="crm-button" :disabled="receipt.processing">
                        {{ receipt.processing ? "Saving…" : "Record receipt" }}
                    </button>
                </div>
            </form>
        </section>
        <section class="crm-card crm-flow">
            <h2>Delivery receipts</h2>
            <p v-if="!receipts.length">No receipts recorded.</p>
            <article v-for="entry in receipts" :key="entry.id">
                <strong
                    >{{ entry.received_on }} ·
                    {{
                        entry.quantity ??
                        (entry.complete ? "Complete" : "Partial")
                    }}</strong
                >
                <p>{{ entry.description }}</p>
                <small>{{ entry.actor?.name ?? "Former staff" }}</small>
            </article>
        </section>
        <HistoryList :history="history" />
    </div>
</template>
