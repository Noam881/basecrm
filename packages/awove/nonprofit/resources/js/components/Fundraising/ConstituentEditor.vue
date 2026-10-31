<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { useForm } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import type { Options, Constituent } from "./types";
import Errors from "./Errors.vue";
defineOptions({ inheritAttrs: false });
const p = defineProps<Options & { record?: Constituent; editable?: boolean }>();
function initial() {
    return {
        version: p.record?.version ?? 1,
        relationship_id: p.record?.relationship_id ?? (null as number | null),
        owner_id: p.record ? p.record.owner_id : p.currentOwnerId,
        next_action: p.record?.next_action ?? "",
        next_action_on: p.record?.next_action_on ?? "",
        notes: p.record?.notes ?? "",
    };
}
const f = useForm(initial());
const stale = computed(() => !!p.record && f.version !== p.record.version);
const relationships = computed(() =>
    p.record?.relationship &&
    !p.relationships.some((r) => r.id === p.record?.relationship_id)
        ? [...p.relationships, p.record.relationship]
        : p.relationships,
);
function reset() {
    f.defaults(initial());
    f.reset();
    f.clearErrors();
}
watch(
    () => p.record?.version,
    () => {
        if (!f.isDirty) reset();
    },
);
function save() {
    if (stale.value) return;
    f.transform((d) =>
        p.record
            ? d
            : {
                  relationship_id: d.relationship_id,
                  owner_id: d.owner_id,
                  next_action: d.next_action,
                  next_action_on: d.next_action_on,
                  notes: d.notes,
              },
    );
    if (p.record)
        f.patch(`/nonprofit/fundraising/constituents/${p.record.id}`, {
            preserveScroll: true,
            onSuccess: reset,
        });
    else
        f.post("/nonprofit/fundraising/constituents", {
            preserveScroll: true,
            onSuccess: reset,
        });
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <fieldset
            class="crm-form-grid crm-form-span"
            :disabled="!!record && !editable"
        >
            <label class="crm-field"
                ><span class="crm-field-label">{{
                    tr("Shared donor, partner or prospect")
                }}</span
                ><select
                    v-model="f.relationship_id"
                    required
                    :disabled="!!record"
                    :aria-label="tr('Shared donor, partner or prospect')"
                >
                    <option :value="null">
                        {{ tr("Choose existing classification") }}
                    </option>
                    <option
                        v-for="r in relationships"
                        :key="r.id"
                        :value="r.id"
                    >
                        {{ r.name }} · {{ r.role
                        }}{{ !r.active ? tr(" (inactive)") : "" }}
                    </option>
                </select></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{
                    tr("Responsible person")
                }}</span
                ><select
                    v-model="f.owner_id"
                    :disabled="!canAssign"
                    :aria-label="tr('Responsible person')"
                >
                    <option :value="null">{{ tr("Unassigned") }}</option>
                    <option v-for="o in owners" :key="o.id" :value="o.id">
                        {{ o.name }}
                    </option>
                </select></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("Next action") }}</span
                ><textarea
                    v-model="f.next_action"
                    rows="2"
                    maxlength="10000"
                /></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{
                    tr("Next action date")
                }}</span
                ><input v-model="f.next_action_on" type="date" /></label
            ><label class="crm-field crm-form-span"
                ><span class="crm-field-label">{{
                    tr("Fundraising notes")
                }}</span
                ><textarea v-model="f.notes" rows="3" maxlength="10000" />
            </label>
        </fieldset>
        <p class="crm-muted crm-form-span">
            {{
                tr(
                    "Clear both next-action fields when no follow-up remains. This changes no pledge or receipt.",
                )
            }}
        </p>
        <p v-if="stale" class="crm-notice crm-form-span" role="alert">
            {{ tr("The record changed. Reload the form before saving.") }}
        </p>
        <Errors :errors="f.errors" />
        <div v-if="!record || editable" class="crm-form-action">
            <button class="crm-button" :disabled="stale || f.processing">
                {{
                    record
                        ? tr("Save fundraising details")
                        : tr("Add fundraising context")
                }}</button
            ><button
                v-if="stale"
                class="crm-secondary"
                type="button"
                @click="reset"
            >
                {{ tr("Reload form") }}
            </button>
        </div>
    </form>
</template>
