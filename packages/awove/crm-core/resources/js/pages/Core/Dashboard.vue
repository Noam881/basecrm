<script setup lang="ts">
import { Head, Link, usePage } from "@inertiajs/vue3";
import { date, type Task } from "../../types";
defineProps<{
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
}>();
const zone = (usePage().props.brand as { timezone: string }).timezone;
</script>
<template>
    <Head title="Overview" />
    <div class="crm-heading">
        <div>
            <p class="crm-eyebrow">A CLEAR VIEW OF YOUR DAY</p>
            <h1>Keep things moving.</h1>
            <p>Your pipeline and follow-ups, all in one place.</p>
        </div>
        <Link class="crm-button" href="/leads"
            >View leads <span aria-hidden="true">↗</span></Link
        >
    </div>
    <div class="crm-metrics">
        <Link href="/leads" class="crm-card"
            ><span>Active leads</span><strong>{{ metrics.active }}</strong
            ><small>Across your open pipeline</small></Link
        ><Link href="/tasks?view=today" class="crm-card"
            ><span>Due today</span><strong>{{ metrics.due }}</strong
            ><small>Follow-ups to keep on track</small></Link
        ><Link href="/tasks?view=overdue" class="crm-card"
            ><span>Overdue</span><strong>{{ metrics.overdue }}</strong
            ><small>Ready for your attention</small></Link
        ><Link href="/leads?view=missing" class="crm-card"
            ><span>Without a next action</span
            ><strong>{{ metrics.missing }}</strong
            ><small>Schedule the next step</small></Link
        >
    </div>
    <div class="crm-dashboard-grid">
        <section class="crm-card">
            <div class="crm-section-title">
                <h2>Your pipeline</h2>
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
                <h2>Next on your list</h2>
                <Link href="/tasks">All follow-ups →</Link>
            </div>
            <div v-if="!tasks.length" class="crm-empty">
                <span class="crm-empty-icon">✓</span>
                <h3>A little room to breathe.</h3>
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
                    <small>{{ date(task.due_at, zone) }}</small>
                </div>
                <span class="crm-badge">{{ task.priority }}</span></Link
            >
        </section>
    </div>
</template>
