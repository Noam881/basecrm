<script setup lang="ts">
import { Head, Link, router } from "@inertiajs/vue3";
import type { Task, Page } from "../../types";
import WorkReminders, {
    type WorkReminder,
} from "../../components/WorkReminders.vue";
import Pagination from "../../components/Pagination.vue";
defineProps<{
    workReminders?: WorkReminder[];
    reminders: Page<{ id: number; task: Task; read_at: string | null }>;
}>();
</script>
<template>
    <Head title="Reminders" />
    <div class="crm-heading">
        <h1>Reminders</h1>
    </div>
    <WorkReminders :reminders="workReminders ?? []" />
    <section class="crm-card">
        <div
            v-if="!reminders.data.length && !workReminders?.length"
            class="crm-empty"
        >
            <span class="crm-empty-icon">✓</span>
            <h3>No due reminders</h3>
            <p>Reminders for your scheduled follow-ups appear here.</p>
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
