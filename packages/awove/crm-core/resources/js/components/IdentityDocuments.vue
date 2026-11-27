<script setup lang="ts">
import { localeTag, t } from "@/lib/i18n";
import { useForm, usePage } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, ref, useId, watch } from "vue";
import { date, type IdentityDocument } from "../types";

const props = defineProps<{
    type: "contacts" | "companies";
    identityId: number;
    editable: boolean;
    timezone: string;
}>();
const page = usePage();
const fieldId = `identity-document-${useId()}`;
const endpoint = computed(
    () => `/people/${props.type}/${props.identityId}/documents`,
);
const uploadEndpoint = computed(() => {
    const fromLead = new URLSearchParams(page.url.split("?")[1]).get(
        "from_lead",
    );
    return fromLead && /^[1-9]\d*$/.test(fromLead)
        ? `${endpoint.value}?from_lead=${fromLead}`
        : endpoint.value;
});
const documents = ref<IdentityDocument[]>([]);
const canUpload = ref(false);
const loading = ref(true);
const loadError = ref("");
const warning = ref("");
const uploaded = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);
const form = useForm<{ file: File | null }>({ file: null });
let loadController: AbortController | undefined;

function reviewLabel(flag: string): string {
    const labels: Record<string, string> = {
        "source-party-review": "Separate source identities require review",
        approved_separate_party_requires_review:
            "Separate source identities require review",
        noncanonical_phone_original_retained:
            "Original phone retained for review",
        phone_over_native_limit_native_null:
            "Original phone exceeds the native field limit",
        source_unselected_unknown_original_retained:
            "Original source not selected",
        unrecognized_privacy_consent_native_null:
            "Original privacy consent needs review",
        multiple_contact_methods_without_primary:
            "Multiple source contact methods",
        invalid_link_native_null_original_retained:
            "Original document link needs review",
        "unassigned-registration": "Registration has no associated company",
        "unsupported-phone": "Original phone retained for review",
    };
    return t(labels[flag] ?? "Recorded source review note");
}

function validSource(source: IdentityDocument["source"]): boolean {
    if (source == null) return true;
    if (
        typeof source !== "object" ||
        ![
            "file-column",
            "item-assets-all",
            "update",
            "reply",
            "generated-reference",
        ].includes(source.kind) ||
        !source.author ||
        (source.author.name !== null &&
            typeof source.author.name !== "string") ||
        (source.source_created_at !== null &&
            typeof source.source_created_at !== "string") ||
        (source.source_updated_at !== null &&
            typeof source.source_updated_at !== "string")
    )
        return false;
    const context = source.context;
    return (
        context === null ||
        (typeof context === "object" &&
            ["update", "reply"].includes(context.kind) &&
            Number.isSafeInteger(context.lead_id) &&
            context.lead_id > 0 &&
            Number.isSafeInteger(context.activity_id) &&
            context.activity_id > 0 &&
            context.url === `/leads/${context.lead_id}`)
    );
}

async function loadDocuments() {
    loadController?.abort();
    const controller = new AbortController();
    loadController = controller;
    const currentEndpoint = endpoint.value;
    loading.value = true;
    loadError.value = "";
    try {
        const response = await fetch(currentEndpoint, {
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
            cache: "no-store",
            signal: controller.signal,
        });
        if (!response.ok) throw new Error("Documents unavailable");
        const result = (await response.json()) as {
            documents: IdentityDocument[];
            can_upload: boolean;
        };
        if (
            !Array.isArray(result.documents) ||
            !result.documents.every(
                (document) =>
                    document &&
                    typeof document === "object" &&
                    validSource(document.source),
            ) ||
            typeof result.can_upload !== "boolean"
        ) {
            throw new Error("Invalid document response");
        }
        if (controller.signal.aborted || endpoint.value !== currentEndpoint)
            return;
        documents.value = result.documents;
        canUpload.value = result.can_upload;
    } catch {
        if (!controller.signal.aborted && endpoint.value === currentEndpoint) {
            documents.value = [];
            canUpload.value = false;
            loadError.value = t(
                "Documents could not be loaded. Please try again.",
            );
        }
    } finally {
        if (loadController === controller) loading.value = false;
    }
}

watch(
    endpoint,
    () => {
        form.cancel();
        form.reset();
        form.clearErrors();
        if (fileInput.value) fileInput.value.value = "";
        documents.value = [];
        canUpload.value = false;
        warning.value = "";
        uploaded.value = false;
        void loadDocuments();
    },
    { immediate: true },
);
onBeforeUnmount(() => {
    loadController?.abort();
    form.cancel();
});

function chooseFile(event: Event) {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.clearErrors("file");
    uploaded.value = false;
    if (
        form.file &&
        (form.file.size === 0 || form.file.size > 100 * 1024 * 1024)
    ) {
        form.setError(
            "file",
            t("Choose a non-empty file no larger than 100 MB."),
        );
    }
}

function upload() {
    if (
        !props.editable ||
        !canUpload.value ||
        !form.file ||
        form.errors.file ||
        form.processing ||
        warning.value
    )
        return;
    const currentEndpoint = endpoint.value;
    form.post(uploadEndpoint.value, {
        forceFormData: true,
        preserveScroll: true,
        errorBag: "identity_documents",
        onSuccess: () => {
            if (endpoint.value !== currentEndpoint) return;
            form.reset();
            if (fileInput.value) fileInput.value.value = "";
            uploaded.value = true;
            void loadDocuments();
        },
        onNetworkError: () => {
            if (endpoint.value === currentEndpoint) {
                warning.value = t(
                    "The connection was interrupted. Reload this profile to check whether the document was uploaded before trying again.",
                );
            }
            return false;
        },
    });
}

