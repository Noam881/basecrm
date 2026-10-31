<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import type { Options, AssetRecord, MaintenanceRecord } from "../types";
import FormErrors from "./FormErrors.vue";
defineOptions({ inheritAttrs: false });
const p = defineProps<
    Options & {
        asset?: AssetRecord;
        record?: MaintenanceRecord;
        editable?: boolean;
    }
>();
function initial() {
    return {
        version: p.record?.version ?? 1,
        asset_version: p.record?.asset.version ?? p.asset?.version ?? 1,
        action: p.record?.action ?? "",
        description: p.record?.description ?? "",
        owner_id: p.record ? p.record.owner_id : p.currentOwnerId,
        supplier_relationship_id: p.record?.supplier_relationship_id ?? null,
        due_on: p.record?.due_on ?? "",
        status: p.record?.status ?? "planned",
        next_due_on: p.record?.next_due_on ?? "",
    };
}
const f = useForm(initial());
const completed = computed(() => p.record?.status === "completed");
const openStatuses = computed(() =>
    Object.fromEntries(
        Object.entries(p.maintenanceStatuses).filter(
            ([key]) => key !== "completed",
        ),
    ),
);

const assetId = computed(() => p.record?.asset.id ?? p.asset?.id);
const stale = computed(
    () =>
        f.asset_version !== (p.record?.asset.version ?? p.asset?.version) ||
        (!!p.record && f.version !== p.record.version),
);
const disabled = computed(() => !!p.record && !p.editable);
const owners = computed(() =>
    p.record?.owner &&
    !p.maintenanceOwners.some((o) => o.id === p.record?.owner?.id)
        ? [...p.maintenanceOwners, p.record.owner]
        : p.maintenanceOwners,
);
const suppliers = computed(() =>
    p.record?.supplier &&
    !p.suppliers.some((s) => s.id === p.record?.supplier?.id)
        ? [...p.suppliers, p.record.supplier]
        : p.suppliers,
);
function reset() {
    f.defaults(initial());
    f.reset();
    f.clearErrors();
}
watch(
    () => [p.record?.version, p.record?.asset.version, p.asset?.version],
    () => {
        if (!f.isDirty) reset();
    },
);
function save() {
    if (stale.value) return;
    f.transform((data) =>
        completed.value
            ? {
                  version: data.version,
                  asset_version: data.asset_version,
                  owner_id: data.owner_id,
                  description: data.description,
                  next_due_on: data.next_due_on,
              }
            : {
                  ...(p.record ? { version: data.version } : {}),
                  asset_version: data.asset_version,
                  action: data.action,
                  description: data.description,
                  owner_id: data.owner_id,
                  due_on: data.due_on,
                  status: data.status,
                  ...(!p.record?.supplier_restricted ||
                  data.supplier_relationship_id !== null
                      ? {
                            supplier_relationship_id:
                                data.supplier_relationship_id,
                        }
                      : {}),
              },
    );
    if (p.record)
        f.patch(`/assets/maintenance/${p.record.id}`, {
            preserveScroll: true,
            onSuccess: reset,
        });
    else
        f.post(`/assets/register/${assetId.value}/maintenance`, {
            preserveScroll: true,
            onSuccess: reset,
        });
}
</script>
<template>
    <form class="crm-form" @submit.prevent="save">
        <fieldset class="crm-form-grid" :disabled="disabled">
            <template v-if="!completed"
                ><label class="crm-field crm-form-span"
                    ><span class="crm-field-label">Maintenance action</span
                    ><input
                        v-model="f.action"
                        required
                        maxlength="180" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Due date</span
                    ><input v-model="f.due_on" type="date" required /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Maintenance status</span
                    ><select v-model="f.status" aria-label="Maintenance status">
                        <option
                            v-for="(label, key) in openStatuses"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </select></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Supplier</span
                    ><select
                        v-model="f.supplier_relationship_id"
                        :disabled="record?.supplier_restricted"
                        aria-label="Supplier"
                    >
                        <option :value="null">
                            {{
                                record?.supplier_restricted
                                    ? "Existing supplier restricted"
                                    : "No supplier"
                            }}
                        </option>
                        <option
                            v-for="s in suppliers"
                            :key="s.id"
                            :value="s.id"
                        >
                            {{ s.name
                            }}{{ s.active === false ? " (inactive)" : "" }}
                        </option>
                    </select></label
                ></template
            ><label class="crm-field"
                ><span class="crm-field-label">Responsible staff member</span
                ><select
                    v-model="f.owner_id"
                    :disabled="!canAssign"
                    required
                    aria-label="Responsible staff member"
                >
                    <option :value="null">
                        Choose responsible staff member
                    </option>
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
                        Current responsible staff member
                    </option>
                </select></label
            ><label v-if="completed" class="crm-field"
                ><span class="crm-field-label">Manual next due date</span
                ><input v-model="f.next_due_on" type="date" /></label
            ><label class="crm-field crm-form-span"
                ><span class="crm-field-label">Maintenance notes</span
                ><textarea v-model="f.description" rows="3" maxlength="10000" />
            </label>
        </fieldset>
        <p v-if="completed" class="crm-muted">
            Completion details stay unchanged. A manual next due date is a
            reminder and does not create another maintenance action.
        </p>
        <p v-if="stale" class="crm-notice" role="alert">
            The asset or maintenance action changed. Reload the latest details
            before saving.
        </p>
        <FormErrors :errors="f.errors" />
        <div v-if="!disabled" class="crm-form-action">
            <button class="crm-button" :disabled="f.processing || stale">
                {{ record ? "Save maintenance" : "Plan maintenance" }}</button
            ><button
                v-if="stale"
                class="crm-secondary"
                type="button"
                @click="reset"
            >
                Reload maintenance form
            </button>
        </div>
    </form>
</template>
