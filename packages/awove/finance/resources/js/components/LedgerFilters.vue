<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { reactive } from "vue";
import { router, Link } from "@inertiajs/vue3";
import type { Options } from "../types";
const props = defineProps<Options & { filters: Record<string, string> }>();
const draft = reactive({
    owner_id: props.filters.owner_id ?? "",
    relationship_id: props.filters.relationship_id ?? "",
    from: props.filters.from ?? "",
    to: props.filters.to ?? "",
    view: props.filters.view ?? "",
});
</script>
<template>
    <form
        class="crm-card crm-form-grid"
        @submit.prevent="
            router.get(`/finance/${cap}`, draft, { preserveState: true })
        "
    >
        <label class="crm-field"
            ><span class="crm-field-label">{{ tr("Owner") }}</span
            ><select v-model="draft.owner_id">
                <option value="">{{ tr("All accessible owners") }}</option>
                <option
                    v-for="owner in owners"
                    :key="owner.id"
                    :value="owner.id"
                >
                    {{ owner.name }}
                </option>
            </select></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{
                cap === "collections" ? tr("Customer") : tr("Supplier")
            }}</span
            ><select v-model="draft.relationship_id">
                <option value="">
                    {{ tr("All accessible classifications") }}
                </option>
                <option v-for="r in relationships" :key="r.id" :value="r.id">
                    {{ r.name }}
                </option>
            </select></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{ tr("Due from") }}</span
            ><input v-model="draft.from" type="date" /></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{ tr("Due to") }}</span
            ><input v-model="draft.to" type="date"
        /></label>
        <div class="crm-form-action">
            <button class="crm-button">{{ tr("Apply filters") }}</button
            ><Link class="crm-secondary" :href="`/finance/${cap}`">{{
                tr("Clear filters")
            }}</Link>
        </div>
    </form>
</template>
