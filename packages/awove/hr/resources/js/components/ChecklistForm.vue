<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { useForm, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import WorkFields, {
    type WorkDraft,
} from "../../../../crm-core/resources/js/components/WorkFields.vue";
import type { Options, ChecklistItem } from "../types";
const props = withDefaults(
    defineProps<{
        options: Options;
        record?: ChecklistItem;
        caseId?: number;
        canEditMetadata?: boolean;
    }>(),
    { canEditMetadata: true },
);
const form = useForm({
    title: props.record?.title ?? "",
    description: props.record?.description ?? "",
    owner_id: props.record
        ? props.record.owner_id
        : (usePage().props.auth as { user: { id: number } }).user.id,
    priority: props.record?.priority ?? "normal",
    due_on: props.record?.due_on ?? props.options.today,
    status: props.record?.status ?? "not-started",
    completed_on: props.record?.completed_on ?? "",
    category: props.record?.category ?? "documents",
    required: props.record?.required ?? true,
    version: props.record?.version ?? 1,
});
const draft = computed<WorkDraft>({
    get: () => form,
    set: (value) => Object.assign(form, value),
});
const warning = ref("");
function save() {
    form.transform(({ version, ...data }) =>
        props.record ? { ...data, version } : data,
    );
    const opts = {
        preserveScroll: true,
        onHttpException: (response: { status: number }) => {
            if ([403, 409].includes(response.status)) {
                warning.value = tr(
                    "This checklist item or case changed. Your edits are kept. Reload before saving.",
                );
                return false;
            }
        },
    };
    if (props.record) form.patch(`/hr/checklist/${props.record.id}`, opts);
    else form.post(`/hr/cases/${props.caseId}/checklist`, opts);
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <WorkFields
            v-model="draft"
            :owners="options.owners"
            :statuses="options.checklistStatuses"
            :completed-statuses="['done']"
            :errors="form.errors"
            :can-assign="options.canAssign"
            :title-label="tr('Checklist item')"
        />
        <label class="crm-field"
            ><span class="crm-field-label">{{ tr("Category") }}</span
            ><select v-model="form.category" :disabled="!canEditMetadata">
                <option
                    v-for="(label, id) in options.categories"
                    :key="id"
                    :value="id"
                >
                    {{ label }}
                </option>
            </select></label
        >
        <label class="crm-checkbox"
            ><input
                v-model="form.required"
                type="checkbox"
                :disabled="!canEditMetadata"
            />{{ tr("Required before case completion") }}</label
        >
        <p class="crm-muted">
            {{
                tr(
                    "Done records manual completion. Optional work may be explicitly skipped. Recording access work does not create or revoke an external account.",
                )
            }}
        </p>
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
                          ? tr("Save checklist item")
                          : tr("Add checklist item")
                }}
            </button>
        </div>
    </form>
</template>