function fileSize(bytes: number) {
    const unit = bytes < 1024 ? "B" : bytes < 1024 * 1024 ? "KB" : "MB";
    const divisor = unit === "B" ? 1 : unit === "KB" ? 1024 : 1024 * 1024;
    return `${new Intl.NumberFormat(localeTag(), { maximumFractionDigits: unit === "B" ? 0 : 1 }).format(bytes / divisor)} ${unit}`;
}
</script>

<template>
    <section
        class="crm-card crm-flow"
        :aria-labelledby="`${fieldId}-title`"
        :aria-busy="loading || form.processing"
    >
        <h2 :id="`${fieldId}-title`">{{ t("Documents") }}</h2>
        <p v-if="loading" role="status" class="crm-muted">
            {{ t("Loading documents…") }}
        </p>
        <div v-if="loadError" class="crm-flow">
            <p role="alert" class="crm-error">{{ loadError }}</p>
            <div class="crm-form-action">
                <button
                    type="button"
                    class="crm-secondary"
                    @click="loadDocuments"
                >
                    {{ t("Try again") }}
                </button>
            </div>
        </div>
        <form
            v-if="editable && canUpload"
            class="crm-form"
            @submit.prevent="upload"
        >
            <label class="crm-field">
                <span :id="`${fieldId}-label`" class="crm-field-label">{{
                    t("Document file")
                }}</span>
                <input
                    :id="fieldId"
                    ref="fileInput"
                    class="crm-input"
                    type="file"
                    accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.jpg,.jpeg,.png,.gif,.webp,.zip,.mp4"
                    required
                    :disabled="form.processing || !!warning"
                    :aria-invalid="!!form.errors.file"
                    :aria-labelledby="`${fieldId}-label`"
                    :aria-describedby="
                        form.errors.file
                            ? `${fieldId}-help ${fieldId}-error`
                            : `${fieldId}-help`
                    "
                    @change="chooseFile"
                />
                <small :id="`${fieldId}-help`" class="crm-muted">{{
                    t(
                        "PDF, Word, Excel, CSV, text, JPG, PNG, GIF, WebP, ZIP or MP4, up to 100 MB. Files are private to people with access to this profile.",
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
                    :disabled="
                        form.processing ||
                        !form.file ||
                        !!form.errors.file ||
                        !!warning
                    "
                >
                    {{
                        form.processing ? t("Uploading…") : t("Upload document")
                    }}
                </button>
            </div>
        </form>
        <p v-if="uploaded" role="status" class="crm-notice">
            {{ t("Document uploaded.") }}
        </p>
        <p v-if="!loading && !loadError && !documents.length" class="crm-muted">
            {{ t("No documents yet.") }}
        </p>
        <article
            v-for="document in documents"
            :key="document.id"
            class="crm-note crm-flow"
        >
            <div class="crm-field">
                <strong
                    ><bdi>{{ document.original_name }}</bdi></strong
                >
                <small class="crm-muted"
                    ><bdi>{{ fileSize(document.size_bytes) }}</bdi> ·
                    {{ date(document.created_at, timezone) }} ·
                    <bdi>{{
                        document.uploaded_by?.name ?? t("Former user")
                    }}</bdi></small
                >
                <small
                    v-if="document.source?.kind === 'generated-reference'"
                    class="crm-muted"
                >
                    {{ t("Generated source reference") }} ·
                    <bdi>{{ document.source.item_id }}</bdi>
                    <span
                        v-for="flag in document.source.review_flags ?? []"
                        :key="flag"
                    >
                        · {{ reviewLabel(flag) }}</span
                    >
                </small>
                <small v-if="document.source" class="crm-muted">
                    <template v-if="document.source.author.name">
                        {{ t("Original author") }}:
                        <bdi>{{ document.source.author.name }}</bdi>
                    </template>
                    <template v-else>{{
                        t("Original author unavailable")
                    }}</template>
                    ·
                    <bdi v-if="document.source.source_created_at">
                        <time
                            :datetime="document.source.source_created_at"
                            :title="document.source.source_created_at"
                            >{{
                                date(
                                    document.source.source_created_at,
                                    timezone,
                                )
                            }}</time
                        >
                    </bdi>
                    <template v-else>{{
                        t("Original time unavailable")
                    }}</template>
                </small>
                <small
                    v-if="document.source?.source_updated_at"
                    class="crm-muted"
                >
                    {{ t("Source updated") }} ·
                    <bdi>{{
                        date(document.source.source_updated_at, timezone)
                    }}</bdi>
                </small>
                <small v-if="document.source?.context">
                    <a
                        :href="`/leads/${document.source.context.lead_id}#activity-${document.source.context.activity_id}`"
                        >{{
                            t(
                                document.source.context.kind === "reply"
                                    ? "Legacy reply"
                                    : "Legacy update",
                            )
                        }}</a
                    >
                </small>
            </div>
            <div class="crm-form-action">
                <a
                    class="crm-secondary"
                    :href="document.download_url"
                    :aria-label="
                        t('Download {filename}', {
                            filename: document.original_name,
                        })
                    "
                    >{{ t("Download") }}</a
                >
            </div>
        </article>
    </section>
</template>
