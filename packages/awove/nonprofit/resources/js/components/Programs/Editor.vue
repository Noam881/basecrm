<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import type { Options, ProgramRecord, Kind, Person, Related } from "./types";
import { singular } from "./types";
import Errors from "./Errors.vue";
defineOptions({ inheritAttrs: false });
const p = defineProps<
    Options & {
        kind: Exclude<Kind, "attendance">;
        record?: ProgramRecord;
        program?: ProgramRecord | null;
    }
>();
function initial() {
    const r = p.record;
    return {
        version: r?.version ?? 1,
        program_version: p.program?.version ?? 1,
        name: r?.name ?? "",
        owner_id: r ? (r.owner_id ?? null) : p.currentOwnerId,
        active: r?.active ?? true,
        notes: r?.notes ?? "",
        participant_code: r?.participant_code ?? "",
        email: r?.email ?? "",
        phone: r?.phone ?? "",
        contact_id: r?.contact_id ?? null,
        program_id: r?.program_id ?? null,
        location_id: r
            ? (r.location_id ?? null)
            : (p.program?.location_id ?? null),
        group_id: r ? (r.group_id ?? null) : (p.program?.group_id ?? null),
        starts_on: r?.starts_on ?? "",
        ends_on: r?.ends_on ?? "",
        session_on: r?.session_on ?? p.today,
        feedback_on: r?.feedback_on ?? p.today,
        comments: r?.comments ?? "",
        follow_up_owner_id: r
            ? (r.follow_up_owner_id ?? null)
            : p.currentOwnerId,
        next_action: r?.next_action ?? "",
        due_on: r?.due_on ?? "",
        completed_on: r?.completed_on ?? "",
    };
}
const f = useForm(initial());
const child = computed(() => ["sessions", "feedback"].includes(p.kind));
const stale = computed(
    () =>
        (!!p.record && f.version !== p.record.version) ||
        (child.value && !!p.program && f.program_version !== p.program.version),
);
const disabled = computed(() =>
    p.record ? !p.record.editable : child.value && !p.program?.editable,
);
const canAssign = computed(() =>
    p.kind === "participants" ? p.canAssignParticipants : p.canAssign,
);
function withSelected<T extends Person>(
    items: T[],
    selected: T | null | undefined,
): T[] {
    return selected && !items.some((x) => x.id === selected.id)
        ? [...items, selected]
        : items;
}
const ownerOptions = computed(() =>
    withSelected(
        p.kind === "participants"
            ? p.participantOwners
            : p.kind === "feedback"
              ? p.followUpOwners
              : p.owners,
        p.kind === "feedback" ? p.record?.follow_up_owner : p.record?.owner,
    ),
);
const related = (kind: "programs" | "groups" | "locations" | "contacts") =>
    withSelected(
        p[kind],
        p.record?.[
            kind === "programs"
                ? "program"
                : kind === "groups"
                  ? "group"
                  : kind === "locations"
                    ? "location"
                    : "contact"
        ] ?? null,
    );
