<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { useForm } from "@inertiajs/vue3";
import { ref, useId } from "vue";
import type { Section, Options, KnowledgeRecord } from "../types";
const props = defineProps<{
    section: Section;
    options: Options;
    record?: KnowledgeRecord;
}>();
const r = props.record;
const form = useForm({
    version: r?.version ?? 1,
    owner_id: r ? r.owner_id : props.options.currentOwnerId,
    kind: r?.kind ?? "procedure",
    title: r?.title ?? "",
    category: r?.category ?? "",
    relevant_role: r?.relevant_role ?? "",
    purpose: r?.purpose ?? "",
    use_classification: r?.use_classification ?? "",
    revision: r?.revision ?? "",
    revised_on: r?.revised_on ?? "",
    updated_on: r?.updated_on ?? "",
    status: r?.status ?? (props.section === "library" ? "draft" : "pending"),
    name: r?.name ?? "",
    provider: r?.provider ?? "",
    expires_on: r?.expires_on ?? "",
    remind_on: r?.remind_on ?? "",
    notes: r?.notes ?? "",
    login_url: r?.login_url ?? "",
    password_manager_reference: r?.password_manager_reference ?? "",
    document_name: "",
    document_url: "",
});
const warning = ref("");
const loginHelp = useId();
const referenceHelp = useId();
function save() {
    form.transform((data) => {
        const payload: Record<string, unknown> = {
            version: data.version,
            owner_id: data.owner_id,
        };
        const fields =
            props.section === "library"
                ? [
                      "kind",
                      "title",
                      "category",
                      "relevant_role",
                      "purpose",
                      "use_classification",
                      "revision",
                      "revised_on",
                      "updated_on",
                      "status",
                  ]
                : props.section === "renewals"
                  ? [
                        "name",
                        "provider",
                        "expires_on",
                        "remind_on",
                        "status",
                        "notes",
                    ]
                  : [
                        "name",
                        ...(props.options.canEditSystemReferences
                            ? ["login_url", "password_manager_reference"]
                            : []),
                    ];
        for (const field of fields)
            payload[field] = data[field as keyof typeof data];
        if (
            !r &&
            props.section !== "systems" &&
            props.options.canManageDocuments &&
            data.document_url
        ) {
            payload.document_name = data.document_name;
            payload.document_url = data.document_url;
        }
        return payload;
    });
    const opts = {
        preserveScroll: true,
        onHttpException: (response: { status: number }) => {
            if ([403, 409].includes(response.status)) {
                warning.value = tr(
                    "This record or your access changed. Reload before saving.",
                );
                return false;
            }
        },
    };
    if (r) form.patch(`/knowledge/${props.section}/${r.id}`, opts);
    else form.post(`/knowledge/${props.section}`, opts);
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <template v-if="section === 'library'">
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Kind") }}</span
                ><select v-model="form.kind" :disabled="!!record">
                    <option
                        v-for="(label, id) in options.kinds"
                        :key="id"
                        :value="id"
                    >
                        {{ label }}
                    </option>
                </select></label
            >
            <label class="crm-field"
                ><span class="crm-field-label">{{
                    form.kind === "training" ? tr("Topic") : tr("Title")
                }}</span
                ><input v-model="form.title" required maxlength="180"
            /></label>
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Category") }}</span
                ><input v-model="form.category" maxlength="120"
            /></label>
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Relevant role") }}</span
                ><input
                    v-model="form.relevant_role"
                    :required="form.kind === 'training'"
                    maxlength="160"
            /></label>
            <label v-if="form.kind === 'material'" class="crm-field"
                ><span class="crm-field-label">{{
                    tr("Use classification")
                }}</span
                ><input
                    v-model="form.use_classification"
                    maxlength="120"
                    required
            /></label>
            <label v-if="form.kind === 'material'" class="crm-field"
                ><span class="crm-field-label">{{ tr("Purpose") }}</span
                ><textarea
                    v-model="form.purpose"
                    maxlength="10000"
                    required
                    rows="3"
                />
            </label>
            <template v-if="form.kind === 'procedure'"
                ><label class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Current version")
                    }}</span
                    ><input v-model="form.revision" maxlength="120" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Revision date")
                    }}</span
                    ><input v-model="form.revised_on" type="date" /></label
            ></template>
            <label class="crm-field"
                ><span class="crm-field-label">{{
                    tr("Updated document date")
                }}</span
                ><input v-model="form.updated_on" type="date"
            /></label>
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Status") }}</span
                ><select v-model="form.status">
                    <option
                        v-for="(label, id) in options.statuses"
                        v-show="id !== 'archived'"
                        :key="id"
                        :value="id"
                    >
                        {{ label }}
                    </option>
                </select></label
            >
        </template>
        <template v-else-if="section === 'renewals'">
            <label class="crm-field"
                ><span class="crm-field-label">{{
                    tr("License or insurance name")
                }}</span
                ><input v-model="form.name" required maxlength="180" /></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("Provider") }}</span
                ><input v-model="form.provider" required maxlength="180"
            /></label>
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Expiry date") }}</span
                ><input v-model="form.expires_on" type="date" required /></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("Reminder date") }}</span
                ><input
                    v-model="form.remind_on"
                    type="date"
                    :max="form.expires_on || undefined" /></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("Renewal status") }}</span
                ><select v-model="form.status">
                    <option
                        v-for="(label, id) in options.renewalStatuses"
                        :key="id"
                        :value="id"
                        :disabled="
                            id === 'renewed' && record?.status !== 'renewed'
                        "
                    >
                        {{ label }}
                    </option>
                </select></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("Notes") }}</span
                ><textarea v-model="form.notes" maxlength="10000" rows="3" />
            </label>
        </template>
        <template v-else
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("System name") }}</span
                ><input v-model="form.name" required maxlength="180" /></label
            ><template v-if="options.canEditSystemReferences"
                ><label class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Public HTTPS login page")
                    }}</span
                    ><input
                        v-model="form.login_url"
                        :aria-label="tr('Public HTTPS login page')"
                        :aria-describedby="loginHelp"
                        type="url"
                        maxlength="2048"
                    /><span :id="loginHelp" class="crm-help">{{
                        tr(
                            "Use the public login page without credentials, query strings or fragments.",
                        )
                    }}</span></label
                ><label class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Password-manager item reference")
                    }}</span
                    ><input
                        v-model="form.password_manager_reference"
                        :aria-label="tr('Password-manager item reference')"
                        :aria-describedby="referenceHelp"
                        maxlength="300"
                        autocomplete="off"
                    /><span :id="referenceHelp" class="crm-help">{{
                        tr(
                            "Item name or opaque item ID only. Never enter a password, secret key or recovery code.",
                        )
                    }}</span></label
                ></template
            ></template
        >
        <label class="crm-field"
            ><span class="crm-field-label">{{
                section === "systems" ? tr("Access owner") : tr("Owner")
            }}</span
            ><select v-model="form.owner_id" :disabled="!options.canAssign">
                <option v-if="options.canAssign" :value="null">
                    {{ tr("Unassigned") }}
                </option>
                <option
                    v-for="owner in options.owners"
                    :key="owner.id"
                    :value="owner.id"
                >
                    {{ owner.name }}
                </option>
            </select></label
        >
        <template
            v-if="
                !record && section !== 'systems' && options.canManageDocuments
            "
            ><label class="crm-field"
                ><span class="crm-field-label">{{
                    tr("Document name (optional)")
                }}</span
                ><input v-model="form.document_name" maxlength="180" /></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{
                    tr("HTTPS document link (optional)")
                }}</span
                ><input
                    v-model="form.document_url"
                    type="url"
                    maxlength="2048" /></label
        ></template>
        <div
            v-if="Object.keys(form.errors).length || warning"
            class="crm-form-action crm-error"
            role="alert"
        >
            <p v-for="(error, key) in form.errors" :key="key">{{ error }}</p>
            <p v-if="warning">{{ warning }}</p>
        </div>
        <div class="crm-form-action">
            <button class="crm-button" :disabled="form.processing || !!warning">
                {{
                    form.processing
                        ? tr("Saving…")
                        : record
                          ? tr("Save record")
                          : tr("Create record")
                }}
            </button>
        </div>
    </form>
</template>
