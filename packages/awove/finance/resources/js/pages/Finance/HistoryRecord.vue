<script setup lang="ts">
import { t } from "@/lib/i18n";
import { Head, Link, usePage } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, ref } from "vue";
import FinanceNav from "../../components/FinanceNav.vue";
import HistoryEntryCard from "../../components/HistoryEntry.vue";
import {
    historyKinds,
    historyPlacements,
    readHistory,
    type HistoryEntry,
    type HistoryRecord,
} from "../../history";
import type { Section } from "../../types";
const props = defineProps<{
    record: HistoryRecord;
    entries: HistoryEntry[];
    next_cursor: number | null;
    sections: Section[];
}>();
const page = usePage();
const zone = computed(
    () => (page.props.brand as { timezone?: string } | undefined)?.timezone,
);
const entries = ref([...props.entries]);
const next = ref(props.next_cursor);
const busy = ref(false);
const error = ref("");
const fullBusy = ref(new Set<number>());
const fullErrors = ref<Record<number, string>>({});
const controllers = new Set<AbortController>();
const loadedIds = computed(
    () => new Set(entries.value.map((entry) => entry.id)),
);
async function more() {
    if (busy.value || next.value === null) return;
    const abort = new AbortController();
    controllers.add(abort);
    busy.value = true;
    error.value = "";
    try {
        const data = await readHistory<{
            entries: HistoryEntry[];
            next_cursor: number | null;
        }>(
            `/finance/history/${props.record.id}/entries?before=${next.value}`,
            abort.signal,
        );
        if (abort.signal.aborted) return;
        const seen = loadedIds.value;
        entries.value.push(
            ...data.entries.filter((entry) => !seen.has(entry.id)),
        );
        next.value = data.next_cursor;
    } catch {
        if (!abort.signal.aborted)
            error.value = t(
                "Financial history could not be loaded. Please try again.",
            );
    } finally {
        controllers.delete(abort);
        busy.value = false;
    }
}
async function full(entry: HistoryEntry) {
    if (fullBusy.value.has(entry.id)) return;
    const abort = new AbortController();
    controllers.add(abort);
    fullBusy.value.add(entry.id);
    delete fullErrors.value[entry.id];
    try {
        const data = await readHistory<{ entry: HistoryEntry }>(
            `/finance/history/${props.record.id}/entries/${entry.id}`,
            abort.signal,
        );
        if (!abort.signal.aborted && data.entry.id === entry.id)
            entries.value = entries.value.map((row) =>
                row.id === entry.id ? data.entry : row,
            );
    } catch {
        if (!abort.signal.aborted)
            fullErrors.value[entry.id] = t(
                "Financial history could not be loaded. Please try again.",
            );
    } finally {
        controllers.delete(abort);
        fullBusy.value.delete(entry.id);
    }
}
onBeforeUnmount(() => controllers.forEach((controller) => controller.abort()));
</script>
<template>
    <Head :title="record.label || t('Financial history')" />
    <div class="crm-heading">
        <h1>{{ record.label || t("Financial history") }}</h1>
        <Link class="crm-secondary" href="/finance/history">{{
            t("Back to financial history")
        }}</Link>
    </div>
    <div class="crm-flow">
        <FinanceNav :sections="sections" />
        <p class="crm-muted">
            {{
                t(
                    "Historical references are excluded from balances and reminders.",
                )
            }}
        </p>
        <section class="crm-card crm-flow">
            <dl class="crm-detail-grid">
                <div class="crm-detail-item">
                    <dt>{{ t("Record kind") }}</dt>
                    <dd>{{ t(historyKinds[record.kind]) }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>{{ t("Association") }}</dt>
                    <dd>{{ t(historyPlacements[record.placement]) }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>{{ t("Owner") }}</dt>
                    <dd>{{ record.owner?.name || t("Unassigned") }}</dd>
                </div>
                <div v-if="record.identities.length" class="crm-detail-item">
                    <dt>{{ t("Linked identities") }}</dt>
                    <dd>
                        <ul>
                            <li
                                v-for="identity in record.identities"
                                :key="`${identity.type}:${identity.id}`"
                            >
                                <Link :href="identity.profile_url"
                                    ><bdi>{{ identity.name }}</bdi></Link
                                >
                            </li>
                        </ul>
                    </dd>
                </div>
            </dl>
        </section>
        <section
            v-for="entry in entries"
            :id="`finance-history-${entry.id}`"
            :key="entry.id"
            class="crm-card crm-flow"
        >
            <HistoryEntryCard
                :entry="entry"
                :zone="zone"
                :parent-loaded="loadedIds.has(entry.parent_entry_id ?? 0)"
            />
            <div v-if="entry.has_more_text" class="crm-form-action">
                <button
                    class="crm-secondary"
                    :disabled="fullBusy.has(entry.id)"
                    @click="full(entry)"
                >
                    {{
                        t(
                            fullBusy.has(entry.id)
                                ? "Loading…"
                                : "Read full entry",
                        )
                    }}
                </button>
            </div>
            <p v-if="fullErrors[entry.id]" class="crm-alert" role="alert">
                {{ fullErrors[entry.id] }}
            </p>
        </section>
        <p v-if="error" class="crm-alert" role="alert">{{ error }}</p>
        <div v-if="next !== null" class="crm-form-action">
            <button class="crm-secondary" :disabled="busy" @click="more">
                {{ t(busy ? "Loading…" : "Load older entries") }}
            </button>
        </div>
    </div>
</template>
