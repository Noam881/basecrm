<script setup lang="ts">
import { usePage } from "@inertiajs/vue3";
import { date } from "../../../../crm-core/resources/js/types";
import type { History } from "../types";
defineProps<{ history: History[] }>();
const zone = (usePage().props.brand as { timezone: string }).timezone;
const labels: Record<string, string> = {
    "record.created": "Record created",
    "record.updated": "Record updated",
    "record.cancelled": "Record cancelled",
    "payment.recorded": "Payment recorded",
};
const fields: Record<string, string> = {
    relationship_id: "Customer/supplier",
    owner_id: "Owner",
    total: "Total payable",
    due_on: "Due date",
    document_url: "Document link",
    next_action: "Next collection action",
    next_action_on: "Next action date",
};
function name(field: string) {
    return (
        fields[field] ??
        field.replaceAll("_", " ").replace(/^./, (v) => v.toUpperCase())
    );
}
</script>
<template>
    <section class="crm-card crm-flow" aria-label="Finance change history">
        <h2>History</h2>
        <article v-for="entry in history" :key="entry.id">
            <strong>{{ labels[entry.event] ?? entry.event }}</strong>
            <p v-if="entry.changed_fields.length">
                {{ entry.changed_fields.map(name).join(", ") }}
            </p>
            <p v-if="entry.event === 'payment.recorded'">
                {{ entry.details.amount }} · {{ entry.details.paid_on }}
            </p>
            <p v-if="entry.details.reason">{{ entry.details.reason }}</p>
            <small
                >{{ entry.actor?.name ?? "Former staff" }} ·
                {{ date(entry.created_at, zone) }}</small
            >
        </article>
    </section>
</template>
