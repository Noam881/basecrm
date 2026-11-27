<script setup lang="ts">
import { ref, watch } from "vue";
import { t } from "@/lib/i18n";
import {
    currentGeneration,
    request,
    securityGeneration,
    useVaultSecurity,
} from "../security";

interface Progress {
    total: number;
    accepted: number;
    eligible: number;
    pending_request_key: string | null;
    source_configured: boolean;
}
const emit = defineEmits<{ imported: [] }>();
const progress = ref<Progress | null>(null);
const busy = ref(false);
const message = ref("");
let key: string | null = null;
let operation = 0;
const security = useVaultSecurity(() => {
    operation++;
    progress.value = null;
    message.value = "";
    busy.value = false;
});
async function load() {
    const captured = securityGeneration();
    try {
        const result = await request<Progress>(
            "/vault/api/imports/monday/workdocs",
            "GET",
            undefined,
            true,
        );
        if (!currentGeneration(captured)) return;
        progress.value = result;
        key = result.pending_request_key;
    } catch {
        if (currentGeneration(captured))
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
async function run() {
    if (busy.value || !progress.value || security.remaining.value <= 25) return;
    const captured = securityGeneration();
    const started = ++operation;
    busy.value = true;
    message.value = "";
    key ??= crypto.randomUUID();
    try {
        // One explicit click, one complete document, serial pages and the original fixed grant.
        const result = await request<Progress & { state: string }>(
            "/vault/api/imports/monday/workdocs/next",
            "POST",
            { request_key: key },
            true,
        );
        if (!currentGeneration(captured) || started !== operation) return;
        progress.value = result;
        if (result.state === "verified") {
            key = null;
            emit("imported");
            message.value = t("Protected workdoc accepted.");
        } else
            message.value = t("Import source records before their workdocs.");
    } catch {
        if (currentGeneration(captured))
            message.value = t(
                "Import stopped. Retry the retained request after reviewing the issue.",
            );
    } finally {
        if (started === operation) busy.value = false;
    }
}
</script>

<template>
    <section class="crm-card crm-flow" aria-labelledby="vault-workdocs-title">
        <h2 id="vault-workdocs-title">{{ t("Protected workdocs") }}</h2>
        <p class="crm-muted">
            {{
                t(
                    "Workdocs are encrypted notes with their original structured TXT export.",
                )
            }}
        </p>
        <p v-if="!security.unlocked.value" class="crm-muted">
            {{ t("Unlock the Vault to continue the approved import.") }}
        </p>
        <template v-else>
            <p v-if="progress" role="status">
                {{ t("Protected workdocs accepted") }}:
                <bdi dir="ltr"
                    >{{ progress.accepted }} / {{ progress.total }}</bdi
                >
            </p>
            <div class="crm-form-action">
                <button
                    class="crm-button"
                    type="button"
                    :disabled="
                        busy ||
                        !progress ||
                        !progress.source_configured ||
                        progress.eligible < 1 ||
                        security.remaining.value <= 25
                    "
                    @click="run"
                >
                    {{ busy ? t("Working…") : t("Import one workdoc") }}
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
