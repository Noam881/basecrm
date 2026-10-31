<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import Errors from "./Errors.vue";
import type { Options, Project } from "./types";
const p = defineProps<Options & { record?: Project }>();
function initial() {
    return {
        name: p.record?.name ?? "",
        objective: p.record?.objective ?? "",
        owner_id: p.record ? p.record.owner_id : p.currentOwnerId,
        starts_on: p.record?.starts_on ?? p.today,
        ends_on: p.record?.ends_on ?? p.today,
        status: p.record?.status ?? "planned",
        planned_budget: p.record?.planned_budget ?? "",
        version: p.record?.version,
    };
}
const form = useForm(initial());
const stale = computed(() => !!p.record && form.version !== p.record.version);
const owners = computed(() =>
    p.record?.owner && !p.owners.some((o) => o.id === p.record?.owner?.id)
        ? [...p.owners, p.record.owner]
        : p.owners,
);
function reset() {
    form.defaults(initial());
    form.reset();
    form.clearErrors();
}
watch(
    () => p.record?.version,
    () => {
        if (!form.isDirty) reset();
    },
);
function save() {
    if (stale.value) return;
    form.transform((data) => {
        const { planned_budget, ...basic } = data;
        return p.canManageBudget ? data : basic;
    });
    if (p.record)
        form.patch(`/nonprofit/projects/${p.record.id}`, {
            preserveScroll: true,
            onSuccess: reset,
        });
    else form.post("/nonprofit/projects", { onSuccess: reset });
}
</script>
<template>
    <form class="crm-form" @submit.prevent="save">
        <fieldset
            class="crm-form-grid"
            :disabled="!!record && !record.editable"
        >
            <label class="crm-field"
                ><span class="crm-field-label">Project name</span
                ><input v-model="form.name" required maxlength="180" /></label
            ><label class="crm-field"
                ><span class="crm-field-label">Project owner</span
                ><select v-model="form.owner_id" :disabled="!canAssign">
                    <option :value="null">Unassigned</option>
                    <option
                        v-for="owner in owners"
                        :key="owner.id"
                        :value="owner.id"
                    >
                        {{ owner.name }}
                    </option>
                </select></label
            >
            <label class="crm-field crm-form-span"
                ><span class="crm-field-label">Objective</span
                ><textarea
                    v-model="form.objective"
                    required
                    rows="3"
                    maxlength="20000"
                />
            </label>
            <label class="crm-field"
                ><span class="crm-field-label">Start date</span
                ><input v-model="form.starts_on" type="date" required /></label
            ><label class="crm-field"
                ><span class="crm-field-label">End date</span
                ><input
                    v-model="form.ends_on"
                    type="date"
                    required
                    :min="form.starts_on"
            /></label>
            <label class="crm-field"
                ><span class="crm-field-label">Project status</span
                ><select v-model="form.status">
                    <option
                        v-for="(label, key) in statuses"
                        :key="key"
                        :value="key"
                    >
                        {{ label }}
                    </option>
                </select></label
            >
            <label v-if="canViewBudget" class="crm-field"
                ><span class="crm-field-label"
                    >Planned budget ({{ record?.currency ?? currency }})</span
                ><input
                    v-model="form.planned_budget"
                    type="text"
                    inputmode="decimal"
                    :disabled="!canManageBudget || !!currencyError"
                /><small
                    >Optional. Revised amounts retain previous budget
                    snapshots.</small
                ></label
            >
        </fieldset>
        <p
            v-if="currencyError && canViewBudget"
            class="crm-notice"
            role="alert"
        >
            {{ currencyError }}
        </p>
        <p v-if="stale" class="crm-notice" role="alert">
            This project changed. Reload the latest record before saving.
        </p>
        <Errors :errors="form.errors" />
        <div v-if="!record || record.editable" class="crm-form-action">
            <button class="crm-button" :disabled="form.processing || stale">
                {{ record ? "Save project" : "Create project" }}</button
            ><button
                v-if="stale"
                class="crm-secondary"
                type="button"
                @click="reset"
            >
                Reload project
            </button>
        </div>
    </form>
</template>
