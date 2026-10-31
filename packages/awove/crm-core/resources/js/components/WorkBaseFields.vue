<script setup lang="ts">
// Common controls shared by typed work items and module request composers.
const title = defineModel<string>("title", { required: true });
const description = defineModel<string>("description", { required: true });
const ownerId = defineModel<number | null>("ownerId", { required: true });
const priority = defineModel<string>("priority", { required: true });
const dueOn = defineModel<string>("dueOn", { required: true });
withDefaults(
    defineProps<{
        owners: { id: number; name: string }[];
        errors: Record<string, string | undefined>;
        canAssign: boolean;
        titleLabel?: string;
        titleErrorKey?: string;
        descriptionRequired?: boolean;
        dueRequired?: boolean;
    }>(),
    {
        titleLabel: "Title",
        titleErrorKey: "title",
        descriptionRequired: false,
        dueRequired: true,
    },
);
</script>
<template>
    <label class="crm-field crm-form-span">
        <span class="crm-field-label">{{ titleLabel }}</span>
        <input
            v-model="title"
            required
            maxlength="180"
            :aria-invalid="!!errors[titleErrorKey]"
        />
        <small
            v-if="errors[titleErrorKey]"
            data-slot="input-error"
            role="alert"
            >{{ errors[titleErrorKey] }}</small
        >
    </label>
    <label class="crm-field crm-form-span">
        <span class="crm-field-label">Description</span>
        <textarea
            v-model="description"
            rows="3"
            maxlength="20000"
            :required="descriptionRequired"
            :aria-invalid="!!errors.description"
        />
        <small v-if="errors.description" data-slot="input-error" role="alert">{{
            errors.description
        }}</small>
    </label>
    <label class="crm-field">
        <span class="crm-field-label">Owner</span>
        <select v-model="ownerId" :disabled="!canAssign">
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
        <span class="crm-field-label">Priority</span>
        <select v-model="priority">
            <option value="low">Low</option>
            <option value="normal">Normal</option>
            <option value="high">High</option>
        </select>
        <small v-if="errors.priority" data-slot="input-error" role="alert">{{
            errors.priority
        }}</small>
    </label>
    <label class="crm-field">
        <span class="crm-field-label">Due date</span>
        <input
            v-model="dueOn"
            type="date"
            :required="dueRequired"
            :aria-invalid="!!errors.due_on"
        />
        <small v-if="errors.due_on" data-slot="input-error" role="alert">{{
            errors.due_on
        }}</small>
    </label>
</template>
