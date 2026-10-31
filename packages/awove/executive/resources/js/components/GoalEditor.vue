<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { useForm, usePage } from "@inertiajs/vue3";
import { ref } from "vue";
import type { Goal, Options } from "../types";
const props = defineProps<{ options: Options; record?: Goal }>();
const form = useForm({
    name: props.record?.name ?? "",
    owner_id:
        props.record?.owner_id ??
        (usePage().props.auth as { user: { id: number } }).user.id,
    target_on: props.record?.target_on ?? "",
    success_criterion: props.record?.success_criterion ?? "",
    unit: props.record?.unit ?? "",
    target_value: props.record?.target_value ?? "",
    current_value: props.record?.current_value ?? "",
    status: props.record?.status ?? "not-started",
    version: props.record?.version ?? 1,
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
                    "This goal changed or your access changed. Your edits are kept. Reload before saving.",
                );
                return false;
            }
        },
    };
    if (props.record)
        form.patch(`/executive/goals/${props.record.id}`, options);
    else form.post("/executive/goals", options);
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <label class="crm-field"
            ><span class="crm-field-label">{{ tr("Goal name") }}</span
            ><input v-model="form.name" required maxlength="180" /></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{ tr("Responsible person") }}</span
            ><select v-model="form.owner_id" :disabled="!options.canAssign">
                <option
                    v-for="owner in options.owners"
                    :key="owner.id"
                    :value="owner.id"
                >
                    {{ owner.name }}
                </option>
            </select></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{ tr("Target date") }}</span
            ><input v-model="form.target_on" type="date" required /></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{ tr("Unit") }}</span
            ><input
                v-model="form.unit"
                required
                maxlength="80"
                :placeholder="tr('e.g. completed projects')" /></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{ tr("Target result") }}</span
            ><input
                v-model="form.target_value"
                inputmode="decimal"
                pattern="-?[0-9]+([.][0-9]{1,4})?"
                maxlength="20"
                required /></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{ tr("Current result") }}</span
            ><input
                v-model="form.current_value"
                inputmode="decimal"
                pattern="-?[0-9]+([.][0-9]{1,4})?"
                maxlength="20"
                required /></label
        ><label class="crm-field crm-form-span"
            ><span class="crm-field-label">{{ tr("Success criterion") }}</span
            ><textarea
                v-model="form.success_criterion"
                required
                maxlength="2000"
                rows="3"
            /></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{ tr("Goal status") }}</span
            ><select v-model="form.status">
                <option
                    v-for="(label, status) in options.goalStatuses"
                    :key="status"
                    :value="status"
                >
                    {{ label }}
                </option>
            </select></label
        >
        <p class="crm-muted">
            {{
                tr(
                    "Target and current results use the same unit. Results and status are entered manually.",
                )
            }}
        </p>
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
                          ? tr("Save goal")
                          : tr("Create goal")
                }}
            </button>
        </div>
    </form>
</template>
