<script setup lang="ts">
export interface RoutineCompletionDraft {
    completed_on: string;
    next_due_on: string;
    checked_items: string[];
}
const model = defineModel<RoutineCompletionDraft>({ required: true });
withDefaults(
    defineProps<{
        checklist: string[];
        errors: Record<string, string | undefined>;
        showChecklist?: boolean;
    }>(),
    { showChecklist: true },
);
</script>
<template>
    <label class="crm-field">
        <span class="crm-field-label">Completed on</span
        ><input
            v-model="model.completed_on"
            type="date"
            required
            :aria-invalid="!!errors.completed_on"
        />
        <small
            v-if="errors.completed_on"
            data-slot="input-error"
            role="alert"
            >{{ errors.completed_on }}</small
        >
    </label>
    <label class="crm-field">
        <span class="crm-field-label">Next due date (optional)</span
        ><input
            v-model="model.next_due_on"
            type="date"
            :aria-invalid="!!errors.next_due_on"
        />
        <small class="crm-muted"
            >Choose a date manually to schedule the next occurrence. Leave blank
            to keep it unscheduled.</small
        >
        <small v-if="errors.next_due_on" data-slot="input-error" role="alert">{{
            errors.next_due_on
        }}</small>
    </label>
    <div
        v-if="showChecklist && checklist.length"
        class="crm-flow crm-form-span"
    >
        <label v-for="item in checklist" :key="item" class="crm-checkbox"
            ><input
                v-model="model.checked_items"
                type="checkbox"
                :value="item"
            />{{ item }}</label
        >
        <small
            v-if="errors.checked_items"
            data-slot="input-error"
            role="alert"
            >{{ errors.checked_items }}</small
        >
    </div>
</template>
