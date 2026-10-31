<script setup lang="ts">
import { Link, router } from "@inertiajs/vue3";
export interface WorkReminder {
    id: number;
    module: string;
    title: string;
    href: string;
    read_at: string | null;
}
defineProps<{ reminders: WorkReminder[] }>();
</script>
<template>
    <section
        v-if="reminders.length"
        class="crm-card crm-flow"
        aria-label="Business module reminders"
    >
        <h2>Work reminders</h2>
        <article
            v-for="notice in reminders"
            :key="notice.id"
            class="crm-task-row"
        >
            <div>
                <Link :href="notice.href"
                    ><strong>{{ notice.title }}</strong></Link
                >
                <p>{{ notice.read_at ? "Read" : "Unread" }}</p>
            </div>
            <button
                v-if="!notice.read_at"
                class="crm-secondary"
                @click="router.post(`/work-reminders/${notice.id}/read`)"
            >
                Mark read
            </button>
        </article>
    </section>
</template>
