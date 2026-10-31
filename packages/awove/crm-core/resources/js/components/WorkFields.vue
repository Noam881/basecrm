<script setup lang="ts">
import { t } from "@/lib/i18n";
import WorkBaseFields from "./WorkBaseFields.vue";
export interface WorkDraft {
    title: string;
    description: string;
    owner_id: number | null;
    priority: string;
    due_on: string;
    status: string;
    completed_on: string;
}
const model = defineModel<WorkDraft>({ required: true });
withDefaults(
    defineProps<{
        owners: { id: number; name: string }[];
        statuses: Record<string, string>;
        completedStatuses: string[];
        errors: Record<string, string | undefined>;
        canAssign: boolean;
        titleLabel?: string;
        descriptionRequired?: boolean;
        dueRequired?: boolean;
    }>(),
    {
        titleLabel: () => t("Title"),
        descriptionRequired: false,
        dueRequired: true,
    },
);
</script>
<template>
    <WorkBaseFields
        v-model:title="model.title"
        v-model:description="model.description"
        v-model:owner-id="model.owner_id"
        v-model:priority="model.priority"
        v-model:due-on="model.due_on"
        :owners="owners"
        :errors="errors"
        :can-assign="canAssign"
        :title-label="titleLabel"
        :description-required="descriptionRequired"
        :due-required="dueRequired"
    />
    <label class="crm-field">
        <span class="crm-field-label">{{ t("Status") }}</span>
        <select v-model="model.status">
            <option v-for="(label, key) in statuses" :key="key" :value="key">
                {{ label }}
            </option>
        </select>
        <small v-if="errors.status" data-slot="input-error" role="alert">{{
            errors.status
        }}</small>
    </label>
    <label v-if="completedStatuses.includes(model.status)" class="crm-field">
        <span class="crm-field-label">{{ t("Completion date") }}</span>
        <input
            v-model="model.completed_on"
            type="date"
            :aria-invalid="!!errors.completed_on"
        />
        <small class="crm-muted">{{
            t("Leave blank to use today in the installation timezone.")
        }}</small>
        <small
            v-if="errors.completed_on"
            data-slot="input-error"
            role="alert"
            >{{ errors.completed_on }}</small
        >
    </label>
</template>