function sync() {
    f.defaults(initial());
    f.reset();
    f.clearErrors();
}
watch(
    () => [p.record?.version, p.program?.version],
    () => {
        if (!f.isDirty) sync();
    },
);
function save() {
    if (stale.value) return;
    let fields: string[] = ["name", "owner_id", "active", "notes"];
    if (["groups", "programs", "participants"].includes(p.kind))
        fields.push("location_id");
    if (["programs", "participants"].includes(p.kind)) fields.push("group_id");
    if (p.kind === "programs") fields.push("starts_on", "ends_on");
    if (p.kind === "participants")
        fields.push(
            "participant_code",
            "email",
            "phone",
            "contact_id",
            "program_id",
        );
    if (p.kind === "sessions")
        fields = [
            "name",
            "session_on",
            "location_id",
            "group_id",
            "notes",
            "program_version",
        ];
    if (p.kind === "feedback")
        fields = [
            "feedback_on",
            "comments",
            "follow_up_owner_id",
            "next_action",
            "due_on",
            "completed_on",
            "program_version",
        ];
    if (p.record) fields.push("version");
    f.transform((data) =>
        Object.fromEntries(
            fields.map((key) => [key, data[key as keyof typeof data]]),
        ),
    );
    if (p.record)
        f.patch(`/nonprofit/programs/${p.kind}/${p.record.id}`, {
            preserveScroll: true,
            onSuccess: sync,
        });
    else if (child.value && p.program)
        f.post(`/nonprofit/programs/programs/${p.program.id}/${p.kind}`);
    else f.post(`/nonprofit/programs/${p.kind}`);
}
</script>
<template>
    <form class="crm-form" @submit.prevent="save">
        <fieldset class="crm-form-grid" :disabled="disabled">
            <label v-if="kind !== 'feedback'" class="crm-field"
                ><span class="crm-field-label">{{
                    kind === "participants"
                        ? "Participant name"
                        : kind === "sessions"
                          ? "Session name"
                          : kind === "programs"
                            ? "Program name"
                            : kind === "groups"
                              ? "Group name"
                              : "Location name"
                }}</span
                ><input v-model="f.name" required maxlength="180"
            /></label>
            <label v-if="kind === 'participants'" class="crm-field"
                ><span class="crm-field-label">Participant ID</span
                ><input v-model="f.participant_code" required maxlength="80"
            /></label>
            <label v-if="kind !== 'sessions'" class="crm-field"
                ><span class="crm-field-label">{{
                    kind === "feedback"
                        ? "Follow-up owner"
                        : "Responsible staff member"
                }}</span
                ><select
                    v-if="kind === 'feedback'"
                    v-model="f.follow_up_owner_id"
                    :disabled="!canAssign"
                    aria-label="Follow-up owner"
                >
                    <option :value="null">Unassigned</option>
                    <option
                        v-for="owner in ownerOptions"
                        :key="owner.id"
                        :value="owner.id"
                    >
                        {{ owner.name }}
                    </option>
                    <option
                        v-if="
                            f.follow_up_owner_id &&
                            !ownerOptions.some(
                                (o) => o.id === f.follow_up_owner_id,
                            )
                        "
                        :value="f.follow_up_owner_id"
                    >
                        Current responsible staff member
                    </option></select
                ><select
                    v-else
                    v-model="f.owner_id"
                    :disabled="!canAssign"
                    aria-label="Responsible staff member"
                >
                    <option :value="null">Unassigned</option>
                    <option
                        v-for="owner in ownerOptions"
                        :key="owner.id"
                        :value="owner.id"
                    >
                        {{ owner.name }}
                    </option>
                    <option
                        v-if="
                            f.owner_id &&
                            !ownerOptions.some((o) => o.id === f.owner_id)
                        "
                        :value="f.owner_id"
                    >
                        Current responsible staff member
                    </option>
                </select></label
            >
            <label
                v-if="!['sessions', 'feedback'].includes(kind)"
                class="crm-field"
                ><span class="crm-field-label">Record status</span
                ><select v-model="f.active" aria-label="Record status">
                    <option :value="true">Active</option>
                    <option :value="false">Inactive</option>
                </select></label
            >
            <label
                v-if="
                    ['programs', 'participants', 'groups', 'sessions'].includes(
                        kind,
                    )
                "
                class="crm-field"
                ><span class="crm-field-label">Location</span
                ><select
                    v-model="f.location_id"
                    :disabled="record?.location_restricted"
                    aria-label="Location"
                >
                    <option :value="null">Not specified</option>
                    <option
                        v-for="r in related('locations')"
                        :key="r.id"
                        :value="r.id"
                    >
                        {{ r.name }}
                    </option>
                </select></label
            >
            <label
                v-if="['programs', 'participants', 'sessions'].includes(kind)"
                class="crm-field"
                ><span class="crm-field-label">Group</span
                ><select
                    v-model="f.group_id"
                    :disabled="record?.group_restricted"
                    aria-label="Group"
                >
                    <option :value="null">Not specified</option>
                    <option
                        v-for="r in related('groups')"
                        :key="r.id"
                        :value="r.id"
                    >
                        {{ r.name }}
                    </option>
                </select></label
            >
            <template v-if="kind === 'participants'"
                ><label class="crm-field"
                    ><span class="crm-field-label">Current program</span
                    ><select
                        v-model="f.program_id"
                        :disabled="record?.program_restricted"
                        aria-label="Current program"
                    >
                        <option :value="null">Not specified</option>
                        <option
                            v-for="r in related('programs')"
                            :key="r.id"
                            :value="r.id"
                        >
                            {{ r.name }}
                        </option>
                    </select></label
                ><label class="crm-field"
                    ><span class="crm-field-label"
                        >Shared contact (optional)</span
                    ><select
                        v-model="f.contact_id"
                        :disabled="record?.contact_restricted"
                        aria-label="Shared contact (optional)"
                    >
                        <option :value="null">No shared contact link</option>
                        <option
                            v-for="r in related('contacts')"
                            :key="r.id"
                            :value="r.id"
                        >
                            {{ r.name }}
                        </option>
                    </select></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Private email</span
                    ><input
                        v-model="f.email"
                        type="email"
                        maxlength="254" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Private phone</span
                    ><input
                        v-model="f.phone"
                        type="tel"
                        maxlength="80" /></label
            ></template>
            <template v-if="kind === 'programs'"
                ><label class="crm-field"
                    ><span class="crm-field-label">Start date</span
                    ><input v-model="f.starts_on" type="date" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">End date</span
                    ><input
                        v-model="f.ends_on"
                        type="date"
                        :min="f.starts_on || undefined" /></label
            ></template>
            <label v-if="kind === 'sessions'" class="crm-field"
                ><span class="crm-field-label">Session date</span
                ><input v-model="f.session_on" type="date" required
            /></label>
            <template v-if="kind === 'feedback'"
                ><label class="crm-field"
                    ><span class="crm-field-label">Feedback date</span
                    ><input
                        v-model="f.feedback_on"
                        type="date"
                        required
                        :max="today" /></label
                ><label class="crm-field crm-form-span"
                    ><span class="crm-field-label">Comments</span
                    ><textarea
                        v-model="f.comments"
                        required
                        rows="3"
                        maxlength="10000"
                    /></label
                ><label class="crm-field crm-form-span"
                    ><span class="crm-field-label">Next action</span
                    ><input v-model="f.next_action" maxlength="500" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Follow-up date</span
                    ><input v-model="f.due_on" type="date" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Completed date</span
                    ><input
                        v-model="f.completed_on"
                        type="date"
                        :max="today" /></label
            ></template>
            <label v-else class="crm-field crm-form-span"
                ><span class="crm-field-label">Notes</span
                ><textarea v-model="f.notes" rows="3" maxlength="10000" />
            </label>
        </fieldset>
        <p
            v-if="
                record?.contact_restricted ||
                record?.program_restricted ||
                record?.location_restricted ||
                record?.group_restricted
            "
            class="crm-notice"
        >
            A restricted linked record is retained when you save.
        </p>
        <p v-if="kind === 'participants'" class="crm-muted">
            Private contact details stay within the participant roster. A shared
            contact link grants no participant access.
        </p>
        <p v-if="stale" class="crm-notice" role="alert">
            The record changed. Reload current details before saving.
        </p>
        <Errors :errors="f.errors" />
        <div v-if="!disabled" class="crm-form-action">
            <button class="crm-button" :disabled="f.processing || stale">
                {{ record ? "Save" : "Create" }} {{ singular[kind] }}</button
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
