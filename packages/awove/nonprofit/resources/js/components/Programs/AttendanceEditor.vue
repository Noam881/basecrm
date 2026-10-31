<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import type { Options, ProgramRecord } from "./types";
import Errors from "./Errors.vue";
defineOptions({ inheritAttrs: false });
const p = defineProps<
    Options & {
        session: ProgramRecord;
        program: ProgramRecord;
        record?: ProgramRecord;
    }
>();
function initial() {
    return {
        participant_id: p.record?.participant_id ?? (null as number | null),
        status: p.record?.status ?? "present",
        notes: p.record?.notes ?? "",
        program_version: p.program.version,
        session_version: p.session.version,
        version: p.record?.version ?? 1,
    };
}
const f = useForm(initial());
const stale = computed(
    () =>
        f.program_version !== p.program.version ||
        f.session_version !== p.session.version ||
        (!!p.record && f.version !== p.record.version),
);
const people = computed(() =>
    p.record?.participant &&
    !p.participants.some((r) => r.id === p.record?.participant_id)
        ? [...p.participants, p.record.participant]
        : p.participants,
);
function sync() {
    f.defaults(initial());
    f.reset();
    f.clearErrors();
}
watch(
    () => [p.program.version, p.session.version, p.record?.version],
    () => {
        if (!f.isDirty) sync();
    },
);
function save() {
    if (stale.value) return;
    if (p.record)
        f.patch(`/nonprofit/programs/attendance/${p.record.id}`, {
            preserveScroll: true,
            onSuccess: sync,
        });
    else
        f.post(`/nonprofit/programs/sessions/${p.session.id}/attendance`, {
            preserveScroll: true,
            onSuccess: sync,
        });
}
</script>
<template>
    <form class="crm-form" @submit.prevent="save">
        <fieldset
            class="crm-form-grid"
            :disabled="
                !program.editable || !session.editable || !canManageAttendance
            "
        >
            <label class="crm-field"
                ><span class="crm-field-label">Participant</span
                ><select
                    v-model="f.participant_id"
                    required
                    :disabled="!!record"
                    aria-label="Participant"
                >
                    <option :value="null" disabled>Choose participant</option>
                    <option v-for="r in people" :key="r.id" :value="r.id">
                        {{ r.name }}
                    </option>
                </select></label
            ><label class="crm-field"
                ><span class="crm-field-label">Attendance status</span
                ><select v-model="f.status" aria-label="Attendance status">
                    <option
                        v-for="(label, key) in statuses"
                        :key="key"
                        :value="key"
                    >
                        {{ label }}
                    </option>
                </select></label
            ><label class="crm-field crm-form-span"
                ><span class="crm-field-label">Attendance notes</span
                ><textarea v-model="f.notes" maxlength="3000" rows="2" />
            </label>
        </fieldset>
        <p class="crm-muted">
            One record per participant and session. Unrecorded attendance is not
            counted as absent.
        </p>
        <p v-if="stale" class="crm-notice" role="alert">
            The session changed. Reload current details before saving.
        </p>
        <Errors :errors="f.errors" />
        <div
            v-if="program.editable && session.editable && canManageAttendance"
            class="crm-form-action"
        >
            <button class="crm-button" :disabled="f.processing || stale">
                {{ record ? "Save attendance" : "Record attendance" }}</button
            ><button
                v-if="stale"
                type="button"
                class="crm-secondary"
                @click="sync"
            >
                Reload current details
            </button>
        </div>
    </form>
</template>
