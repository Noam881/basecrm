<script setup lang="ts">
import { t } from "@/lib/i18n";
import { useForm } from "@inertiajs/vue3";
import { ref, useId } from "vue";
import { date } from "../types";

type Proposal = {
    id: number;
    version: number;
    original_name: string;
    mime_type: string;
    size_bytes: number;
    created_at: string;
    uploaded_by: { id: number; name: string } | null;
    download_url: string;
};
const props = defineProps<{
    leadId: number;
    editable: boolean;
    proposals: Proposal[];
    timezone: string;
}>();
const fieldId = `proposal-file-${useId()}`;
const labelId = `${fieldId}-label`;
const helpId = `${fieldId}-help`;
const errorId = `${fieldId}-error`;
const form = useForm<{ file: File | null }>({ file: null });
const fileInput = ref<HTMLInputElement | null>(null);
function chooseFile(event: Event) {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.clearErrors("file");
}
function upload() {
    form.post(`/leads/${props.leadId}/proposals`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            if (fileInput.value) fileInput.value.value = "";
        },
    });
}
function size(bytes: number) {
    return bytes < 1024 * 1024
        ? `${Math.max(1, Math.ceil(bytes / 1024))} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
</script>

<template>
    <section
        id="proposals"
        class="crm-card crm-flow"
        aria-labelledby="proposals-title"
    >
        <div class="crm-flow">
            <h2 id="proposals-title">{{ t("Proposals") }}</h2>
            <p class="crm-muted">
                {{
                    t(
                        "Upload a new version to keep the previous files in the lead history.",
                    )
                }}
            </p>
        </div>
        <form v-if="editable" class="crm-form" @submit.prevent="upload">
            <label class="crm-field" :for="fieldId">
                <span :id="labelId" class="crm-field-label">{{
                    t("Proposal file")
                }}</span>
                <input
                    :id="fieldId"
                    ref="fileInput"
                    class="crm-input"
                    type="file"
                    accept=".pdf,.doc,.docx"
                    required
                    :disabled="form.processing"
                    :aria-labelledby="labelId"
                    :aria-invalid="!!form.errors.file"
                    :aria-describedby="
                        form.errors.file ? `${helpId} ${errorId}` : helpId
                    "
                    @change="chooseFile"
                />
                <small :id="helpId" class="crm-muted">{{
                    t(
                        "PDF, DOC or DOCX, up to 10 MB. Files are private to people with access to this lead.",
                    )
                }}</small>
                <small
                    v-if="form.errors.file"
                    :id="errorId"
                    role="alert"
                    class="crm-error"
                    >{{ form.errors.file }}</small
                >
            </label>
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
                    :disabled="form.processing || !form.file"
                >
                    {{
                        form.processing ? t("Uploading…") : t("Upload proposal")
                    }}
                </button>
            </div>
        </form>
        <p v-if="!proposals.length" class="crm-muted">
            {{ t("No proposal files yet.") }}
        </p>
        <article
            v-for="proposal in proposals"
            :key="proposal.id"
            class="crm-note crm-flow"
        >
            <div class="crm-field">
                <strong>{{ proposal.original_name }}</strong>
                <small class="crm-muted"
                    >{{ t("Version") }} {{ proposal.version }} ·
                    {{ size(proposal.size_bytes) }} ·
                    {{ date(proposal.created_at, timezone) }} ·
                    {{ proposal.uploaded_by?.name ?? t("Former user") }}</small
                >
            </div>
            <div class="crm-form-action">
                <a class="crm-secondary" :href="proposal.download_url"
                    >{{ t("Download version") }} {{ proposal.version }}</a
                >
            </div>
        </article>
    </section>
</template>
