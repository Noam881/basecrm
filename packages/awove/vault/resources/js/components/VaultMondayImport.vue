<script setup lang="ts">
import { ref, watch } from "vue";
import { t } from "@/lib/i18n";
import {
    currentGeneration,
    isUnlocked,
    request,
    securityGeneration,
    useVaultSecurity,
} from "../security";

interface Progress {
    total: number;
    accepted: number;
    pending_request_key: string | null;
    binary_assets_pending: number;
    workdocs_pending: number;
    source_configured: boolean;
    file_scope_ready: boolean;
    file_total: number;
    files_accepted: number;
    files_eligible: number;
    pending_file_request_key: string | null;
    migration_complete: false;
}
const emit = defineEmits<{ imported: [] }>();
const progress = ref<Progress | null>(null);
const busy = ref(false);
const message = ref("");
let requestKey: string | null = null;
let fileRequestKey: string | null = null;
let operation = 0;
const security = useVaultSecurity(() => {
    operation++;
    progress.value = null;
    message.value = "";
    busy.value = false;
});
async function load() {
    if (!isUnlocked()) return;
    try {
        progress.value = await request<Progress>(
            "/vault/api/imports/monday",
            "GET",
            undefined,
            true,
        );
        requestKey = progress.value.pending_request_key;
        fileRequestKey = progress.value.pending_file_request_key;
    } catch {
        message.value = t(
            "The approved import is unavailable. Contact the installation operator.",
        );
    }
}
watch(
    security.unlocked,
    (unlocked) => {
        if (unlocked) void load();
    },
    { immediate: true },
);
async function run(phase: "items" | "files", maximum: 1 | 5 = 5) {
    if (busy.value || !progress.value) return;
    const captured = securityGeneration();
    const started = ++operation;
    busy.value = true;
    message.value = "";
    let changed = false;
    try {
        // An explicit click permits at most five serial single-entry requests.
        for (let index = 0; index < maximum; index++) {
            if (
                !currentGeneration(captured) ||
                started !== operation ||
                security.remaining.value <= (phase === "files" ? 45 : 25)
            )
                break;
            if (
                !progress.value ||
                (phase === "files"
                    ? progress.value.files_eligible < 1 ||
                      progress.value.files_accepted >= progress.value.file_total
                    : progress.value.accepted >= progress.value.total)
            )
                break;
            if (phase === "files") fileRequestKey ??= crypto.randomUUID();
            else requestKey ??= crypto.randomUUID();
            const result: Progress & { state: string } = await request(
                phase === "files"
                    ? "/vault/api/imports/monday/next-file"
                    : "/vault/api/imports/monday/next",
                "POST",
                {
                    request_key:
                        phase === "files" ? fileRequestKey : requestKey,
                },
                true,
            );
            if (!currentGeneration(captured) || started !== operation) break;
            progress.value = { ...progress.value, ...result };
            if (result.state === "verified") {
                if (phase === "files") fileRequestKey = null;
                else requestKey = null;
            }
            changed = true;
            if (result.state !== "verified") {
                message.value = t(
                    result.state === "awaiting-items"
                        ? "Import source records before their files."
                        : "This transfer requires operator review.",
                );
                break;
            }
        }
        if (currentGeneration(captured) && !message.value)
            message.value = t(
                "Batch finished. Continue while the Vault is unlocked.",
            );
    } catch {
        if (currentGeneration(captured))
            message.value = t(
                "Import stopped. Retry the retained request after reviewing the issue.",
            );
    } finally {
        if (started === operation) busy.value = false;
        if (changed) emit("imported");
    }
}
</script>

<template>
    <section class="crm-card crm-flow" aria-labelledby="vault-monday-title">
        <h2 id="vault-monday-title">{{ t("Approved Monday import") }}</h2>
        <p v-if="!security.unlocked.value" class="crm-muted">
            {{ t("Unlock the Vault to continue the approved import.") }}
        </p>
        <template v-else>
            <p
                v-if="progress && !progress.source_configured"
                class="crm-notice"
            >
                {{ t("Import connection is not configured yet.") }}
            </p>
            <p v-if="progress" role="status">
                {{ t("Secure records accepted") }}:
                <bdi dir="ltr"
                    >{{ progress.accepted }} / {{ progress.total }}</bdi
                >
            </p>
            <p v-if="progress" class="crm-muted">
                {{ t("Binary files pending") }}:
                {{ progress.binary_assets_pending }} ·
                {{ t("Workdocs pending") }}: {{ progress.workdocs_pending }}
            </p>
            <div class="crm-form-action">
                <button
                    class="crm-secondary"
                    type="button"
                    :disabled="
                        busy ||
                        !progress ||
                        !progress.source_configured ||
                        security.remaining.value <= 25 ||
                        progress.accepted >= progress.total
                    "
                    @click="run('items', 1)"
                >
                    {{ t("Import one record") }}
                </button>
                <button
                    class="crm-button"
                    type="button"
                    :disabled="
                        busy ||
                        !progress ||
                        !progress.source_configured ||
                        security.remaining.value <= 25 ||
                        progress.accepted >= progress.total
                    "
                    @click="run('items')"
                >
                    {{ busy ? t("Working…") : t("Import next 5 records") }}
                </button>
                <button
                    class="crm-button"
                    type="button"
                    :disabled="
                        busy ||
                        !progress ||
                        !progress.source_configured ||
                        !progress.file_scope_ready ||
                        progress.accepted < 1 ||
                        progress.files_eligible < 1 ||
                        progress.files_accepted >= progress.file_total ||
                        security.remaining.value <= 45
                    "
                    @click="run('files')"
                >
                    {{ t("Import next 5 files") }}
                </button>
                <button
                    class="crm-secondary"
                    type="button"
                    :disabled="busy"
                    @click="load"
                >
                    {{ t("Refresh") }}
                </button>
            </div>
        </template>
        <p v-if="message" class="crm-notice" role="status">{{ message }}</p>
    </section>
</template>
