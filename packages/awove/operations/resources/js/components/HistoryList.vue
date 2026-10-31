<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { usePage } from "@inertiajs/vue3";
import { date } from "../../../../crm-core/resources/js/types";
import type { History } from "../types";
defineProps<{ history: History[] }>();
const zone = (usePage().props.brand as { timezone: string }).timezone;
const labels: Record<string, string> = {
    "work.created": "Request created",
    "work.updated": "Request updated",
    "work.archived": "Request archived",
    "routine.created": "Routine created",
    "routine.updated": "Routine updated",
    "routine.completed": "Occurrence completed",
    "routine.archived": "Routine archived",
    "order.created": "Order created",
    "order.updated": "Order updated",
    "order.archived": "Order archived",
    "receipt.recorded": "Delivery receipt recorded",
    "classification.created": "Classification created",
    "classification.updated": "Classification updated",
    "classification.archived": "Classification archived",
    "classification.restored": "Classification restored",
};
function fieldName(field: string) {
    const key = field.split(":").at(-1)!;
    const labels: Record<string, string> = {
        customer_relationship_id: "Customer",
        supplier_relationship_id: "Supplier",
        owner_id: "Owner",
        responsible_id: "Responsible person",
        main_contact_id: "Main contact",
        due_on: "Due date",
        completed_on: "Completion date",
        document_url: "Document link",
        next_due_on: "Next occurrence",
    };
    if (labels[key]) return tr(labels[key]);
    const value = key.replaceAll("_", " ");
    return value[0].toUpperCase() + value.slice(1);
}
</script>
<template>
    <section class="crm-card crm-flow" :aria-label="tr('Change history')">
        <h2>{{ tr("History") }}</h2>
        <article v-for="entry in history" :key="entry.id">
            <strong>{{ tr(labels[entry.event] ?? entry.event) }}</strong>
            <p v-if="entry.changed_fields?.length">
                {{ entry.changed_fields.map(fieldName).join(", ") }}
            </p>
            <p v-if="entry.details?.previous_completed_on">
                {{ tr("Previous completion:") }}
                {{ entry.details.previous_completed_on }}
            </p>
            <p
                v-if="
                    entry.event === 'receipt.recorded' &&
                    entry.details?.received_on
                "
            >
                {{ tr("Received") }} {{ entry.details.received_on }} ·
                {{
                    entry.details.quantity ??
                    (entry.details.complete ? tr("Complete") : tr("Partial"))
                }}
            </p>
            <p
                v-if="
                    entry.event === 'receipt.recorded' &&
                    entry.details?.outstanding_items
                "
            >
                {{ tr("Outstanding at this receipt:") }}
                {{ entry.details.outstanding_items }}
            </p>
            <small
                >{{ entry.actor?.name ?? tr("Former staff") }} ·
                {{ date(entry.created_at, zone) }}</small
            >
        </article>
    </section>
</template>
