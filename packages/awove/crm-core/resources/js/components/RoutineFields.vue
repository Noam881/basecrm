<script setup lang="ts">
export interface RoutineDraft {
    name: string;
    owner_id: number | null;
    frequency: string;
    next_due_on: string;
    checklist: string[];
}
const model = defineModel<RoutineDraft>({ required: true });
withDefaults(
    defineProps<{
        owners: { id: number; name: string }[];
        errors: Record<string, string | undefined>;
        canAssign: boolean;
        nextRequired?: boolean;
        showChecklist?: boolean;
    }>(),
    { nextRequired: false, showChecklist: true },
);
</script>
<template>
    <label class="crm-field crm-form-span">
        <span class="crm-field-label">Name</span
        ><input
            v-model="model.name"
            required
            maxlength="180"
            :aria-invalid="!!errors.name"
        />
        <small v-if="errors.name" data-slot="input-error" role="alert">{{
            errors.name
        }}</small>
    </label>
    <label class="crm-field">
        <span class="crm-field-label">Owner</span
        ><select v-model="model.owner_id" :disabled="!canAssign">
            <option :value="null">Unassigned</option>
            <option v-for="owner in owners" :key="owner.id" :value="owner.id">
                {{ owner.name }}
            </option>
        </select>
        <small v-if="errors.owner_id" data-slot="input-error" role="alert">{{
            errors.owner_id
        }}</small>
    </label>
    <label class="crm-field">
        <span class="crm-field-label">Frequency</span
        ><input
            v-model="model.frequency"
            required
            maxlength="120"
            placeholder="For example, Weekly"
            :aria-invalid="!!errors.frequency"
        />
        <small class="crm-muted"
            >A description for the owner; the next occurrence is scheduled
            manually.</small
        >
        <small v-if="errors.frequency" data-slot="input-error" role="alert">{{
            errors.frequency
        }}</small>
    </label>
    <label class="crm-field">
        <span class="crm-field-label">Next due date</span
        ><input
            v-model="model.next_due_on"
            type="date"
            :required="nextRequired"
            :aria-invalid="!!errors.next_due_on"
        />
        <small v-if="errors.next_due_on" data-slot="input-error" role="alert">{{
            errors.next_due_on
        }}</small>
    </label>
    <section
        v-if="showChecklist"
        class="crm-flow crm-form-span"
        aria-label="Routine checklist"
    >
        <h3>Checklist (optional)</h3>
        <label
            v-for="(_, index) in model.checklist"
            :key="index"
            class="crm-field"
        >
            <span class="crm-field-label">Checklist item {{ index + 1 }}</span
            ><input
                v-model="model.checklist[index]"
                required
                maxlength="200"
                :aria-invalid="!!errors[`checklist.${index}`]"
            />
            <small
                v-if="errors[`checklist.${index}`]"
                data-slot="input-error"
                role="alert"
                >{{ errors[`checklist.${index}`] }}</small
            >
            <button
                type="button"
                class="crm-ghost"
                :aria-label="`Remove checklist item ${index + 1}`"
                @click="model.checklist.splice(index, 1)"
            >
                Remove item
            </button>
        </label>
        <small v-if="errors.checklist" data-slot="input-error" role="alert">{{
            errors.checklist
        }}</small>
        <div class="crm-form-action">
            <button
                type="button"
                class="crm-secondary"
                :disabled="model.checklist.length >= 20"
                @click="model.checklist.push('')"
            >
                Add checklist item
            </button>
        </div>
    </section>
</template>
