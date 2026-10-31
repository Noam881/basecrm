<script setup lang="ts">
import { useForm, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import WorkFields, {
    type WorkDraft,
} from "../../../../crm-core/resources/js/components/WorkFields.vue";
import type { Options, Task } from "../types";
const props = defineProps<{ options: Options; record?: Task }>();
const form = useForm({
    title: props.record?.title ?? "",
    description: props.record?.description ?? "",
    owner_id: props.record
        ? props.record.owner_id
        : (usePage().props.auth as { user: { id: number } }).user.id,
    priority: props.record?.priority ?? "normal",
    due_on: props.record?.due_on ?? props.options.today,
    status: props.record?.status ?? "not-started",
    completed_on: props.record?.completed_on ?? "",
    goal_id: props.record?.goal_id ?? (null as number | null),
    goal_action: "set",
    version: props.record?.version ?? 1,
});
const keepRestricted = ref(props.record?.goal_restricted ?? false);
const draft = computed<WorkDraft>({
    get: () => form,
    set: (value) => Object.assign(form, value),
});
const warning = ref("");
function save() {
    form.transform(({ version, ...data }) => {
        const payload = {
            ...data,
            goal_action: keepRestricted.value
                ? "keep"
                : data.goal_id === null
                  ? "clear"
                  : "set",
        };
        return props.record ? { ...payload, version } : payload;
    });
    const options = {
        preserveScroll: true,
        onHttpException: (response: { status: number }) => {
            if ([403, 409].includes(response.status)) {
                warning.value =
                    "This task changed or your access changed. Your edits are kept. Reload before saving.";
                return false;
            }
        },
    };
    if (props.record)
        form.patch(`/executive/tasks/${props.record.id}`, options);
    else form.post("/executive/tasks", options);
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <WorkFields
            v-model="draft"
            :owners="options.owners"
            :statuses="options.taskStatuses"
            :completed-statuses="['done']"
            :errors="form.errors"
            :can-assign="options.canAssign"
            title-label="Task name"
        /><label
            v-if="record?.goal_restricted"
            class="crm-checkbox crm-form-span"
            ><input v-model="keepRestricted" type="checkbox" />Keep the existing
            restricted goal link</label
        ><label class="crm-field"
            ><span class="crm-field-label">Goal (optional)</span
            ><select v-model="form.goal_id" :disabled="keepRestricted">
                <option :value="null">No goal</option>
                <option
                    v-if="
                        record?.goal &&
                        !options.goals.some(
                            (goal) => goal.id === record?.goal_id,
                        )
                    "
                    :value="record.goal_id"
                >
                    {{ record.goal.name }} (archived)
                </option>
                <option
                    v-for="goal in options.goals"
                    :key="goal.id"
                    :value="goal.id"
                >
                    {{ goal.name }}
                </option>
            </select></label
        >
        <div
            v-if="Object.keys(form.errors).length || warning"
            class="crm-form-action crm-error"
            role="alert"
        >
            <p v-for="(error, key) in form.errors" :key="key">{{ error }}</p>
            <p v-if="warning">{{ warning }}</p>
        </div>
        <div class="crm-form-action">
            <button class="crm-button" :disabled="form.processing || !!warning">
                {{
                    form.processing
                        ? "Saving…"
                        : record
                          ? "Save task"
                          : "Create task"
                }}
            </button>
        </div>
    </form>
</template>
