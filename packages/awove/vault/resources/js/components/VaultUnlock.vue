<script setup lang="ts">
import { t } from "@/lib/i18n";
import { computed, ref } from "vue";
import {
    adoptStatus,
    isUnlocked,
    request,
    securityGeneration,
    useVaultSecurity,
    VaultRequestError,
} from "../security";
import type { AuthStatus } from "../types";
const emit = defineEmits<{ close: []; unlocked: [] }>();
const password = ref("");
const confirmation = ref("");
const invitation = ref("");
const code = ref("");
const recovery = ref("");
const answer = ref("");
let operation = 0;
const useRecovery = ref(false);
const setup = ref<{ secret: string; otpauth_uri: string } | null>(null);
const recoveryCodes = ref<string[]>([]);
const busy = ref(false);
const error = ref("");
const notice = ref("");
function clear() {
    operation++;
    password.value = "";
    confirmation.value = "";
    invitation.value = "";
    code.value = "";
    recovery.value = "";
    answer.value = "";
    setup.value = null;
    recoveryCodes.value = [];
}
const security = useVaultSecurity(clear);
const status = security.status;
const step = computed(() => status.value?.step ?? "password");
function failed(e: unknown) {
    error.value =
        e instanceof VaultRequestError
            ? t(e.message)
            : t("The Vault request could not be completed.");
}
async function authenticate(path: string, body: object) {
    busy.value = true;
    error.value = "";
    notice.value = "";
    const generation = securityGeneration();
    const operationId = operation;
    const started = performance.now();
    try {
        const next = await request<AuthStatus>(
            `/vault/api/auth/${path}`,
            "POST",
            body,
        );
        if (generation !== securityGeneration() || operationId !== operation)
            return;
        adoptStatus(next, started);
        password.value = "";
        code.value = "";
        recovery.value = "";
        answer.value = "";
        if (next.unlocked && isUnlocked()) emit("unlocked");
        else if (path === "resend")
            notice.value = t(
                "A new confirmation link was sent. The previous link no longer works.",
            );
    } catch (e) {
        failed(e);
    } finally {
        busy.value = false;
    }
}
async function enroll() {
    busy.value = true;
    error.value = "";
    const generation = securityGeneration();
    const operationId = operation;
    try {
        const result = await request<{ secret: string; otpauth_uri: string }>(
            "/vault/api/auth/enroll",
            "POST",
            {
                invitation: invitation.value,
                password: password.value,
                password_confirmation: confirmation.value,
            },
        );
        if (generation !== securityGeneration() || operationId !== operation)
            return;
        setup.value = result;
        password.value = "";
        confirmation.value = "";
        invitation.value = "";
    } catch (e) {
        failed(e);
    } finally {
        busy.value = false;
    }
}
async function confirmEnrollment() {
    busy.value = true;
    error.value = "";
    const generation = securityGeneration();
    const operationId = operation;
    try {
        const result = await request<AuthStatus & { recovery_codes: string[] }>(
            "/vault/api/auth/enroll/confirm",
            "POST",
            { code: code.value },
        );
        if (generation !== securityGeneration() || operationId !== operation)
            return;
        setup.value = null;
        code.value = "";
        recoveryCodes.value = result.recovery_codes;
        adoptStatus(result);
    } catch (e) {
        failed(e);
    } finally {
        busy.value = false;
    }
}
async function copySetup(value: string) {
    const generation = securityGeneration();
    const operationId = operation;
    try {
        if (generation !== securityGeneration() || operationId !== operation)
            return;
        await navigator.clipboard.writeText(value);
        if (generation === securityGeneration() && operationId === operation)
            notice.value = t("Copied.");
    } catch {
        error.value = t("Copying is unavailable. Select the value manually.");
    }
}
function close() {
    clear();
    emit("close");
}
</script>
<template>
    <section class="crm-card crm-flow" aria-labelledby="vault-unlock-heading">
        <div class="crm-heading">
            <h2 id="vault-unlock-heading">{{ t("Unlock Vault") }}</h2>
            <button type="button" class="crm-secondary" @click="close">
                {{ t("Close") }}
            </button>
        </div>
        <p class="crm-muted">
            {{
                t(
                    "Vault authentication is separate from CRM email-link sign-in.",
                )
            }}
        </p>
        <p v-if="status?.dev_question_mode" role="alert" class="crm-notice">
            {{
                t(
                    "Development mode: fictional data only. The test question must never protect real information.",
                )
            }}
        </p>
        <p v-if="error" role="alert" class="crm-notice">{{ error }}</p>
        <p v-if="notice" role="status" class="crm-notice">{{ notice }}</p>
        <p v-if="!security.ready.value" role="status">
            {{ t("Checking Vault access…") }}
        </p>
        <p v-else-if="!status?.approved" class="crm-muted">
            {{
                t(
                    "An authorized Vault administrator must approve enrollment and provide an invitation before you can continue.",
                )
            }}
        </p>
        <template v-else-if="recoveryCodes.length">
            <h3>{{ t("Save your recovery codes") }}</h3>
            <p>
                {{
                    t(
                        "These codes are shown once. Each replaces only the authenticator step; your Vault password and a fresh email confirmation are still required.",
                    )
                }}
            </p>
            <pre data-vault-sensitive dir="ltr">{{
                recoveryCodes.join("\n")
            }}</pre>
            <div class="crm-form-action">
                <button
                    type="button"
                    class="crm-secondary"
                    @click="copySetup(recoveryCodes.join('\n'))"
                >
                    {{ t("Copy recovery codes") }}</button
                ><button
                    type="button"
                    class="crm-button"
                    @click="recoveryCodes = []"
                >
                    {{ t("I saved the recovery codes") }}
                </button>
            </div>
        </template>
        <template v-else-if="!status?.enrolled">
            <form v-if="!setup" class="crm-form" @submit.prevent="enroll">
                <label class="crm-field"
                    ><span class="crm-field-label">{{
                        t("Vault invitation")
                    }}</span
                    ><input
                        v-model="invitation"
                        data-vault-sensitive
                        required
                        autocomplete="off"
                        :disabled="busy"
                        spellcheck="false"
                        dir="ltr"
                /></label>
                <label class="crm-field"
                    ><span class="crm-field-label">{{
                        t("New Vault password")
                    }}</span
                    ><input
                        v-model="password"
                        data-vault-sensitive
                        type="password"
                        minlength="12"
                        required
                        autocomplete="new-password"
                        :disabled="busy"
                /></label>
                <label class="crm-field"
                    ><span class="crm-field-label">{{
                        t("Confirm Vault password")
                    }}</span
                    ><input
                        v-model="confirmation"
                        data-vault-sensitive
                        type="password"
                        minlength="12"
                        required
                        autocomplete="new-password"
                        :disabled="busy"
                /></label>
                <div class="crm-form-action">
                    <button class="crm-button" :disabled="busy">
                        {{ busy ? t("Working…") : t("Set up authenticator") }}
                    </button>
                </div>
            </form>
            <form v-else class="crm-form" @submit.prevent="confirmEnrollment">
                <p>
                    {{
                        t(
                            "Add this account to your authenticator app using the setup key, then enter its current code.",
                        )
                    }}
                </p>
                <label class="crm-field"
                    ><span class="crm-field-label">{{
                        t("Authenticator setup key")
                    }}</span
                    ><input
                        :value="setup.secret"
                        data-vault-sensitive
                        readonly
                        dir="ltr"
                        autocomplete="off"
                /></label>
                <div class="crm-form-action">
                    <button
                        type="button"
                        class="crm-secondary"
                        @click="copySetup(setup.secret)"
                    >
                        {{ t("Copy setup key") }}</button
                    ><button
                        type="button"
                        class="crm-secondary"
                        @click="copySetup(setup.otpauth_uri)"
                    >
                        {{ t("Copy authenticator URI") }}
                    </button>
                </div>
                <label class="crm-field"
                    ><span class="crm-field-label">{{
                        t("Authenticator code")
                    }}</span
                    ><input
                        v-model="code"
                        data-vault-sensitive
                        required
                        inputmode="numeric"
                        pattern="[0-9]{6}"
                        maxlength="6"
                        autocomplete="one-time-code"
                        dir="ltr"
                        :disabled="busy"
                /></label>
                <div class="crm-form-action">
                    <button class="crm-button" :disabled="busy">
                        {{ busy ? t("Working…") : t("Confirm authenticator") }}
                    </button>
                </div>
            </form>
        </template>
        <form
            v-else-if="step === 'password'"
            class="crm-form"
            @submit.prevent="authenticate('password', { password })"
        >
            <label class="crm-field"
                ><span class="crm-field-label">{{ t("Vault password") }}</span
                ><input
                    v-model="password"
                    data-vault-sensitive
                    type="password"
                    required
                    autocomplete="current-password"
                    :disabled="busy"
            /></label>
            <div class="crm-form-action">
                <button class="crm-button" :disabled="busy">
                    {{
                        busy
                            ? t("Working…")
                            : t("Send Vault confirmation email")
                    }}
                </button>
            </div>
        </form>
        <div v-else-if="step === 'email'" class="crm-flow">
            <p>
                {{
                    t(
                        "Open the new Vault confirmation email in the same browser session. The link requires an explicit confirmation before the authenticator step.",
                    )
                }}
            </p>
            <div class="crm-form-action">
                <button
                    class="crm-secondary"
                    type="button"
                    :disabled="busy"
                    @click="security.refreshStatus().catch(failed)"
                >
                    {{ t("Check email confirmation") }}</button
                ><button
                    class="crm-secondary"
                    type="button"
                    :disabled="busy || !status?.challenge_id"
                    @click="
                        authenticate('resend', {
                            challenge_id: status?.challenge_id,
                        })
                    "
                >
                    {{ t("Send a new confirmation link") }}
                </button>
            </div>
        </div>
        <form
            v-else
            class="crm-form"
            @submit.prevent="
                authenticate('factor', {
                    challenge_id: status?.challenge_id,
                    ...(useRecovery
                        ? { recovery_code: recovery }
                        : status?.dev_question_mode
                          ? { answer }
                          : { code }),
                })
            "
        >
            <label class="crm-checkbox"
                ><input
                    v-model="useRecovery"
                    type="checkbox"
                    :disabled="busy"
                />{{ t("Use a recovery code") }}</label
            >
            <label v-if="useRecovery" class="crm-field"
                ><span class="crm-field-label">{{ t("Recovery code") }}</span
                ><input
                    v-model="recovery"
                    data-vault-sensitive
                    required
                    autocomplete="off"
                    dir="ltr"
                    :disabled="busy"
            /></label>
            <label v-else-if="status?.dev_question_mode" class="crm-field"
                ><span class="crm-field-label">{{ status.question }}</span
                ><input
                    v-model="answer"
                    data-vault-sensitive
                    type="password"
                    required
                    autocomplete="off"
                    :disabled="busy"
            /></label>
            <label v-else class="crm-field"
                ><span class="crm-field-label">{{
                    t("Authenticator code")
                }}</span
                ><input
                    v-model="code"
                    data-vault-sensitive
                    required
                    inputmode="numeric"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    autocomplete="one-time-code"
                    dir="ltr"
                    :disabled="busy"
            /></label>
            <div class="crm-form-action">
                <button class="crm-button" :disabled="busy">
                    {{ busy ? t("Working…") : t("Unlock for 300 seconds") }}
                </button>
            </div>
        </form>
    </section>
</template>
