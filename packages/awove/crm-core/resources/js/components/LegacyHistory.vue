<script setup lang="ts">
import { computed } from "vue";
import { t } from "@/lib/i18n";
import { date, type LegacyHistoryEntry } from "../types";

const props = defineProps<{
    entry: LegacyHistoryEntry;
    zone?: string;
    parentLoaded: boolean;
}>();
const labels: Record<LegacyHistoryEntry["kind"], string> = {
    update: "Legacy update",
    reply: "Legacy reply",
    "source-created": "Source record creation",
};
const title = computed(() => t(labels[props.entry.kind]));
const replyLabel = computed(() =>
    t("Reply to legacy activity {id}", {
        id: String(props.entry.parent_activity_id),
    }),
);
</script>

<template>
    <div class="crm-field" role="group" :aria-label="title">
        <strong>{{ title }}</strong>
        <small>
            <template v-if="entry.author.name">
                {{ t("Original author") }}:
                <bdi>{{ entry.author.name }}</bdi>
            </template>
            <template v-else>{{ t("Original author unavailable") }}</template>
            ·
            <bdi v-if="entry.source_created_at">
                <time
                    :datetime="entry.source_created_at"
                    :title="entry.source_created_at"
                    >{{ date(entry.source_created_at, zone) }}</time
                >
            </bdi>
            <template v-else>{{ t("Original time unavailable") }}</template>
        </small>
        <small v-if="entry.source_updated_at">
            {{ t("Source updated") }} ·
            <bdi
                ><time
                    :datetime="entry.source_updated_at"
                    :title="entry.source_updated_at"
                    >{{ date(entry.source_updated_at, zone) }}</time
                ></bdi
            >
        </small>
        <small v-if="entry.parent_activity_id !== null">
            <a
                v-if="parentLoaded"
                :href="`#activity-${entry.parent_activity_id}`"
                >{{ replyLabel }}</a
            >
            <span v-else>{{ replyLabel }}</span>
        </small>
        <small v-if="entry.revision > 1">{{
            t("Legacy revision {revision}", {
                revision: String(entry.revision),
            })
        }}</small>
        <div v-if="entry.text" class="crm-preserved-text" dir="auto">
            {{ entry.text }}
        </div>
        <small v-if="entry.source_media_present">{{
            t(
                "The source entry included embedded media. Check Documents for available files.",
            )
        }}</small>
        <small>
            <template v-if="entry.ingested_by">
                {{ t("Imported by") }}:
                <bdi>{{ entry.ingested_by.name }}</bdi>
            </template>
            <template v-else>{{ t("Importer unavailable") }}</template>
            ·
            <bdi
                ><time
                    :datetime="entry.ingested_at"
                    :title="entry.ingested_at"
                    >{{ date(entry.ingested_at, zone) }}</time
                ></bdi
            >
        </small>
    </div>
</template>
