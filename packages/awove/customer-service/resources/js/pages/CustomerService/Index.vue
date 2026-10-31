<script setup lang="ts">
import { Head, Link, router } from "@inertiajs/vue3";
import { reactive } from "vue";
import RequestEditor from "../../components/RequestEditor.vue";
import FeedbackEditor from "../../components/FeedbackEditor.vue";
import DataTable from "../../../../../crm-core/resources/js/components/table/DataTable.vue";
import type { TablePayload } from "../../../../../crm-core/resources/js/components/table/types";
import Pagination from "../../../../../crm-core/resources/js/components/Pagination.vue";
import type { Feedback, Options, Page, Identity } from "../../types";
const props = defineProps<
    Options & {
        table: TablePayload;
        feedback: Page<Feedback>;
        filters: Record<string, string>;
        metrics: Record<string, number>;
        byStatus: Record<string, number>;
        byOwner: { owner: Identity | null; total: number }[];
    }
>();
const filters = reactive({
    search: props.filters.search ?? "",
    status: props.filters.status ?? "",
    priority: props.filters.priority ?? "",
    owner_id: props.filters.owner_id ?? "",
    contact_id: props.filters.contact_id ?? "",
    company_id: props.filters.company_id ?? "",
    from: props.filters.from ?? "",
    to: props.filters.to ?? "",
    date_field: props.filters.date_field ?? "received_on",
    view: props.filters.view ?? "",
});
function apply() {
    router.get("/service", filters, { preserveState: true });
}
function exportUrl(type = "requests") {
    const params = new URLSearchParams();
    function append(prefix: string, value: unknown) {
        if (value === null || value === undefined || value === "") return;
        if (Array.isArray(value))
            value.forEach((item, index) => append(`${prefix}[${index}]`, item));
        else if (typeof value === "object")
            Object.entries(value).forEach(([key, item]) =>
                append(`${prefix}[${key}]`, item),
            );
        else params.append(prefix, String(value));
    }
    const query =
        type === "requests"
            ? { ...filters, ...props.table.state, type }
            : { ...filters, type };
    Object.entries(query).forEach(([key, value]) => append(key, value));
    return "/service/export?" + params.toString();
}
</script>
<template>
    <Head title="Customer service" />
    <div class="crm-heading">
        <h1>Customer service</h1>
        <a v-if="canExport" class="crm-secondary" :href="exportUrl()"
            >Export requests (CSV)</a
        ><a v-if="canExport" class="crm-secondary" :href="exportUrl('feedback')"
            >Export feedback (CSV)</a
        >
    </div>
    <div class="crm-flow">
        <div class="crm-tabs">
            <Link href="/service">All requests</Link
            ><Link href="/service?view=overdue">Overdue</Link
            ><Link href="/service?view=waiting">Waiting for customer</Link
            ><Link v-if="canViewAll" href="/service?view=unassigned"
                >Unassigned</Link
            ><Link href="/service?view=feedback-due">Feedback follow-up</Link
            ><Link href="/service?view=archived">Archived</Link>
        </div>
        <form class="crm-card crm-form-grid" @submit.prevent="apply">
            <label class="crm-field"
                ><span class="crm-field-label">Search</span
                ><input
                    v-model="filters.search"
                    type="search"
                    maxlength="180"
                    placeholder="Subject, description or next action"
            /></label>
            <label class="crm-field"
                ><span class="crm-field-label">Status</span
                ><select v-model="filters.status">
                    <option value="">All statuses</option>
                    <option
                        v-for="(label, key) in statuses"
                        :key="key"
                        :value="key"
                    >
                        {{ label }}
                    </option>
                </select></label
            >
            <label class="crm-field"
                ><span class="crm-field-label">Owner</span
                ><select v-model="filters.owner_id">
                    <option value="">All accessible owners</option>
                    <option
                        v-for="owner in owners"
                        :key="owner.id"
                        :value="owner.id"
                    >
                        {{ owner.name }}
                    </option>
                </select></label
            >
            <label class="crm-field"
                ><span class="crm-field-label">Priority</span
                ><select v-model="filters.priority">
                    <option value="">All priorities</option>
                    <option value="low">Low</option>
                    <option value="normal">Normal</option>
                    <option value="high">High</option>
                </select></label
            >
            <label class="crm-field"
                ><span class="crm-field-label">Customer contact</span
                ><select v-model="filters.contact_id">
                    <option value="">All accessible contacts</option>
                    <option
                        v-for="contact in contacts"
                        :key="contact.id"
                        :value="contact.id"
                    >
                        {{ contact.name }}
                    </option>
                </select></label
            >
            <label class="crm-field"
                ><span class="crm-field-label">Customer company</span
                ><select v-model="filters.company_id">
                    <option value="">All accessible companies</option>
                    <option
                        v-for="company in companies"
                        :key="company.id"
                        :value="company.id"
                    >
                        {{ company.name }}
                    </option>
                </select></label
            >
            <label class="crm-field"
                ><span class="crm-field-label">Date field</span
                ><select v-model="filters.date_field">
                    <option value="received_on">Date received</option>
                    <option value="resolved_on">Resolution date</option>
                </select></label
            >
            <label class="crm-field"
                ><span class="crm-field-label">From date</span
                ><input v-model="filters.from" type="date"
            /></label>
            <label class="crm-field"
                ><span class="crm-field-label">To date</span
                ><input
                    v-model="filters.to"
                    type="date"
                    :min="filters.from || undefined"
            /></label>
            <div class="crm-form-action">
                <button class="crm-button">Apply service filters</button
                ><Link class="crm-secondary" href="/service"
                    >Clear filters</Link
                >
            </div>
            <p class="crm-muted crm-form-span">
                Overview totals follow these overview filters. The request table
                has its own column filters, which also apply to request CSV
                exports. Choose Resolution date for resolutions by period.
                Feedback dates use date received; request status and priority do
                not apply to feedback.
            </p>
        </form>
        <div class="crm-metrics">
            <div class="crm-card">
                <span>Open requests</span><strong>{{ metrics.open }}</strong>
            </div>
            <div class="crm-card">
                <span>Overdue actions</span
                ><strong>{{ metrics.overdue }}</strong>
            </div>
            <div class="crm-card">
                <span>Waiting for customer</span
                ><strong>{{ metrics.awaiting }}</strong>
            </div>
            <div v-if="canViewAll" class="crm-card">
                <span>Unassigned</span><strong>{{ metrics.unassigned }}</strong>
            </div>
            <div class="crm-card">
                <span>Resolved / closed</span
                ><strong>{{ metrics.resolved }}</strong>
            </div>
            <div class="crm-card">
                <span>Feedback needing action</span
                ><strong>{{ metrics.feedback_due }}</strong>
            </div>
        </div>
        <section class="crm-card crm-flow">
            <h2>Open work by owner</h2>
            <p v-if="!byOwner.length">No open work matches these filters.</p>
            <p v-for="(item, index) in byOwner" :key="index">
                {{ item.owner?.name ?? "Unassigned" }} · {{ item.total }}
            </p>
            <h3>Requests by status</h3>
            <p v-for="(total, status) in byStatus" :key="status">
                {{ statuses[status] ?? status }} · {{ total }}
            </p>
        </section>
        <section v-if="canCreate" class="crm-card">
            <details class="crm-form-disclosure">
                <summary>Add service request</summary>
                <div class="crm-flow"><RequestEditor v-bind="props" /></div>
            </details>
        </section>
        <DataTable
            :payload="table"
            endpoint="/service"
            title="Service requests"
            :base-query="filters"
        />
        <section v-if="canCreate" class="crm-card">
            <details class="crm-form-disclosure">
                <summary>Add customer feedback</summary>
                <div class="crm-flow"><FeedbackEditor v-bind="props" /></div>
            </details>
        </section>
        <section class="crm-card crm-flow">
            <h2>Customer feedback</h2>
            <p v-if="!feedback.data.length">No matching feedback.</p>
            <article
                v-for="item in feedback.data"
                :key="item.id"
                class="crm-task-row"
            >
                <div>
                    <Link :href="`/service/feedback/${item.id}`"
                        ><strong
                            >{{
                                item.customer?.name ?? "Customer unavailable"
                            }}
                            · {{ item.rating }}/5</strong
                        ></Link
                    >
                    <p>{{ item.comments }}</p>
                    <small
                        >{{ item.received_on }} ·
                        {{ item.owner?.name ?? "Unassigned"
                        }}<template v-if="item.action_required">
                            ·
                            {{
                                item.followed_up_on
                                    ? `Completed ${item.followed_up_on}`
                                    : `Follow up ${item.follow_up_on}`
                            }}</template
                        ></small
                    >
                </div>
            </article>
            <Pagination :page="feedback" />
        </section>
    </div>
</template>
