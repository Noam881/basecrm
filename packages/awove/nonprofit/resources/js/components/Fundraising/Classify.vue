<script setup lang="ts">
import { useForm, usePage, router } from "@inertiajs/vue3";
import { computed } from "vue";
import IdentityClassificationFields from "../../../../../crm-core/resources/js/components/IdentityClassificationFields.vue";
import FormErrors from "./Errors.vue";
import type { Options } from "./types";
defineOptions({ inheritAttrs: false });
const props = withDefaults(defineProps<Options & { roles?: string[] }>(), {
    roles: () => ["donor", "partner", "prospect"],
});
const form = useForm({
    contact_id: null as number | null,
    company_id: null as number | null,
    role: "donor",
    responsible_id: props.currentOwnerId as number | null,
    main_contact_id: null as number | null,
    active: true,
    notes: "",
});
const canAddIdentity = computed(() =>
    (usePage().props.capabilities as string[]).includes("leads.create"),
);
function save() {
    form.post("/nonprofit/fundraising/classifications", {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
function refresh() {
    router.reload({
        only: ["contacts", "companies", "relationships"],
    });
}
</script>
<template>
    <section v-if="canClassify" class="crm-card crm-flow">
        <details class="crm-form-disclosure">
            <summary>Classify an existing contact or company</summary>
            <form class="crm-form-grid" @submit.prevent="save">
                <IdentityClassificationFields
                    :model-value="form"
                    :contacts="contacts"
                    :companies="companies"
                    :owners="classificationOwners"
                    :can-assign="canAssignClassification"
                    :roles="roles"
                    :errors="form.errors"
                />
                <FormErrors :errors="form.errors" />
                <div class="crm-form-action">
                    <button class="crm-button" :disabled="form.processing">
                        Add {{ form.role }} classification
                    </button>
                    <a
                        v-if="canAddIdentity"
                        class="crm-secondary"
                        href="/people"
                        target="_blank"
                        rel="noopener noreferrer"
                        >Add new contact or company</a
                    >
                    <button
                        class="crm-secondary"
                        type="button"
                        @click="refresh"
                    >
                        Refresh available identities
                    </button>
                </div>
            </form>
        </details>
    </section>
</template>
