<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { useForm } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import WorkFields from "../../../../../crm-core/resources/js/components/WorkFields.vue";
import Errors from "./Errors.vue";
import type { Options, Project, WorkRecord } from "./types";
const p = defineProps<
    Options & {
        project: Pick<Project, "id" | "version">;
        kind: string;
        record?: WorkRecord;
    }
>();
function initial() {
    return {
        title: p.record?.title ?? "",
        description: p.record?.description ?? "",
        owner_id: p.record ? p.record.owner_id : p.currentOwnerId,
        priority: p.record?.priority ?? "normal",
        due_on: p.record?.due_on ?? p.today,
        status: p.record?.status ?? "not-started",
        completed_on: p.record?.completed_on ?? "",
        project_version: p.project.version,
        version: p.record?.version,
    };
}
const form = useForm(initial());
const stale = computed(
    () =>
        form.project_version !== p.project.version ||
        form.version !== p.record?.version,
);
const owners = computed(() =>
    p.record?.owner && !p.workOwners.some((o) => o.id === p.record?.owner?.id)
        ? [...p.workOwners, p.record.owner]
        : p.workOwners,
);
function reset() {
    form.defaults(initial());
    form.reset();
    form.clearErrors();
}
watch(
    () => [p.project.version, p.record?.version],
    () => {
        if (!form.isDirty) reset();
    },
);
function save() {
    if (stale.value) return;
    if (p.record)
        form.patch(`/nonprofit/${p.kind}/${p.record.id}`, {
            preserveScroll: true,
            onSuccess: reset,
        });
    else
        form.post(`/nonprofit/projects/${p.project.id}/work/${p.kind}`, {
            onSuccess: reset,
        });
}
</script>
<template>
    <form class="crm-form" @submit.prevent="save">
        <fieldset
            class="crm-form-grid"
            :disabled="!!record && !record.editable"
        >
            <WorkFields
                v-model="form"
                :owners="owners"
                :statuses="workStatuses"
                :completed-statuses="['done']"
                :can-assign="canAssign"
                :errors="form.errors"
                :title-label="
                    kind === 'project-milestones'
                        ? tr('Milestone title')
                        : tr('Task title')
                "
            />
        </fieldset>
        <p v-if="stale" class="crm-notice" role="alert">
            {{ tr("The project or work item changed. Reload before saving.") }}
        </p>
        <Errors :errors="form.errors" />
        <div v-if="!record || record.editable" class="crm-form-action">
            <button class="crm-button" :disabled="form.processing || stale">
                {{
                    record
                        ? tr("Save work")
                        : kind === "project-milestones"
                          ? tr("Create milestone")
                          : tr("Create task")
                }}</button
            ><button
                v-if="stale"
                type="button"
                class="crm-secondary"
                @click="reset"
            >
                {{ tr("Reload work") }}
            </button>
        </div>
    </form>
</template>
