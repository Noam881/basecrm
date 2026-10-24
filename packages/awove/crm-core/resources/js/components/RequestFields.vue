<script setup lang="ts">
import WorkBaseFields from "./WorkBaseFields.vue";
// Modules retain their own lifecycle and persistence around the shared controls.
export interface RequestDraft {
    subject: string;
    description: string;
    owner_id: number | null;
    priority: string;
    next_action: string;
    due_on: string;
}
const model = defineModel<RequestDraft>({ required: true });
defineProps<{
    owners: { id: number; name: string }[];
    canAssign: boolean;
    errors: Record<string, string | undefined>;
}>();
</script>
<template>
    <WorkBaseFields
        v-model:title="model.subject"
        v-model:description="model.description"
        v-model:owner-id="model.owner_id"
        v-model:priority="model.priority"
        v-model:due-on="model.due_on"
        :owners="owners"
        :errors="errors"
        :can-assign="canAssign"
        title-label="Subject"
        title-error-key="subject"
        :description-required="true"
        :due-required="false"
    />
    <label class="crm-field crm-form-span">
        <span class="crm-field-label">Next action</span>
        <textarea
            v-model="model.next_action"
            rows="2"
            maxlength="10000"
            :aria-invalid="!!errors.next_action"
        />
        <small v-if="errors.next_action" data-slot="input-error" role="alert">{{
            errors.next_action
        }}</small>
    </label>
</template>
