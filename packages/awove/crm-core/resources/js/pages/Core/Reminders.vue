<script setup lang="ts">
import { Head, Link, router } from "@inertiajs/vue3";
import type { Task, Page } from "../../types";
import Pagination from "../../components/Pagination.vue";
defineProps<{
    reminders: Page<{ id: number; task: Task; read_at: string | null }>;
}>();
</script>
<template>
    <Head title="Reminders" />
    <div class="crm-heading">
        <div>
            <p class="crm-eyebrow">A TIMELY NUDGE</p>
            <h1>Reminders</h1>
            <p>Reading a reminder keeps its follow-up open.</p>
        </div>
    </div>
    <section class="crm-card">
        <div v-if="!reminders.data.length" class="crm-empty">
            <span class="crm-empty-icon">✓</span>
            <h3>You're up to date.</h3>
            <p>Due reminders appear here after the scheduler and queue run.</p>
        </div>
        <article v-for="n in reminders.data" :key="n.id" class="crm-task-row">
            <div>
                <Link :href="`/leads/${n.task.lead_id}`"
                    ><strong>{{ n.task.title }}</strong></Link
                >
                <p>{{ n.task.lead?.title }}</p>
                <small>{{ n.read_at ? "Read" : "Unread" }}</small>
            </div>
            <button
                v-if="!n.read_at"
                class="crm-secondary"
                @click="router.post(`/reminders/${n.id}/read`)"
            >
                Mark read
            </button>
        </article>
        <Pagination :page="reminders" />
    </section>
</template>
