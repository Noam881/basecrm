<script setup lang="ts">
// Shared request fields; business modules own statuses, dates, access and persistence.
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
    <label class="crm-field crm-form-span"
        ><span class="crm-field-label">Subject</span
        ><input
            v-model="model.subject"
            required
            maxlength="180"
            :aria-invalid="!!errors.subject"
        /><small v-if="errors.subject" data-slot="input-error" role="alert">{{
            errors.subject
        }}</small></label
    >
    <label class="crm-field crm-form-span"
        ><span class="crm-field-label">Description</span
        ><textarea
            v-model="model.description"
            required
            rows="4"
            maxlength="20000"
            :aria-invalid="!!errors.description"
        /><small
            v-if="errors.description"
            data-slot="input-error"
            role="alert"
            >{{ errors.description }}</small
        ></label
    >
    <label class="crm-field"
        ><span class="crm-field-label">Owner</span
        ><select v-model="model.owner_id" :disabled="!canAssign">
            <option :value="null">Unassigned</option>
            <option v-for="owner in owners" :key="owner.id" :value="owner.id">
                {{ owner.name }}
            </option></select
        ><small v-if="errors.owner_id" data-slot="input-error" role="alert">{{
            errors.owner_id
        }}</small></label
    >
    <label class="crm-field"
        ><span class="crm-field-label">Priority</span
        ><select v-model="model.priority">
            <option value="low">Low</option>
            <option value="normal">Normal</option>
            <option value="high">High</option>
        </select></label
    >
    <label class="crm-field crm-form-span"
        ><span class="crm-field-label">Next action</span
        ><textarea v-model="model.next_action" rows="2" maxlength="10000" />
    </label>
    <label class="crm-field"
        ><span class="crm-field-label">Due date</span
        ><input
            v-model="model.due_on"
            type="date"
            :aria-invalid="!!errors.due_on"
        /><small v-if="errors.due_on" data-slot="input-error" role="alert">{{
            errors.due_on
        }}</small></label
    >
</template>
