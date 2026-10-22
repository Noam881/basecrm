<script setup lang="ts">
import { Head, Link, usePage } from "@inertiajs/vue3";
import { date, type Task, type Page } from "../../types";
import Pagination from "../../components/Pagination.vue";
defineProps<{ tasks: Page<Task>; view: string }>();
const zone = (usePage().props.brand as { timezone: string }).timezone;
</script>
<template>
    <Head title="Follow-ups" />
    <div class="crm-heading">
        <div>
            <p class="crm-eyebrow">MAKE THE NEXT MOVE</p>
            <h1>Follow-ups</h1>
            <p>Small actions that move relationships forward.</p>
        </div>
    </div>
    <section class="crm-card">
        <div class="crm-tabs">
            <Link href="/tasks">Open</Link
            ><Link href="/tasks?view=today">Today</Link
            ><Link href="/tasks?view=overdue">Overdue</Link
            ><Link href="/tasks?view=completed">Completed</Link>
        </div>
        <div v-if="!tasks.data.length" class="crm-empty">
            <h3>Nothing here right now.</h3>
            <p>Schedule follow-ups from a lead profile.</p>
        </div>
        <Link
            v-for="t in tasks.data"
            :key="t.id"
            class="crm-task-row"
            :href="`/leads/${t.lead_id}`"
            ><span class="crm-task-dot" />
            <div>
                <strong>{{ t.title }}</strong>
                <p>{{ t.lead?.title }} · {{ t.assignee?.name }}</p>
                <small>{{ date(t.due_at, zone) }}</small>
            </div>
            <span class="crm-badge">{{ t.status }}</span></Link
        ><Pagination :page="tasks" />
    </section>
</template>
