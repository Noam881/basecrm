<script setup lang="ts">
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import { reactive } from "vue";
import { type Person, type Task } from "../../types";
import ModuleWidgets, {
    type DashboardWidget,
} from "../../components/ModuleWidgets.vue";
const props = defineProps<{
    metrics: {
        active: number;
        new: number;
        missing: number;
        unassigned: number | null;
        due: number;
        overdue: number;
    };
    stages: { id: string; name: string; count: number }[];
    tasks: Task[];
    owners: Person[];
    moduleWidgets: DashboardWidget[];
    filters: { owner?: number | string; from?: string; to?: string };
    salesOverview: {
        estimated_pipeline: string | null;
        won_count: number;
        won_value: string | null;
        won_missing_value: number;
        won_missing_closing_date: number;
    };
}>();
const currency = (usePage().props.brand as { currency: string }).currency;
const filters = reactive({
    owner: props.filters.owner ?? "",
    from: props.filters.from ?? "",
    to: props.filters.to ?? "",
});
function apply() {
    router.get("/dashboard", filters, { preserveState: true });
}
</script>
<template>
    <Head title="Overview" />
    <div class="crm-heading">
        <h1>Overview</h1>
        <Link class="crm-button" href="/leads"
            >View leads <span aria-hidden="true">↗</span></Link
        >
    </div>
    <ModuleWidgets :widgets="moduleWidgets" />
    <form class="crm-card crm-form-grid" @submit.prevent="apply">
        <label
            ><span class="crm-field-label">Owner</span
            ><select v-model="filters.owner">
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
        <label
            ><span class="crm-field-label">From date</span
            ><input v-model="filters.from" type="date"
        /></label>
        <label
            ><span class="crm-field-label">To date</span
            ><input
                v-model="filters.to"
                type="date"
                :min="filters.from || undefined"
        /></label>
        <div class="crm-form-action">
            <button class="crm-button">Apply overview filters</button
            ><Link href="/dashboard" class="crm-secondary">Clear filters</Link>
        </div>
        <p class="crm-muted">
            Pipeline and follow-ups use opportunity creation dates. Won deals
            use the recorded closing date. Only records you can access are
            included.
        </p>
    </form>
    <div class="crm-metrics">
        <div class="crm-card">
            <span>Estimated open pipeline ({{ currency }})</span
            ><strong>{{
                salesOverview.estimated_pipeline ?? "Not recorded"
            }}</strong>
        </div>
        <div class="crm-card">
            <span>Won deals in period</span
            ><strong>{{ salesOverview.won_count }}</strong>
        </div>
        <div class="crm-card">
            <span>Recorded final deal value ({{ currency }})</span
            ><strong>{{ salesOverview.won_value ?? "Not recorded" }}</strong>
        </div>
    </div>
    <p
        v-if="
            salesOverview.won_missing_value ||
            salesOverview.won_missing_closing_date
        "
        class="crm-muted"
    >
        Won deals in the selected period missing a final value:
        {{ salesOverview.won_missing_value }}. Accessible won deals without a
        closing date, regardless of the selected period:
        {{ salesOverview.won_missing_closing_date }}. Missing amounts are
        excluded from the recorded total; undated deals cannot be assigned to a
        closing period.
    </p>
    <div class="crm-metrics">
        <Link href="/leads" class="crm-card"
            ><span>Active leads</span
            ><strong>{{ metrics.active }}</strong></Link
        ><Link href="/tasks?view=today" class="crm-card"
            ><span>Due today</span><strong>{{ metrics.due }}</strong></Link
        ><Link href="/tasks?view=overdue" class="crm-card"
            ><span>Overdue</span><strong>{{ metrics.overdue }}</strong></Link
        ><Link href="/leads?view=missing" class="crm-card"
            ><span>Without a next action</span
            ><strong>{{ metrics.missing }}</strong></Link
        >
    </div>
    <div class="crm-dashboard-grid">
        <section class="crm-card">
            <div class="crm-section-title">
                <h2>Pipeline</h2>
                <Link href="/leads">Explore →</Link>
            </div>
            <Link
                v-for="stage in stages"
                :key="stage.id"
                class="crm-stage-row"
                :href="`/leads?status=${stage.id}`"
                ><span><i />{{ stage.name }}</span
                ><strong>{{ stage.count }}</strong></Link
            ><Link class="crm-stage-row" href="/leads?status=new"
                ><span>New leads</span><strong>{{ metrics.new }}</strong></Link
            ><Link
                v-if="metrics.unassigned !== null"
                class="crm-stage-row"
                href="/leads?view=unassigned"
                ><span>Unassigned queue</span
                ><strong>{{ metrics.unassigned }}</strong></Link
            >
        </section>
        <section class="crm-card">
            <div class="crm-section-title">
                <h2>Upcoming follow-ups</h2>
                <Link href="/tasks">All follow-ups →</Link>
            </div>
            <div v-if="!tasks.length" class="crm-empty">
                <span class="crm-empty-icon">✓</span>
                <h3>No open follow-ups</h3>
                <p>Create a follow-up from a lead to plan your next step.</p>
                <Link href="/leads">Open your leads →</Link>
            </div>
            <Link
                v-for="task in tasks"
                :key="task.id"
                :href="`/leads/${task.lead_id}`"
                class="crm-task-row"
                ><span class="crm-task-dot" />
                <div>
                    <strong>{{ task.title }}</strong>
                    <p>{{ task.lead?.title }}</p>
                </div>
                <span class="crm-badge">{{ task.priority }}</span></Link
            >
        </section>
    </div>
</template>
