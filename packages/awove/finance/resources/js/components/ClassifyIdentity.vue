<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { useForm, usePage } from "@inertiajs/vue3";
import IdentityClassificationFields from "../../../../crm-core/resources/js/components/IdentityClassificationFields.vue";
import type { Options } from "../types";
defineOptions({ inheritAttrs: false });
const props = defineProps<Options>();
const role = props.cap === "collections" ? "customer" : "supplier";
const form = useForm({
    contact_id: null as number | null,
    company_id: null as number | null,
    role,
    responsible_id: (usePage().props.auth as { user: { id: number } }).user.id,
    main_contact_id: null as number | null,
    active: true,
    notes: "",
});
</script>
<template>
    <details class="crm-form-disclosure">
        <summary>{{ tr("Classify an existing contact or company") }}</summary>
        <form
            class="crm-form-grid"
            @submit.prevent="
                form.post(`/finance/${cap}/classifications`, {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                })
            "
        >
            <IdentityClassificationFields
                :model-value="form"
                :contacts="contacts"
                :companies="companies"
                :owners="classificationOwners"
                :can-assign="canAssignClassification"
                :roles="[role]"
                :errors="form.errors"
            />
            <div class="crm-form-action">
                <button class="crm-button" :disabled="form.processing">
                    {{ tr("Add") }} {{ tr(role) }} {{ tr("classification") }}
                </button>
            </div>
        </form>
    </details>
</template>
