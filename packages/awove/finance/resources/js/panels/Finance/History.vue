<script setup lang="ts">
import { t } from "@/lib/i18n";
import { Link } from "@inertiajs/vue3";
import { onBeforeUnmount, ref, watch } from "vue";
import { historyKinds, readHistory } from "../../history";
const props = defineProps<{ endpoint: string }>();
type Row = {
    id: number;
    label: string | null;
    kind: string;
    record_url: string;
};
const records = ref<Row[]>([]);
const cursor = ref<number | null>(null);
const busy = ref(false);
const error = ref("");
let controller: AbortController | undefined;
async function load(before: number | null = null) {
    if (busy.value) return;
    const abort = new AbortController();
    controller = abort;
    busy.value = true;
    error.value = "";
    try {
        const data = await readHistory<{
            records: Row[];
            next_cursor: number | null;
        }>(
            props.endpoint + (before === null ? "" : `?before=${before}`),
            abort.signal,
        );
        if (abort.signal.aborted || controller !== abort) return;
        records.value =
            before === null
                ? data.records
                : [...records.value, ...data.records];
        cursor.value = data.next_cursor;
    } catch {
        if (!abort.signal.aborted && controller === abort)
            error.value = t(
                "Financial history could not be loaded. Please try again.",
            );
    } finally {
        if (controller === abort) {
            controller = undefined;
            busy.value = false;
        }
    }
}
watch(
    () => props.endpoint,
    () => {
        controller?.abort();
        controller = undefined;
        busy.value = false;
        records.value = [];
        cursor.value = null;
        void load();
    },
    { immediate: true },
);
onBeforeUnmount(() => controller?.abort());
</script>
<template>
    <div class="crm-flow">
        <p class="crm-muted">
            {{
                t(
                    "Historical references are excluded from balances and reminders.",
                )
            }}
        </p>
        <p v-if="error" class="crm-alert" role="alert">{{ error }}</p>
        <p v-if="busy" role="status">{{ t("Loading…") }}</p>
        <p v-if="!busy && !error && records.length === 0">
            {{ t("No financial history is available for this profile.") }}
        </p>
        <div v-for="record in records" :key="record.id" class="crm-field">
            <Link :href="`/finance/history/${record.id}`"
                ><bdi>{{ record.label || t("Financial history") }}</bdi></Link
            ><small>{{ t(historyKinds[record.kind]) }}</small>
        </div>
        <div v-if="error || cursor !== null" class="crm-form-action">
            <button
                class="crm-secondary"
                :disabled="busy"
                @click="load(cursor)"
            >
                {{ t(error ? "Try again" : "Load more") }}
            </button>
        </div>
    </div>
</template>
