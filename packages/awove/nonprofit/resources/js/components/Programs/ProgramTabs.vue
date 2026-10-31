<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { Link } from "@inertiajs/vue3";
import type { Options, Kind } from "./types";
import { titles } from "./types";
defineProps<Pick<Options, "canViewPrograms" | "canViewParticipants">>();
const general: Kind[] = [
    "programs",
    "locations",
    "groups",
    "sessions",
    "attendance",
    "feedback",
];
</script>
<template>
    <nav class="crm-tabs" :aria-label="tr('Programs sections')">
        <template v-if="canViewPrograms"
            ><Link
                v-for="kind in general.filter(
                    (k) => k !== 'attendance' || canViewParticipants,
                )"
                :key="kind"
                :href="`/nonprofit/programs?section=${kind}`"
                >{{ tr(titles[kind]) }}</Link
            ></template
        ><Link
            v-if="canViewParticipants"
            href="/nonprofit/programs?section=participants"
            >{{ tr("Participants") }}</Link
        >
    </nav>
</template>
