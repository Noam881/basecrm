<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { usePage } from "@inertiajs/vue3";
import { useId } from "vue";
import { date } from "../../../../crm-core/resources/js/types";
import type { History } from "../types";
defineProps<{ history: History[]; title?: string }>();
const eventLabels: Record<string, string> = {
    created: "Goal created",
    updated: "Goal updated",
    archived: "Goal archived",
    "work.created": "Task created",
    "work.updated": "Task updated",
    "work.archived": "Task archived",
    "routine.created": "Routine created",
    "routine.updated": "Routine updated",
    "routine.completed": "Occurrence completed",
    "routine.archived": "Routine archived",
    "Goal link updated": "Linked goal updated",
};
const fieldLabels: Record<string, string> = {
    title: "Task name",
    name: "Name",
    description: "Description",
    owner_id: "Responsible person",
    target_on: "Target date",
    success_criterion: "Success criterion",
    target_value: "Target result",
    current_value: "Current result",
    unit: "Unit",
    status: "Status",
    priority: "Priority",
    due_on: "Due date",
    completed_on: "Completion date",
    goal_id: "Linked goal",
    frequency: "Frequency",
    next_due_on: "Next due date",
    checklist: "Checklist",
    archived_at: "Archived date",
    created_by: "Created by",
};
function readable(value: string): string {
    const label = (value.split(":").at(-1) ?? value).replaceAll(/[_.]/g, " ");
    return tr(label.charAt(0).toUpperCase() + label.slice(1));
}
function fieldName(field: string): string {
    const key = field.split(":").at(-1) ?? field;
    return fieldLabels[key] ? tr(fieldLabels[key]) : readable(key);
}
const headingId = `executive-history-${useId()}`;
const zone = (usePage().props.brand as { timezone: string }).timezone;
</script>
<template>
    <section class="crm-card crm-flow" :aria-labelledby="headingId">
        <h2 :id="headingId">{{ title ?? tr("History") }}</h2>
        <p v-if="!history.length" class="crm-muted">
            {{ tr("No history recorded.") }}
        </p>
        <article v-for="entry in history" :key="entry.id" class="crm-note">
            <strong>{{
                eventLabels[entry.event]
                    ? tr(eventLabels[entry.event])
                    : readable(entry.event)
            }}</strong>
            <p v-if="entry.changed_fields?.length">
                {{ entry.changed_fields.map(fieldName).join(", ") }}
            </p>
            <small
                >{{ entry.actor?.name ?? tr("Former team member") }} ·
                {{ date(entry.created_at, zone) }}</small
            >
        </article>
    </section>
</template>
