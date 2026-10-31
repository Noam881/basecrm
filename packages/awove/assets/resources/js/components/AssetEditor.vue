<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { useForm } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import type { Options, AssetRecord } from "../types";
import FormErrors from "./FormErrors.vue";
defineOptions({ inheritAttrs: false });
const p = defineProps<Options & { record?: AssetRecord; editable?: boolean }>();
function initial() {
    return {
        code: p.record?.code ?? "",
        name: p.record?.name ?? "",
        type: p.record?.type ?? "",
        location: p.record?.location ?? "",
        owner_id: p.record ? p.record.owner_id : p.currentOwnerId,
        notes: p.record?.notes ?? "",
        purchased_on: p.record?.purchased_on ?? "",
        serial_number: p.record?.serial_number ?? "",
        status: p.record?.status ?? "available",
        version: p.record?.version ?? 1,
    };
}
const f = useForm(initial());
const stale = computed(() => !!p.record && f.version !== p.record.version);
const disabled = computed(() => !!p.record && !p.editable);
const owners = computed(() =>
    p.record?.owner && !p.owners.some((o) => o.id === p.record?.owner?.id)
        ? [...p.owners, p.record.owner]
        : p.owners,
);
function sync() {
    f.defaults(initial());
    f.reset();
    f.clearErrors();
}
watch(
    () => p.record?.version,
    () => {
        if (!f.isDirty) sync();
    },
);
function save() {
    if (stale.value) return;
    if (p.record)
        f.patch(`/assets/register/${p.record.id}`, {
            preserveScroll: true,
            onSuccess: sync,
        });
    else f.post("/assets/register");
}
</script>
<template>
    <form class="crm-form" @submit.prevent="save">
        <fieldset class="crm-form-grid" :disabled="disabled">
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Asset code") }}</span
                ><input v-model="f.code" required maxlength="80" /></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("Asset name") }}</span
                ><input v-model="f.name" required maxlength="180" /></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("Asset type") }}</span
                ><input v-model="f.type" required maxlength="120" /></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("Location") }}</span
                ><input v-model="f.location" required maxlength="180" /></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{
                    tr("Responsible staff member")
                }}</span
                ><select
                    v-model="f.owner_id"
                    :disabled="!canAssign"
                    :aria-label="tr('Responsible staff member')"
                >
                    <option :value="null">{{ tr("Unassigned") }}</option>
                    <option v-for="o in owners" :key="o.id" :value="o.id">
                        {{ o.name }}
                    </option>
                    <option
                        v-if="
                            f.owner_id !== null &&
                            !owners.some((o) => o.id === f.owner_id)
                        "
                        :value="f.owner_id"
                    >
                        {{ tr("Current responsible staff member") }}
                    </option>
                </select></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("Status") }}</span
                ><select v-model="f.status" :aria-label="tr('Status')">
                    <option
                        v-for="(label, key) in statuses"
                        :key="key"
                        :value="key"
                    >
                        {{ label }}
                    </option>
                </select></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("Purchase date") }}</span
                ><input v-model="f.purchased_on" type="date" /></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("Serial number") }}</span
                ><input v-model="f.serial_number" maxlength="180" /></label
            ><label class="crm-field crm-form-span"
                ><span class="crm-field-label">{{ tr("Notes") }}</span
                ><textarea v-model="f.notes" rows="3" maxlength="10000" />
            </label>
        </fieldset>
        <p v-if="stale" class="crm-notice" role="alert">
            {{
                tr(
                    "The asset changed. Reload its latest details before saving.",
                )
            }}
        </p>
        <FormErrors :errors="f.errors" />
        <div v-if="!disabled" class="crm-form-action">
            <button class="crm-button" :disabled="f.processing || stale">
                {{ record ? tr("Save asset") : tr("Create asset") }}</button
            ><button
                v-if="stale"
                type="button"
                class="crm-secondary"
                @click="sync"
            >
                {{ tr("Reload current asset") }}
            </button>
        </div>
    </form>
</template>
