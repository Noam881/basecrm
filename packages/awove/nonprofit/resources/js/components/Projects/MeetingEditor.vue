<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import WorkFields from "../../../../../crm-core/resources/js/components/WorkFields.vue";
import Errors from "./Errors.vue";
import type { Options, Project, Meeting } from "./types";
const p = defineProps<Options & { project: Project; meeting?: Meeting }>();
const addFollow = ref(false);
function initial() {
    return {
        held_on: p.meeting?.held_on ?? p.today,
        summary: p.meeting?.summary ?? "",
        decisions: p.meeting?.decisions ?? "",
        project_version: p.project.version,
        version: p.meeting?.version,
        follow_up: {
            title: "",
            description: "",
            owner_id: p.currentOwnerId,
            priority: "normal",
            due_on: p.today,
            status: "not-started",
            completed_on: "",
        },
    };
}
const form = useForm(initial());
const stale = computed(() => form.project_version !== p.project.version);
function reset() {
    form.defaults(initial());
    form.reset();
    form.clearErrors();
    addFollow.value = false;
}
function save() {
    if (stale.value) return;
    form.transform((d) => {
        const { follow_up, ...base } = d;
        return addFollow.value ? d : base;
    });
    const url = `/nonprofit/projects/${p.project.id}/meetings`;
    if (p.meeting)
        form.patch(`${url}/${p.meeting.id}`, {
            preserveScroll: true,
            onSuccess: reset,
        });
    else form.post(url, { preserveScroll: true, onSuccess: reset });
}
</script>
<template>
    <form class="crm-form" @submit.prevent="save">
        <label class="crm-field"
            ><span class="crm-field-label">Meeting date</span
            ><input v-model="form.held_on" type="date" required /></label
        ><label class="crm-field"
            ><span class="crm-field-label">Meeting summary</span
            ><textarea
                v-model="form.summary"
                required
                rows="3"
                maxlength="20000"
            /></label
        ><label class="crm-field"
            ><span class="crm-field-label">Decisions</span
            ><textarea
                v-model="form.decisions"
                rows="3"
                maxlength="20000"
            /></label
        ><label
            v-if="
                !meeting?.follow_up &&
                !meeting?.follow_up_restricted &&
                canCreate
            "
            class="crm-check-label"
            ><input v-model="addFollow" type="checkbox" /> Create a follow-up
            task</label
        >
        <fieldset v-if="addFollow" class="crm-form-grid">
            <legend>Meeting follow-up</legend>
            <WorkFields
                v-model="form.follow_up"
                title-label="Follow-up title"
                :owners="workOwners"
                :statuses="workStatuses"
                :completed-statuses="['done']"
                :can-assign="canAssign"
                :errors="form.errors"
            />
        </fieldset>
        <Errors :errors="form.errors" />
        <p v-if="stale" class="crm-notice">
            The project changed. Reload these meeting fields before saving.
        </p>
        <div class="crm-form-action">
            <button class="crm-button" :disabled="form.processing || stale">
                {{ meeting ? "Save meeting" : "Record meeting" }}</button
            ><button
                v-if="stale"
                type="button"
                class="crm-secondary"
                @click="reset"
            >
                Reload meeting fields
            </button>
        </div>
    </form>
</template>
