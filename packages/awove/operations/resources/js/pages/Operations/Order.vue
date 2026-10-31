<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
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
    if (confirm(tr("Archive this order and retain its receipts?")))
        router.post(`/operations/orders/${props.record.id}/archive`, {
            version: props.record.version,
        });
}
</script>
<template>
    <Head :title="record.reference" /><Link
        class="crm-back"
        href="/operations/orders"
        >{{ tr("← Supplier orders") }}</Link
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
                    <dt>{{ tr("Supplier") }}</dt>
                    <dd>
                        <Link
                            v-if="record.supplier"
                            :href="`/operations/directory/${record.supplier.id}`"
                            >{{ record.supplier.name }}</Link
                        ><template v-else>{{
                            tr("Supplier unavailable")
                        }}</template>
                    </dd>
                </div>
                <div class="crm-detail-item">
                    <dt>{{ tr("Owner") }}</dt>
                    <dd>{{ record.owner?.name ?? tr("Unassigned") }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>{{ tr("Agreed total") }}</dt>
                    <dd>{{ record.agreed_total }} {{ record.currency }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>{{ tr("Expected delivery") }}</dt>
                    <dd>
                        {{ record.expected_on ?? tr("Not scheduled")
                        }}<strong v-if="record.overdue">
                            {{ tr("· Overdue") }}</strong
                        >
                    </dd>
                </div>
                <div v-if="record.quantity" class="crm-detail-item">
                    <dt>{{ tr("Quantity received / agreed") }}</dt>
                    <dd>
                        {{ record.received_quantity }} / {{ record.quantity }}
                    </dd>
                </div>
                <div v-if="record.outstanding_quantity" class="crm-detail-item">
                    <dt>{{ tr("Outstanding quantity") }}</dt>
                    <dd>{{ record.outstanding_quantity }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>{{ tr("Receipt completion date") }}</dt>
                    <dd>
                        {{ record.received_on ?? tr("Not fully received") }}
                    </dd>
                </div>
            </dl>
            <p>{{ record.description }}</p>
            <p v-if="record.outstanding_items">
                {{ tr("Outstanding:") }} {{ record.outstanding_items }}
            </p>
            <a
                v-if="record.document_link"
                class="crm-secondary"
                :href="record.document_link"
                target="_blank"
                rel="noopener noreferrer"
                >{{ tr("Open document") }}</a
            >
            <p v-if="record.archived_at">
                {{ tr("Archived · retained read-only record.") }}
            </p>
        </section>
        <section v-if="record.editable" class="crm-card crm-flow">
            <h2>{{ tr("Edit order") }}</h2>
            <OrderEditor :key="record.version" v-bind="props" />
            <div v-if="canArchive" class="crm-form-action">
                <button class="crm-secondary" @click="archive">
                    {{ tr("Archive order") }}
                </button>
            </div>
        </section>
        <section
            v-if="
                record.editable &&
                ['ordered', 'part-received'].includes(record.status)
            "
            class="crm-card crm-flow"
            :aria-label="tr('Record delivery receipt')"
        >
            <h2>{{ tr("Record receipt") }}</h2>
            <form class="crm-form-grid" @submit.prevent="recordReceipt">
                <label class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Received date")
                    }}</span
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
                    ><span class="crm-field-label">{{
                        tr("Received quantity")
                    }}</span
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
                    ><input v-model="receipt.complete" type="checkbox" />{{
                        tr("All items received")
                    }}</label
                ><label class="crm-field crm-form-span"
                    ><span class="crm-field-label">{{
                        tr("Receipt description")
                    }}</span
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
                    ><span class="crm-field-label">{{
                        tr("Items still outstanding")
                    }}</span
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
                        {{
                            receipt.processing
                                ? tr("Saving…")
                                : tr("Record receipt")
                        }}
                    </button>
                </div>
            </form>
        </section>
        <section class="crm-card crm-flow">
            <h2>{{ tr("Delivery receipts") }}</h2>
            <p v-if="!receipts.length">{{ tr("No receipts recorded.") }}</p>
            <article v-for="entry in receipts" :key="entry.id">
                <strong
                    >{{ entry.received_on }} ·
                    {{
                        entry.quantity ??
                        (entry.complete ? tr("Complete") : tr("Partial"))
                    }}</strong
                >
                <p>{{ entry.description }}</p>
                <small>{{ entry.actor?.name ?? tr("Former staff") }}</small>
            </article>
        </section>
        <HistoryList :history="history" />
    </div>
</template>
