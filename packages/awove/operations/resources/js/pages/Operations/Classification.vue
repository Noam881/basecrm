<script setup lang="ts">
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
        >← Customers &amp; suppliers</Link
    >
    <div class="crm-heading">
        <h1>{{ record.name }}</h1>
        <span class="crm-badge">{{
            record.role === "customer" ? "Customer" : "Supplier"
        }}</span>
    </div>
    <div class="crm-flow">
        <OperationsNav :can-view-directory="canViewDirectory" />
        <section class="crm-card crm-flow">
            <dl class="crm-detail-grid">
                <div class="crm-detail-item">
                    <dt>Phone</dt>
                    <dd>{{ record.phone ?? "Not provided" }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Email</dt>
                    <dd>{{ record.email ?? "Not provided" }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Main contact</dt>
                    <dd>
                        {{
                            record.main_contact?.name ??
                            (record.has_hidden_main_contact
                                ? "Unavailable"
                                : "Not set")
                        }}
                    </dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Responsible person</dt>
                    <dd>{{ record.responsible?.name ?? "Unassigned" }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Activity</dt>
                    <dd>{{ record.active ? "Active" : "Inactive" }}</dd>
                </div>
            </dl>
            <p v-if="record.notes">{{ record.notes }}</p>
            <Link class="crm-secondary" :href="record.profile_url"
                >Shared contact details</Link
            >
            <p v-if="record.archived_at">
                Archived classification · existing business links retained.
            </p>
        </section>
        <section v-if="canClassify" class="crm-card crm-flow">
            <h2>Edit classification</h2>
            <ClassificationEditor :key="record.version" v-bind="props" />
            <div class="crm-form-action">
                <button class="crm-secondary" @click="archive">
                    {{
                        record.archived_at
                            ? "Restore classification"
                            : "Archive classification"
                    }}
                </button>
            </div>
        </section>
        <section class="crm-card crm-flow">
            <h2>Related records</h2>
            <div v-if="related.requests.length">
                <h3>Operational requests</h3>
                <p v-for="item in related.requests" :key="item.id">
                    <Link :href="`/operations/requests/${item.id}`">{{
                        item.title
                    }}</Link>
                </p>
            </div>
            <div v-if="related.orders.length">
                <h3>Supplier orders</h3>
                <p v-for="item in related.orders" :key="item.id">
                    <Link :href="`/operations/orders/${item.id}`">{{
                        item.reference
                    }}</Link>
                </p>
            </div>
            <div v-if="related.opportunities.length">
                <h3>Opportunities</h3>
                <p v-for="item in related.opportunities" :key="item.id">
                    <Link :href="`/leads/${item.id}`">{{
                        item.title ?? `Opportunity #${item.id}`
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
                No accessible linked records.
            </p>
        </section>
        <HistoryList :history="history" />
    </div>
</template>
