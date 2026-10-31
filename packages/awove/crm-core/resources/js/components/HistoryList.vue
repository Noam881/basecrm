<script setup lang="ts">
import { t } from "@/lib/i18n";
import { usePage } from "@inertiajs/vue3";
import { useId } from "vue";
import { date } from "../types";
export interface HistoryEntry {
    id: number;
    event: string;
    version?: number;
    changed_fields?: string[];
    actor?: { id: number; name: string } | null;
    created_at: string;
}
withDefaults(
    defineProps<{
        history: HistoryEntry[];
        title?: string;
        eventLabels?: Record<string, string>;
        fieldLabels?: Record<string, string>;
    }>(),
    {
        title: () => t("History"),
        eventLabels: () => ({}),
        fieldLabels: () => ({}),
    },
);
const commonFieldLabels: Record<string, string> = {
    owner_id: t("Owner"),
    due_on: t("Due date"),
    completed_on: t("Completion date"),
};
const heading = useId();
const zone = (usePage().props.brand as { timezone?: string } | undefined)
    ?.timezone;
function readable(value: string): string {
    const words = (value.split(":").at(-1) ?? value).replace(/[_.-]/g, " ");
    return t(words.charAt(0).toUpperCase() + words.slice(1));
}
</script>
<template>
    <section class="crm-card crm-flow" :aria-labelledby="heading">
        <h2 :id="heading">{{ title }}</h2>
        <p v-if="!history.length" class="crm-muted">
            {{ t("No history recorded.") }}
        </p>
        <article v-for="entry in history" :key="entry.id" class="crm-note">
            <strong
                >{{ eventLabels[entry.event] ?? readable(entry.event)
                }}<span v-if="entry.version !== undefined">
                    {{ t("· Version") }} {{ entry.version }}</span
                ></strong
            >
            <p v-if="entry.changed_fields?.length">
                {{
                    entry.changed_fields
                        .map(
                            (field) =>
                                fieldLabels[field] ??
                                commonFieldLabels[field] ??
                                readable(field),
                        )
                        .join(", ")
                }}
            </p>
            <small
                >{{ entry.actor?.name ?? t("Former staff") }} ·
                <time :datetime="entry.created_at">{{
                    date(entry.created_at, zone)
                }}</time></small
            >
        </article>
    </section>
</template>
