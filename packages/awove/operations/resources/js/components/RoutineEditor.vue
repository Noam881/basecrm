<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { useForm, usePage } from "@inertiajs/vue3";
import RoutineFields from "../../../../crm-core/resources/js/components/RoutineFields.vue";
import { historicalOwner, type Options, type Routine } from "../types";
defineOptions({ inheritAttrs: false });
const props = defineProps<Options & { record?: Routine }>();
const form = useForm({
    name: props.record?.name ?? "",
    owner_id: props.record
        ? props.record.owner_id
        : (usePage().props.auth as { user: { id: number } }).user.id,
    frequency: props.record?.frequency ?? "",
    next_due_on: props.record?.next_due_on ?? "",
    checklist: [...(props.record?.checklist ?? [])],
    version: props.record?.version ?? 1,
});
function save() {
    if (props.record)
        form.patch(`/operations/routines/${props.record.id}`, {
            preserveScroll: true,
        });
    else form.post("/operations/routines");
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <RoutineFields
            :model-value="form"
            :owners="historicalOwner(owners, record?.owner)"
            :errors="form.errors"
            :can-assign="canAssign"
            :next-required="!record"
        />
        <div class="crm-form-action">
            <button class="crm-button" :disabled="form.processing">
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
