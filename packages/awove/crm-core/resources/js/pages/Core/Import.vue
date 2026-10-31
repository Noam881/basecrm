<script setup lang="ts">
import { t } from "@/lib/i18n";
import { Head, Link } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";

type RecordType = "contacts" | "companies" | "leads";
type Notice = { line: number; message: string };
type Preview = {
    type: RecordType;
    count: number;
    rows: { line: number; data: Record<string, unknown> }[];
    errors: { line: number; messages: string[] }[];
    warnings: Notice[];
    token: string | null;
    expires_in_minutes: number;
};
const props = defineProps<{
    definitions: Partial<
        Record<
            RecordType,
            {
                columns: string[];
                options: Record<string, Record<string, string>>;
            }
        >
    >;
    canImport: boolean;
    canExport: boolean;
}>();
const type = ref<RecordType>("contacts");
const file = ref<File | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const preview = ref<Preview | null>(null);
const acceptDuplicates = ref(false);
const processing = ref(false);
const errors = ref<string[]>([]);
const saved = ref("");
const columns = computed(() => props.definitions[type.value]?.columns ?? []);
const options = computed(() => props.definitions[type.value]?.options ?? {});
const singularNames: Record<RecordType, string> = {
    contacts: "contact",
    companies: "company",
    leads: "lead / opportunity",
};
const names: Record<RecordType, string> = {
    contacts: t("Contacts"),
    companies: t("Companies"),
    leads: t("Leads / opportunities"),
};
watch(type, clearPreview);
function clearPreview() {
    preview.value = null;
    acceptDuplicates.value = false;
    errors.value = [];
    saved.value = "";
}
function chooseFile(event: Event) {
    clearPreview();
    file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}
