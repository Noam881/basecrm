<script setup lang="ts">
import { t } from "@/lib/i18n";
defineProps<{ busy: boolean; canResend: boolean }>();
defineEmits<{ check: []; resend: [] }>();
</script>
<template>
    <div class="crm-flow">
        <p>
            {{
                t(
                    "Open the new Vault confirmation email in the same browser session. The link requires an explicit confirmation before the next step.",
                )
            }}
        </p>
        <div class="crm-form-action">
            <button
                class="crm-secondary"
                type="button"
                :disabled="busy"
                @click="$emit('check')"
            >
                {{ t("Check email confirmation") }}
            </button>
            <button
                class="crm-secondary"
                type="button"
                :disabled="busy || !canResend"
                @click="$emit('resend')"
            >
                {{ t("Send a new confirmation link") }}
            </button>
        </div>
    </div>
</template>
