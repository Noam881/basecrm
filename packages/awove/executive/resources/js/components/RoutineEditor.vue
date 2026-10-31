<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { useForm, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import RoutineFields, {
    type RoutineDraft,
} from "../../../../crm-core/resources/js/components/RoutineFields.vue";
import type { Options, Routine } from "../types";
const props = defineProps<{ options: Options; record?: Routine }>();
const form = useForm({
    name: props.record?.name ?? "",
    owner_id: props.record
        ? props.record.owner_id
        : (usePage().props.auth as { user: { id: number } }).user.id,
    frequency: props.record?.frequency ?? "",
    next_due_on: props.record?.next_due_on ?? "",
    checklist: props.record?.checklist ?? ([] as string[]),
    version: props.record?.version ?? 1,
});
const draft = computed<RoutineDraft>({
    get: () => form,
    set: (value) => Object.assign(form, value),
});
const warning = ref("");
function save() {
    form.transform(({ version, ...data }) =>
        props.record ? { ...data, version } : data,
    );
    const options = {
        preserveScroll: true,
        onHttpException: (response: { status: number }) => {
            if ([403, 409].includes(response.status)) {
                warning.value = tr(
                    "This routine changed or your access changed. Your edits are kept. Reload before saving.",
                );
                return false;
            }
        },
    };
    if (props.record)
        form.patch(`/executive/routines/${props.record.id}`, options);
    else form.post("/executive/routines", options);
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <RoutineFields
            v-model="draft"
            :owners="options.owners"
            :errors="form.errors"
            :can-assign="options.canAssign"
            :next-required="!record"
            :show-checklist="(record?.checklist.length ?? 0) > 0"
        />
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
                        ? tr("Saving…")
                        : record
                          ? tr("Save routine")
                          : tr("Create routine")
                }}
            </button>
        </div>
    </form>
</template>
