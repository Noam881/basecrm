<script setup lang="ts">
import { t } from "@/lib/i18n";
import { useForm } from "@inertiajs/vue3";
import { ref, useId } from "vue";
import { date, type LeadSignedContract } from "../types";

const props = defineProps<{
    leadId: number;
    editable: boolean;
    contracts: LeadSignedContract[];
    timezone: string;
}>();
const fieldId = `signed-contract-file-${useId()}`;
const form = useForm<{ file: File | null }>({ file: null });
const fileInput = ref<HTMLInputElement | null>(null);
const warning = ref("");
function chooseFile(event: Event) {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.clearErrors("file");
    warning.value = "";
}
function upload() {
    if (!form.file || form.processing) return;
    warning.value = "";
    form.post(`/leads/${props.leadId}/signed-contracts`, {
        forceFormData: true,
        preserveScroll: true,
        errorBag: "signed_contracts",
        onSuccess: () => {
            form.reset();
            if (fileInput.value) fileInput.value.value = "";
        },
        onNetworkError: () => {
            warning.value = t(
                "The connection was interrupted. Reload the lead to check whether the signed contract was uploaded before trying again.",
            );
            return false;
        },
    });
}
</script>
<template>
    <section
        id="signed-contracts"
        class="crm-card crm-flow"
        aria-labelledby="signed-contracts-title"
    >
        <h2 id="signed-contracts-title">{{ t("Signed contracts") }}</h2>
        <p class="crm-muted">
            {{
                t(
                    "Upload a new signed contract version to keep the previous files in the lead history.",
                )
            }}
        </p>
        <form v-if="editable" class="crm-form" @submit.prevent="upload">
            <label class="crm-field" :for="fieldId">
                <span :id="`${fieldId}-label`" class="crm-field-label">{{
                    t("Signed contract file")
                }}</span>
                <input
                    :id="fieldId"
                    ref="fileInput"
                    class="crm-input"
                    type="file"
                    accept=".pdf,.doc,.docx"
                    required
                    :disabled="form.processing"
                    :aria-labelledby="`${fieldId}-label`"
                    :aria-invalid="!!form.errors.file"
                    :aria-describedby="
                        form.errors.file
                            ? `${fieldId}-help ${fieldId}-error`
                            : `${fieldId}-help`
                    "
                    @change="chooseFile"
                />
                <small :id="`${fieldId}-help`" class="crm-muted">{{
                    t(
                        "PDF, DOC or DOCX, up to 10 MB. Files are private to people with access to this lead.",
                    )
                }}</small>
                <small
                    v-if="form.errors.file"
                    :id="`${fieldId}-error`"
                    role="alert"
                    class="crm-error"
                    >{{ form.errors.file }}</small
                >
            </label>
            <p v-if="warning" role="alert" class="crm-notice-panel">
                {{ warning }}
            </p>
            <div
                v-if="form.progress"
                role="status"
                aria-live="polite"
                class="crm-muted"
            >
                {{ t("Uploading") }} {{ form.progress.percentage ?? 0 }}%
            </div>
            <div class="crm-form-action">
                <button
                    class="crm-button"
                    :disabled="form.processing || !form.file || !!warning"
                >
                    {{
                        form.processing
                            ? t("Uploading…")
                            : t("Upload signed contract")
                    }}
                </button>
            </div>
        </form>
        <p v-if="!contracts.length" class="crm-muted">
            {{ t("No signed contracts yet.") }}
        </p>
        <article
            v-for="contract in contracts"
            :key="contract.id"
            class="crm-note crm-flow"
        >
            <div class="crm-field">
                <strong dir="auto">{{ contract.original_name }}</strong>
                <small class="crm-muted"
                    >{{ t("Version") }} {{ contract.version }} ·
                    {{ date(contract.created_at, timezone) }} ·
                    {{ contract.created_by?.name ?? t("Former user") }}</small
                >
            </div>
            <div class="crm-form-action">
                <a class="crm-secondary" :href="contract.download_url"
                    >{{ t("Download version") }} {{ contract.version }}</a
                >
            </div>
        </article>
    </section>
</template>
