<script setup lang="ts">
import { t as tr, translateLabels } from "@/lib/i18n";
import { Head, Link, useForm } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import HistoryList from "../../../../../../crm-core/resources/js/components/HistoryList.vue";
import type { HistoryEntry } from "../../../../../../crm-core/resources/js/components/HistoryList.vue";
import NonprofitNav from "../../../components/NonprofitNav.vue";
import PledgeEditor from "../../../components/Fundraising/PledgeEditor.vue";
import Errors from "../../../components/Fundraising/Errors.vue";
import {
    fields,
    events,
    type Options,
    type Pledge,
    type Receipt,
    type Actuals,
} from "../../../components/Fundraising/types";
const p = defineProps<
    Options & {
        record: Pledge;
        editable: boolean;
        history: HistoryEntry[];
        actuals?: Actuals;
        receiptOptions?: Receipt[];
    }
>();
const receipt = useForm({
    constituent_version: p.record.constituent.version,
    pledge_version: p.record.version,
    payment_id: null as number | null,
});
const stale = computed(
    () =>
        receipt.constituent_version !== p.record.constituent.version ||
        receipt.pledge_version !== p.record.version,
);
function reset() {
    receipt.defaults({
        constituent_version: p.record.constituent.version,
        pledge_version: p.record.version,
        payment_id: null,
    });
    receipt.reset();
    receipt.clearErrors();
}
watch(
    () => [p.record.version, p.record.constituent.version],
    () => {
        if (!receipt.isDirty) reset();
    },
);
function link() {
    if (stale.value) return;
    const selected = p.receiptOptions?.find(
        (r) => r.payment_id === receipt.payment_id,
    );
    if (!selected) return;
    receipt
        .transform((d) => ({
            ...d,
            finance_record_id: selected.finance_record_id,
            finance_version: selected.finance_version,
        }))
        .post(`/nonprofit/fundraising/pledges/${p.record.id}/receipts`, {
            preserveScroll: true,
            onSuccess: reset,
        });
}
const action = useForm({
    constituent_version: 0,
    pledge_version: 0,
    version: 0,
});
function unlink(row: Receipt) {
    action.constituent_version = p.record.constituent.version;
    action.pledge_version = p.record.version;
    action.version = row.version ?? 0;
    action.post(`/nonprofit/fundraising/receipts/${row.id}/archive`, {
        preserveScroll: true,
    });
}
function archive() {
    action.constituent_version = p.record.constituent.version;
    action.version = p.record.version;
    action.post(`/nonprofit/fundraising/pledges/${p.record.id}/archive`, {
        preserveScroll: true,
    });
}
</script>
<template>
    <Head
        :title="record.reference ?? tr('Pledge #{id}', { id: record.id })"
    /><Link
        class="crm-back"
        :href="`/nonprofit/fundraising/constituents/${record.constituent.id}`"
        >← {{ record.constituent.relationship.name }}</Link
    >
    <div class="crm-heading">
        <h1>{{ record.reference ?? tr("Pledge #{id}", { id: record.id }) }}</h1>
        <span v-if="record.archived_at" class="crm-badge">{{
            tr("Archived")
        }}</span>
    </div>
    <div class="crm-flow">
        <NonprofitNav :sections="sections" />
        <section class="crm-card crm-flow">
            <h2>{{ tr("Pledge details") }}</h2>
            <PledgeEditor
                v-if="canManagePledges"
                v-bind="p"
                :constituent="record.constituent"
            />
            <dl v-else class="crm-detail-grid">
                <div class="crm-detail-item">
                    <dt>{{ tr("Pledged amount") }}</dt>
                    <dd>{{ record.amount }} {{ record.currency }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>{{ tr("Expected donation date") }}</dt>
                    <dd>{{ record.expected_on }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>{{ tr("Donation restrictions") }}</dt>
                    <dd>{{ record.restrictions ?? tr("Unrestricted") }}</dd>
                </div>
            </dl>
        </section>
        <section v-if="canViewReceipts && actuals" class="crm-card crm-flow">
            <h2>{{ tr("Actual Finance receipts") }}</h2>
            <p v-if="!actuals.available" class="crm-notice">
                {{ actuals.message }}
            </p>
            <p v-else>
                <strong>{{ actuals.amount }} {{ actuals.currency }}</strong>
                {{
                    tr(
                        "linked actual receipts. This is separate from the pledged amount.",
                    )
                }}
            </p>
            <article
                v-for="row in actuals.receipts ?? []"
                :key="row.id"
                class="crm-note crm-flow"
            >
                <Link class="crm-inline-link" :href="row.href">{{
                    row.label
                }}</Link
                ><span
                    >{{ row.amount }} {{ row.currency }} {{ tr("· Received") }}
                    {{ row.paid_on }}</span
                >
                <div
                    v-if="editable && canManageReceipts"
                    class="crm-form-action"
                >
                    <button
                        class="crm-secondary"
                        :disabled="action.processing"
                        @click="unlink(row)"
                    >
                        {{ tr("Remove receipt link") }}
                    </button>
                </div>
            </article>
            <form
                v-if="editable && canManageReceipts && receiptOptions?.length"
                class="crm-form"
                @submit.prevent="link"
            >
                <label class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Existing Finance receipt")
                    }}</span
                    ><select
                        v-model="receipt.payment_id"
                        required
                        :aria-label="tr('Existing Finance receipt')"
                    >
                        <option :value="null">
                            {{ tr("Choose received payment") }}
                        </option>
                        <option
                            v-for="row in receiptOptions"
                            :key="row.payment_id"
                            :value="row.payment_id"
                        >
                            {{ row.label }} · {{ row.amount }}
                            {{ row.currency }}
                        </option>
                    </select></label
                >
                <p class="crm-muted">
                    {{
                        tr(
                            "Only currently accessible payments for the same shared donor identity can be linked. No payment, invoice or donation receipt is created.",
                        )
                    }}
                </p>
                <p v-if="stale" class="crm-notice">
                    {{ tr("The pledge changed. Reload the receipt form.") }}
                </p>
                <Errors :errors="receipt.errors" />
                <div class="crm-form-action">
                    <button
                        class="crm-button"
                        :disabled="receipt.processing || stale"
                    >
                        {{ tr("Link existing receipt") }}</button
                    ><button
                        v-if="stale"
                        class="crm-secondary"
                        type="button"
                        @click="reset"
                    >
                        {{ tr("Reload receipt form") }}
                    </button>
                </div>
            </form>
            <p
                v-else-if="editable && canManageReceipts && actuals.available"
                class="crm-muted"
            >
                {{
                    tr(
                        "No unlinked matching receipts are available. Record actual payments in Finance separately.",
                    )
                }}
            </p>
            <Errors :errors="action.errors" />
        </section>
        <HistoryList
            :history="history"
            :event-labels="translateLabels(events)"
            :field-labels="translateLabels(fields)"
        />
        <form
            v-if="editable && canManagePledges && canArchive"
            class="crm-card crm-form"
            @submit.prevent="archive"
        >
            <p>
                {{
                    tr(
                        "Archive retains this expectation and every receipt link. It changes no actual Finance payment.",
                    )
                }}
            </p>
            <Errors :errors="action.errors" />
            <div class="crm-form-action">
                <button class="crm-secondary" :disabled="action.processing">
                    {{ tr("Archive pledge") }}
                </button>
            </div>
        </form>
    </div>
</template>
