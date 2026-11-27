<script setup lang="ts">
import { t } from "@/lib/i18n";
import { date } from "../../../../crm-core/resources/js/types";
import type { HistoryEntry } from "../history";
defineProps<{ entry: HistoryEntry; zone?: string; parentLoaded: boolean }>();
const labels = {
    facts: "Original financial facts",
    update: "Legacy update",
    reply: "Legacy reply",
};
</script>
<template>
    <div
        class="crm-flow"
        role="group"
        :aria-label="t(labels[entry.entry_kind])"
    >
        <div class="crm-field">
            <h3>{{ entry.label || t(labels[entry.entry_kind]) }}</h3>
            <small>
                <template v-if="entry.author.name"
                    >{{ t("Original author") }}:
                    <bdi>{{ entry.author.name }}</bdi></template
                >
                <template v-else>{{
                    t("Original author unavailable")
                }}</template>
                ·
                <bdi v-if="entry.source_created_at"
                    ><time
                        :datetime="entry.source_created_at"
                        :title="entry.source_created_at"
                        >{{ date(entry.source_created_at, zone) }}</time
                    ></bdi
                >
                <template v-else>{{ t("Original time unavailable") }}</template>
            </small>
            <small v-if="entry.source_updated_at"
                >{{ t("Source updated") }} ·
                <bdi
                    ><time
                        :datetime="entry.source_updated_at"
                        :title="entry.source_updated_at"
                        >{{ date(entry.source_updated_at, zone) }}</time
                    ></bdi
                ></small
            >
            <small v-if="entry.source_revision > 1">{{
                t("Legacy revision {revision}", {
                    revision: entry.source_revision,
                })
            }}</small>
            <small v-if="entry.parent_entry_id"
                ><a
                    v-if="parentLoaded"
                    :href="`#finance-history-${entry.parent_entry_id}`"
                    >{{
                        t("Reply to financial entry {id}", {
                            id: entry.parent_entry_id,
                        })
                    }}</a
                ><span v-else>{{
                    t("Reply to financial entry {id}", {
                        id: entry.parent_entry_id,
                    })
                }}</span></small
            >
        </div>
        <dl v-if="entry.facts.length" class="crm-detail-grid">
            <div
                v-for="fact in entry.facts"
                :key="fact.key"
                class="crm-detail-item"
            >
                <dt>
                    {{ fact.label }}
                    <small v-if="fact.currency || fact.unit"
                        >· <bdi>{{ fact.currency || fact.unit }}</bdi></small
                    >
                </dt>
                <dd class="crm-preserved-text" dir="auto">
                    <template v-if="fact.availability === 'present'">{{
                        fact.display
                    }}</template
                    ><template v-else-if="fact.availability === 'missing'">{{
                        t("Not provided in source")
                    }}</template
                    ><template v-else>{{
                        t("Unavailable in source")
                    }}</template>
                </dd>
            </div>
        </dl>
        <div v-if="entry.text" class="crm-preserved-text" dir="auto">
            {{ entry.text }}
        </div>
        <small v-if="entry.source_media_present">{{
            t("The source entry included embedded media.")
        }}</small>
        <small
            ><template v-if="entry.ingested_by"
                >{{ t("Imported by") }}:
                <bdi>{{ entry.ingested_by.name }}</bdi></template
            ><template v-else>{{ t("Importer unavailable") }}</template> ·
            <bdi
                ><time
                    :datetime="entry.ingested_at"
                    :title="entry.ingested_at"
                    >{{ date(entry.ingested_at, zone) }}</time
                ></bdi
            ></small
        >
    </div>
</template>
