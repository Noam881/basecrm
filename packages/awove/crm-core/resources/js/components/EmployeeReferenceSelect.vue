<script setup lang="ts">
import { computed } from "vue";
export interface EmployeeSummary {
    id: number;
    name: string;
    active: boolean;
}
const model = defineModel<number | null>({ required: true });
const props = withDefaults(
    defineProps<{
        options: EmployeeSummary[];
        selected?: EmployeeSummary | null;
        label?: string;
        required?: boolean;
        error?: string;
        canManage?: boolean;
    }>(),
    { label: "Employee", required: true, canManage: false },
);
const choices = computed(() =>
    props.selected &&
    !props.options.some((option) => option.id === props.selected?.id)
        ? [...props.options, props.selected]
        : props.options,
);
</script>
<template>
    <label class="crm-field">
        <span class="crm-field-label">{{ label }}</span>
        <select
            v-model="model"
            :required="required"
            :aria-invalid="!!error"
            :aria-label="label"
        >
            <option :value="null">Choose an employee</option>
            <option
                v-for="employee in choices"
                :key="employee.id"
                :value="employee.id"
            >
                {{ employee.name }}{{ employee.active ? "" : " (inactive)" }}
            </option>
        </select>
        <small v-if="error" data-slot="input-error" role="alert">{{
            error
        }}</small>
        <a
            v-if="canManage"
            class="crm-inline-link"
            href="/people/employees"
            target="_blank"
            rel="noopener noreferrer"
            >Manage employee references</a
        >
    </label>
</template>
