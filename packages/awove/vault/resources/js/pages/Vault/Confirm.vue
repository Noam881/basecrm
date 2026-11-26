<script setup lang="ts">
import { t } from "@/lib/i18n";
import { Head, Link, router } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import VaultUnlock from "../../components/VaultUnlock.vue";
import {
    adoptStatus,
    request,
    securityGeneration,
    useVaultSecurity,
    VaultRequestError,
} from "../../security";
import type { AuthStatus } from "../../types";
const token = ref("");
const challenge = ref("");
const busy = ref(false);
const confirmed = ref(false);
const done = ref(false);
const error = ref("");
const security = useVaultSecurity(() => {
    token.value = "";
});
const emailRiddle = computed(
    () => security.status.value?.auth_mode === "email_riddle",
);
let removeInitialListener: (() => void) | undefined;
onBeforeUnmount(() => removeInitialListener?.());
onMounted(() => {
    const hash = new URLSearchParams(window.location.hash.slice(1));
    const incomingToken = hash.get("token") ?? "";
    const incomingChallenge = hash.get("challenge") ?? "";
    // Remove the fragment immediately, then replace Inertia's captured initial URL
    // once its first visit finishes. Otherwise initial navigation restores the fragment.
    const cleanUrl = window.location.pathname + window.location.search;
    window.history.replaceState(window.history.state, "", cleanUrl);
    removeInitialListener = router.once("navigate", () => {
        router.replace({
            url: cleanUrl,
            preserveState: true,
            preserveScroll: true,
        });
    });
    if (
        /^[A-Za-z0-9_-]{32,200}$/.test(incomingToken) &&
        /^[0-9a-f-]{36}$/i.test(incomingChallenge)
    ) {
        token.value = incomingToken;
        challenge.value = incomingChallenge;
    } else
        error.value = t(
            "This Vault confirmation link is incomplete. Request a new link from the original browser session.",
        );
});
async function confirm() {
    busy.value = true;
    error.value = "";
    const generation = securityGeneration();
    try {
        const status = await request<AuthStatus>(
            "/vault/api/auth/email",
            "POST",
            { challenge_id: challenge.value, token: token.value },
        );
        if (generation !== securityGeneration()) return;
        token.value = "";
        adoptStatus(status);
        confirmed.value = true;
    } catch (e) {
        error.value =
            e instanceof VaultRequestError
                ? t(e.message)
                : t("The confirmation could not be completed.");
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <Head :title="t('Confirm Vault email')" />
    <div class="crm-heading">
        <h1>{{ t("Confirm Vault email") }}</h1>
    </div>
    <div class="crm-flow">
        <section class="crm-card crm-flow">
            <p>
                {{
                    t(
                        "Confirm only if you requested this Vault unlock in this browser session. Opening this page has not confirmed the email or unlocked the Vault.",
                    )
                }}
            </p>
            <p v-if="error" role="alert" class="crm-notice">{{ error }}</p>
            <p v-if="confirmed" role="status" class="crm-notice">
                {{
                    emailRiddle
                        ? t(
                              "Email confirmed. Enter the Vault passphrase to unlock it.",
                          )
                        : t(
                              "Email confirmed. Complete the authenticator step to unlock the Vault.",
                          )
                }}
            </p>
            <div v-if="!confirmed" class="crm-form-action">
                <button
                    class="crm-button"
                    type="button"
                    :disabled="busy || !token"
                    @click="confirm"
                >
                    {{ busy ? t("Working…") : t("Confirm this Vault request") }}
                </button>
            </div>
            <Link href="/vault" class="crm-secondary">{{
                t("Return to Vault")
            }}</Link>
        </section>
        <VaultUnlock
            v-if="confirmed && !done"
            @unlocked="done = true"
            @close="done = true"
        />
        <p v-if="done" role="status">
            {{
                t(
                    "Return to the original Vault tab to continue. The server controls the remaining unlock time.",
                )
            }}
        </p>
    </div>
</template>
