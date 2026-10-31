<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { Head, Link, router } from "@inertiajs/vue3";
import OperationsNav from "../../components/OperationsNav.vue";
import ClassificationEditor from "../../components/ClassificationEditor.vue";
import HistoryList from "../../components/HistoryList.vue";
import type { Options, Person, Classification, History } from "../../types";
const props = defineProps<
    Options & {
        record: Classification;
        history: History[];
        contacts: (Person & { company_id?: number | null })[];
        companies: Person[];
        classificationOwners: Person[];
        canAssignClassification: boolean;
        related: {
            requests: { id: number; title: string }[];
            orders: { id: number; reference: string }[];
            opportunities: { id: number; title: string | null }[];
        };
    }
>();
function archive() {
    router.post(`/operations/directory/${props.record.id}/archive`, {
        version: props.record.version,
        archived: !props.record.archived_at,
    });
}
</script>
<template>
    <Head :title="record.name" /><Link
        class="crm-back"
        href="/operations/directory"
        >{{ tr("← Customers & suppliers") }}</Link
    >
    <div class="crm-heading">
        <h1>{{ record.name }}</h1>
        <span class="crm-badge">{{
            record.role === "customer" ? tr("Customer") : tr("Supplier")
        }}</span>
    </div>
    <div class="crm-flow">
        <OperationsNav :can-view-directory="canViewDirectory" />
        <section class="crm-card crm-flow">
            <dl class="crm-detail-grid">
                <div class="crm-detail-item">
                    <dt>{{ tr("Phone") }}</dt>
                    <dd>{{ record.phone ?? tr("Not provided") }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>{{ tr("Email") }}</dt>
                    <dd>{{ record.email ?? tr("Not provided") }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>{{ tr("Main contact") }}</dt>
                    <dd>
                        {{
                            record.main_contact?.name ??
                            (record.has_hidden_main_contact
                                ? tr("Unavailable")
                                : tr("Not set"))
                        }}
                    </dd>
                </div>
                <div class="crm-detail-item">
                    <dt>{{ tr("Responsible person") }}</dt>
                    <dd>{{ record.responsible?.name ?? tr("Unassigned") }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>{{ tr("Activity") }}</dt>
                    <dd>{{ record.active ? tr("Active") : tr("Inactive") }}</dd>
                </div>
            </dl>
            <p v-if="record.notes">{{ record.notes }}</p>
            <Link class="crm-secondary" :href="record.profile_url">{{
                tr("Shared contact details")
            }}</Link>
            <p v-if="record.archived_at">
                {{
                    tr(
                        "Archived classification · existing business links retained.",
                    )
                }}
            </p>
        </section>
        <section v-if="canClassify" class="crm-card crm-flow">
            <h2>{{ tr("Edit classification") }}</h2>
            <ClassificationEditor :key="record.version" v-bind="props" />
            <div class="crm-form-action">
                <button class="crm-secondary" @click="archive">
                    {{
                        record.archived_at
                            ? tr("Restore classification")
                            : tr("Archive classification")
                    }}
                </button>
            </div>
        </section>
        <section class="crm-card crm-flow">
            <h2>{{ tr("Related records") }}</h2>
            <div v-if="related.requests.length">
                <h3>{{ tr("Operational requests") }}</h3>
                <p v-for="item in related.requests" :key="item.id">
                    <Link :href="`/operations/requests/${item.id}`">{{
                        item.title
                    }}</Link>
                </p>
            </div>
            <div v-if="related.orders.length">
                <h3>{{ tr("Supplier orders") }}</h3>
                <p v-for="item in related.orders" :key="item.id">
                    <Link :href="`/operations/orders/${item.id}`">{{
                        item.reference
                    }}</Link>
                </p>
            </div>
            <div v-if="related.opportunities.length">
                <h3>{{ tr("Opportunities") }}</h3>
                <p v-for="item in related.opportunities" :key="item.id">
                    <Link :href="`/leads/${item.id}`">{{
                        item.title ?? tr("Opportunity #{id}", { id: item.id })
                    }}</Link>
                </p>
            </div>
            <p
                v-if="
                    !related.requests.length &&
                    !related.orders.length &&
                    !related.opportunities.length
                "
            >
                {{ tr("No accessible linked records.") }}
            </p>
        </section>
        <HistoryList :history="history" />
    </div>
</template>
