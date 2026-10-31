<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { useForm, usePage } from "@inertiajs/vue3";
import IdentityClassificationFields from "../../../../crm-core/resources/js/components/IdentityClassificationFields.vue";
import type { Classification, Options, Person } from "../types";
defineOptions({ inheritAttrs: false });
const props = defineProps<
    Options & {
        record?: Classification;
        contacts: (Person & { company_id?: number | null })[];
        companies: Person[];
        classificationOwners: Person[];
        canAssignClassification: boolean;
    }
>();
const form = useForm({
    contact_id: props.record?.contact_id ?? null,
    company_id: props.record?.company_id ?? null,
    role: props.record?.role ?? "customer",
    responsible_id: props.record
        ? props.record.responsible_id
        : (usePage().props.auth as { user: { id: number } }).user.id,
    main_contact_id: props.record?.main_contact_id ?? null,
    active: props.record?.active ?? true,
    notes: props.record?.notes ?? "",
    version: props.record?.version ?? 1,
});
function save() {
    if (props.record)
        form.transform(
            ({
                contact_id: _contact,
                company_id: _company,
                role: _role,
                ...data
            }) => {
                if (
                    props.record?.has_hidden_main_contact &&
                    data.main_contact_id === null
                ) {
                    const { main_contact_id: _main, ...preserved } = data;
                    return preserved;
                }
                return data;
            },
        ).patch(`/operations/directory/${props.record.id}`, {
            preserveScroll: true,
        });
    else
        form.transform(({ version: _version, ...data }) => data).post(
            "/operations/directory",
        );
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <IdentityClassificationFields
            :model-value="form"
            :contacts="contacts"
            :companies="companies"
            :owners="classificationOwners"
            :can-assign="canAssignClassification"
            :locked-identity="!!record"
            :roles="['customer', 'supplier']"
            :errors="form.errors"
        />
        <div class="crm-form-action">
            <button class="crm-button" :disabled="form.processing">
                {{
                    form.processing
                        ? tr("Saving…")
                        : record
                          ? tr("Save classification")
                          : tr("Add classification")
                }}
            </button>
        </div>
    </form>
</template>