async function post(url: string, body: FormData | string) {
    const token = decodeURIComponent(
        document.cookie
            .split("; ")
            .find((cookie) => cookie.startsWith("XSRF-TOKEN="))
            ?.split("=")[1] ?? "",
    );
    const response = await fetch(url, {
        method: "POST",
        headers: {
            Accept: "application/json",
            "X-XSRF-TOKEN": token,
            ...(typeof body === "string"
                ? { "Content-Type": "application/json" }
                : {}),
        },
        body,
        credentials: "same-origin",
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const messages = data.errors
            ? Object.values(data.errors).flat().map(String)
            : [
                  response.status === 403
                      ? t("You no longer have permission for this action.")
                      : response.status === 419
                        ? t(
                              "Your session expired. Reload this page and try again.",
                          )
                        : (data.message ??
                          t("The request could not be completed.")),
              ];
        errors.value = messages;
        if ([403, 404, 409].includes(response.status)) preview.value = null;
        return null;
    }
    return data;
}
async function previewFile() {
    if (!file.value || processing.value) return;
    clearPreview();
    processing.value = true;
    try {
        const body = new FormData();
        body.append("type", type.value);
        body.append("file", file.value);
        preview.value = await post("/spreadsheets/preview", body);
    } catch {
        errors.value = [
            t("Could not preview the file. Check your connection."),
        ];
    } finally {
        processing.value = false;
    }
}
async function confirmImport() {
    if (!preview.value?.token || processing.value) return;
    processing.value = true;
    errors.value = [];
    try {
        const result = await post(
            "/spreadsheets/confirm",
            JSON.stringify({
                token: preview.value.token,
                accept_duplicates: acceptDuplicates.value,
            }),
        );
        if (result) {
            saved.value = t("Imported {count} records.", {
                count: result.count,
            });
            preview.value = null;
            file.value = null;
            if (fileInput.value) fileInput.value.value = "";
        }
    } catch {
        errors.value = [
            t(
                "The connection was interrupted. Reload before importing again to check whether the records were saved.",
            ),
        ];
    } finally {
        processing.value = false;
    }
}
function display(value: unknown): string {
    return Array.isArray(value) ? value.join(" | ") : String(value ?? "—");
}
</script>

<template>
    <Head :title="t('Import and export')" />
    <Link href="/dashboard" class="crm-back">{{
        t("← Back to overview")
    }}</Link>
    <div class="crm-heading">
        <h1>{{ t("Import and export") }}</h1>
    </div>
    <div class="crm-stack">
        <section class="crm-card crm-flow">
            <label class="crm-field">
                <span class="crm-field-label">{{ t("Record type") }}</span>
                <select v-model="type" :disabled="processing">
                    <option
                        v-for="(_, key) in definitions"
                        :key="key"
                        :value="key"
                    >
                        {{ names[key as RecordType] }}
                    </option>
                </select>
            </label>
            <p class="crm-muted">
                {{
                    t(
                        "CSV files only. Exports include records you can access and omit archived leads. Import creates separate records and never updates or merges existing ones.",
                    )
                }}
            </p>
            <div v-if="canExport" class="crm-form-action">
                <a
                    class="crm-secondary"
                    :href="`/spreadsheets/export/${type}`"
                    >{{ t("Download {type} CSV", { type: names[type] }) }}</a
                >
            </div>
        </section>
        <section v-if="canImport" class="crm-card crm-flow">
            <h2>{{ t("Import {type}", { type: names[type] }) }}</h2>
            <p class="crm-muted">
                {{
                    t(
                        "Use UTF-8, comma-separated CSV with a header row, up to 1 MB and 500 records. Preview validates every record before anything is saved. A preview expires after 15 minutes.",
                    )
                }}
            </p>
            <p>
                {{
                    type === "leads"
                        ? t(
                              "Required: status_id and an accessible contact_id or company_id. Owner defaults to you. Use stable status identifiers listed below. Dates use YYYY-MM-DD; money uses decimal values without currency symbols.",
                          )
                        : t(
                              "Required: name. Related company_id values must refer to companies you can access.",
                          )
                }}
            </p>
            <p v-if="type === 'contacts'" class="crm-muted">
                {{
                    t(
                        "Use | between preferred contact days or times, for example sunday|monday or mornings|afternoons. Use the same internal option values as the contact form; Anytime cannot be combined with other times. Import companies first when linking existing company IDs.",
                    )
                }}
            </p>
            <details>
                <summary>{{ t("Supported columns") }}</summary>
                <p>{{ columns.join(", ") }}</p>
                <p
                    v-for="(choices, field) in options"
                    :key="field"
                    class="crm-muted"
                >
                    {{ field }}:
                    {{
                        Object.entries(choices)
                            .map(([key, label]) => `${key} (${label})`)
                            .join(", ")
                    }}
                </p>
                <p class="crm-muted">
                    {{
                        t(
                            "Export-only fields such as id and created_at must be removed before import. The template contains only supported columns.",
                        )
                    }}
                </p>
            </details>
            <div class="crm-form-action">
                <a
                    class="crm-secondary"
                    :href="`/spreadsheets/template/${type}`"
                    >{{ t("Download import template") }}</a
                >
            </div>
            <form class="crm-form" @submit.prevent="previewFile">
                <label class="crm-field">
                    <span class="crm-field-label">{{ t("CSV file") }}</span>
                    <input
                        ref="fileInput"
                        type="file"
                        accept=".csv,text/csv"
                        required
                        :disabled="processing"
                        @change="chooseFile"
                    />
                </label>
                <div class="crm-form-action">
                    <button class="crm-button" :disabled="processing || !file">
                        {{
                            processing ? t("Processing…") : t("Preview import")
                        }}
                    </button>
                </div>
            </form>
        </section>
        <div v-if="errors.length" class="crm-error" role="alert">
            <p v-for="error in errors" :key="error">{{ error }}</p>
        </div>
        <p v-if="saved" class="crm-notice" role="status">{{ saved }}</p>
        <section v-if="preview" class="crm-card crm-flow">
            <h2>
                {{ t("Preview:") }} {{ preview.count }}
                {{ preview.count === 1 ? t("record") : t("records") }}
            </h2>
            <p v-if="preview.errors.length" class="crm-error" role="alert">
                {{
                    t(
                        "Fix the errors below, save the CSV, and preview it again. No records have been saved.",
                    )
                }}
            </p>
            <ul v-if="preview.errors.length">
                <li v-for="error in preview.errors" :key="error.line">
                    {{ t("Record") }} {{ error.line }}:
                    {{ error.messages.join(" ") }}
                </li>
            </ul>
            <div
                v-if="preview.warnings.length"
                class="crm-notice"
                role="status"
            >
                <p>{{ t("Review possible duplicates before importing.") }}</p>
                <ul>
                    <li v-for="warning in preview.warnings" :key="warning.line">
                        {{ t("Record") }} {{ warning.line }}:
                        {{ warning.message }}
                    </li>
                </ul>
            </div>
            <p class="crm-muted">
                {{
                    t(
                        "Showing the first {shown} valid records. All {count} records were checked.",
                        {
                            shown: Math.min(20, preview.rows.length),
                            count: preview.count,
                        },
                    )
                }}
            </p>
            <div v-if="preview.rows.length" class="md-table">
                <div
                    class="md-data-scroll"
                    tabindex="0"
                    role="region"
                    :aria-label="t('CSV import preview')"
                >
                    <table :style="{ width: `${64 + columns.length * 200}px` }">
                        <colgroup>
                            <col style="width: 64px" />
                            <col
                                v-for="column in columns"
                                :key="column"
                                style="width: 200px"
                            />
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">{{ t("Row") }}</th>
                                <th
                                    v-for="column in columns"
                                    :key="column"
                                    scope="col"
                                >
                                    {{
                                        column
                                            .replaceAll("_", " ")
                                            .replace(/^./, (letter) =>
                                                letter.toLocaleUpperCase(),
                                            )
                                    }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in preview.rows.slice(0, 20)"
                                :key="row.line"
                            >
                                <td>{{ row.line }}</td>
                                <td v-for="column in columns" :key="column">
                                    <span :title="display(row.data[column])">{{
                                        display(row.data[column])
                                    }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <form
                v-if="preview.token && !preview.errors.length"
                class="crm-form"
                @submit.prevent="confirmImport"
            >
                <label v-if="preview.warnings.length" class="crm-checkbox">
                    <input
                        v-model="acceptDuplicates"
                        type="checkbox"
                        :disabled="processing"
                    />
                    {{
                        t(
                            "Keep separate records for the possible duplicates listed above",
                        )
                    }}
                </label>
                <div class="crm-form-action">
                    <button
                        class="crm-button"
                        :disabled="
                            processing ||
                            (preview.warnings.length > 0 && !acceptDuplicates)
                        "
                    >
                        {{
                            processing
                                ? t("Importing…")
                                : t("Import {count} records", {
                                      count: preview.count,
                                  })
                        }}
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>
