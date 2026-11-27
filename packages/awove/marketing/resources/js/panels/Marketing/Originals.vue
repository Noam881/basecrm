<script setup lang="ts">
import { t } from "@/lib/i18n";
import { onBeforeUnmount, ref, watch } from "vue";
const props = defineProps<{ endpoint: string }>();
type Entry = {
    id: number;
    source_revision: number;
    imported_at: string;
    author: { name: string | null };
    source_created_at: string | null;
    cell_count: number;
    source: { board_id: string; item_id: string };
};
type Original = {
    cells: {
        id: string;
        label: string;
        type: string;
        representation: string;
        available: boolean;
        value: string | null;
    }[];
};
const entries = ref<Entry[]>([]);
const next = ref<number | null>(null);
const selected = ref<Original | null>(null);
const busy = ref(false);
const error = ref("");
let controller: AbortController | undefined;
async function load(before?: number) {
    controller?.abort();
    controller = new AbortController();
    const signal = controller.signal;
    busy.value = true;
    error.value = "";
    try {
        const response = await fetch(
            props.endpoint + (before ? `?before=${before}` : ""),
            {
                headers: { Accept: "application/json" },
                cache: "no-store",
                signal,
            },
        );
        if (!response.ok) throw new Error();
        const result = (await response.json()) as {
            entries: Entry[];
            next_before: number | null;
        };
        if (
            !Array.isArray(result.entries) ||
            (result.next_before !== null &&
                !Number.isSafeInteger(result.next_before))
        )
            throw new Error();
        if (!signal.aborted) {
            entries.value = before
                ? [...entries.value, ...result.entries]
                : result.entries;
            next.value = result.next_before;
        }
    } catch {
        if (!signal.aborted)
            error.value = t("Original marketing data could not be loaded.");
    } finally {
        if (!signal.aborted) busy.value = false;
    }
}
async function open(entry: Entry) {
    controller?.abort();
    controller = new AbortController();
    const signal = controller.signal;
    busy.value = true;
    error.value = "";
    selected.value = null;
    try {
        const response = await fetch(`${props.endpoint}/${entry.id}`, {
            headers: { Accept: "application/json" },
            cache: "no-store",
            signal,
        });
        if (!response.ok) throw new Error();
        const value = (await response.json()) as Original;
        if (
            !Array.isArray(value.cells) ||
            !value.cells.every(
                (c) =>
                    typeof c.label === "string" &&
                    typeof c.type === "string" &&
                    typeof c.available === "boolean" &&
                    (c.value === null || typeof c.value === "string"),
            )
        )
            throw new Error();
        if (!signal.aborted) selected.value = value;
    } catch {
        if (!signal.aborted)
            error.value = t("Original marketing data could not be loaded.");
    } finally {
        if (!signal.aborted) busy.value = false;
    }
}
watch(
    () => props.endpoint,
    () => {
        entries.value = [];
        selected.value = null;
        next.value = null;
        void load();
    },
    { immediate: true },
);
onBeforeUnmount(() => controller?.abort());
</script>
<template>
    <section class="crm-flow" :aria-busy="busy">
        <h3>{{ t("Original marketing data") }}</h3>
        <p v-if="error" class="crm-error" role="alert">{{ error }}</p>
        <p v-if="busy" class="crm-muted" role="status">
            {{ t("Loading original data…") }}
        </p>
        <p v-if="!busy && !error && !entries.length" class="crm-muted">
            {{ t("No original marketing data yet.") }}
        </p>
        <article
            v-for="entry in entries"
            :key="entry.id"
            class="crm-note crm-flow"
        >
            <p>
                <bdi>{{
                    entry.author.name ?? t("Original author unavailable")
                }}</bdi>
                ·
                <bdi>{{
                    entry.source_created_at ?? t("Original time unavailable")
                }}</bdi>
            </p>
            <p class="crm-muted">
                {{ t("Source item") }} <bdi>{{ entry.source.item_id }}</bdi> ·
                {{ t("Source board") }} <bdi>{{ entry.source.board_id }}</bdi>
            </p>
            <button
                class="crm-secondary"
                type="button"
                :disabled="busy"
                @click="open(entry)"
            >
                {{ t("View original fields") }} · {{ entry.source_revision }}
            </button>
        </article>
        <button
            v-if="next !== null"
            type="button"
            class="crm-secondary"
            :disabled="busy"
            @click="load(next)"
        >
            {{ t("Load earlier originals") }}
        </button>
        <dl v-if="selected" class="crm-flow">
            <div
                v-for="cell in selected.cells"
                :key="cell.id"
                class="crm-note crm-flow"
            >
                <dt>
                    <bdi>{{ cell.label }}</bdi> · <bdi>{{ cell.type }}</bdi>
                </dt>
                <dd>
                    <pre class="crm-original-value">{{
                        !cell.available
                            ? t("Source value unavailable")
                            : cell.value === null
                              ? t("Observed null value")
                              : cell.value
                    }}</pre>
                </dd>
            </div>
        </dl>
    </section>
</template>
<style scoped>
.crm-original-value {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    unicode-bidi: plaintext;
    margin: 0;
    font: inherit;
}
</style>
