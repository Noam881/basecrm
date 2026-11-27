<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { t } from "@/lib/i18n";
import { type LegacyActivity } from "../types";
import LegacyHistory from "./LegacyHistory.vue";

const props = defineProps<{ leadId: number; zone?: string }>();
const endpoint = computed(() => `/leads/${props.leadId}/legacy-history`);
const entries = ref<LegacyActivity[]>([]);
const nextCursor = ref<number | null>(null);
const loading = ref(true);
const error = ref("");
const fullErrors = ref<Record<number, string>>({});
const fullLoading = ref(new Set<number>());
const loadedIds = computed(
    () => new Set(entries.value.map((entry) => entry.id)),
);
let controller: AbortController | undefined;
let lastCursor: number | null = null;
let generation = 0;
const fullControllers = new Map<number, AbortController>();

function validEntry(entry: LegacyActivity) {
    return (
        entry &&
        Number.isSafeInteger(entry.id) &&
        entry.id > 0 &&
        entry.event === "lead.legacy_history" &&
        entry.actor === null &&
        entry.legacy &&
        ["update", "reply", "source-created"].includes(entry.legacy.kind) &&
        typeof entry.legacy.text === "string" &&
        typeof entry.legacy.source_media_present === "boolean" &&
        typeof entry.legacy.has_more_text === "boolean" &&
        (entry.legacy.has_more_text
            ? entry.legacy.full_text_url === `${endpoint.value}/${entry.id}`
            : entry.legacy.full_text_url === null)
    );
}

async function load(cursor: number | null = null) {
    if (loading.value && controller) return;
    const active = new AbortController();
    controller = active;
    const currentEndpoint = endpoint.value;
    const currentGeneration = generation;
    lastCursor = cursor;
    loading.value = true;
    error.value = "";
    try {
        const response = await fetch(
            `${currentEndpoint}${cursor === null ? "" : `?before=${cursor}`}`,
            {
                headers: {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
                cache: "no-store",
                signal: active.signal,
            },
        );
        if (!response.ok) throw new Error();
        const result = (await response.json()) as {
            entries: LegacyActivity[];
            next_cursor: number | null;
        };
        if (
            !Array.isArray(result.entries) ||
            !result.entries.every(validEntry) ||
            (result.next_cursor !== null &&
                (!Number.isSafeInteger(result.next_cursor) ||
                    result.next_cursor <= 0))
        )
            throw new Error();
        if (
            active.signal.aborted ||
            currentGeneration !== generation ||
            endpoint.value !== currentEndpoint
        )
            return;
        const combined = cursor === null ? [] : entries.value;
        const ids = new Set(combined.map((entry) => entry.id));
        entries.value = [
            ...combined,
            ...result.entries.filter((entry) => !ids.has(entry.id)),
        ];
        nextCursor.value = result.next_cursor;
    } catch {
        if (
            !active.signal.aborted &&
            currentGeneration === generation &&
            endpoint.value === currentEndpoint
        )
            error.value = t(
                "Legacy history could not be loaded. Please try again.",
            );
    } finally {
        if (controller === active) {
            controller = undefined;
            loading.value = false;
        }
    }
}

async function loadFull(entry: LegacyActivity) {
    if (fullLoading.value.has(entry.id) || !entry.legacy.has_more_text) return;
    const active = new AbortController();
    fullControllers.set(entry.id, active);
    fullLoading.value.add(entry.id);
    delete fullErrors.value[entry.id];
    const currentEndpoint = endpoint.value;
    const currentGeneration = generation;
    try {
        // Construct the installation-local read path; never follow a source URL from legacy content.
        const response = await fetch(`${currentEndpoint}/${entry.id}`, {
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
            cache: "no-store",
            signal: active.signal,
        });
        if (!response.ok) throw new Error();
        const result = (await response.json()) as { entry: LegacyActivity };
        if (
            !validEntry(result.entry) ||
            result.entry.id !== entry.id ||
            result.entry.legacy.has_more_text
        )
            throw new Error();
        if (
            active.signal.aborted ||
            currentGeneration !== generation ||
            endpoint.value !== currentEndpoint
        )
            return;
        entries.value = entries.value.map((existing) =>
            existing.id === entry.id ? result.entry : existing,
        );
    } catch {
        if (
            !active.signal.aborted &&
            currentGeneration === generation &&
            endpoint.value === currentEndpoint
        )
            fullErrors.value[entry.id] = t(
                "The full legacy text could not be loaded. Please try again.",
            );
    } finally {
        if (fullControllers.get(entry.id) === active) {
            fullControllers.delete(entry.id);
            fullLoading.value.delete(entry.id);
        }
    }
}

function abort() {
    generation++;
    controller?.abort();
    controller = undefined;
    for (const active of fullControllers.values()) active.abort();
    fullControllers.clear();
}
watch(
    endpoint,
    () => {
        abort();
        entries.value = [];
        nextCursor.value = null;
        fullErrors.value = {};
        fullLoading.value = new Set();
        loading.value = true;
        void load();
    },
    { immediate: true },
);
onBeforeUnmount(abort);
</script>

<template>
    <section
        v-if="entries.length || loading || error"
        class="crm-card crm-flow"
        :aria-label="t('Legacy history')"
        :aria-busy="loading"
    >
        <h2>{{ t("Legacy history") }}</h2>
        <article
            v-for="entry in entries"
            :id="`activity-${entry.id}`"
            :key="entry.id"
            class="crm-activity"
        >
            <span class="crm-task-dot" />
            <div class="crm-flow">
                <LegacyHistory
                    :entry="entry.legacy"
                    :zone="zone"
                    :parent-loaded="
                        entry.legacy.parent_activity_id !== null &&
                        loadedIds.has(entry.legacy.parent_activity_id)
                    "
                />
                <p v-if="fullErrors[entry.id]" role="alert" class="crm-error">
                    {{ fullErrors[entry.id] }}
                </p>
                <div v-if="entry.legacy.has_more_text" class="crm-form-action">
                    <button
                        type="button"
                        class="crm-secondary"
                        :disabled="fullLoading.has(entry.id)"
                        @click="loadFull(entry)"
                    >
                        {{
                            fullLoading.has(entry.id)
                                ? t("Loading full text…")
                                : fullErrors[entry.id]
                                  ? t("Try again")
                                  : t("Read full text")
                        }}
                    </button>
                </div>
            </div>
        </article>
        <p v-if="loading" role="status" class="crm-muted">
            {{ t("Loading legacy history…") }}
        </p>
        <p v-if="error" role="alert" class="crm-error">{{ error }}</p>
        <div v-if="error || nextCursor !== null" class="crm-form-action">
            <button
                type="button"
                class="crm-secondary"
                :disabled="loading"
                @click="load(error ? lastCursor : nextCursor)"
            >
                {{ error ? t("Try again") : t("Load older history") }}
            </button>
        </div>
    </section>
</template>
