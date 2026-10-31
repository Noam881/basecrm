<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { reactive } from "vue";
import { router, Link } from "@inertiajs/vue3";
import type { Options } from "../types";
const props = defineProps<
    Options & {
        endpoint: string;
        filters: Record<string, string>;
        customer?: boolean;
        supplier?: boolean;
    }
>();
const draft = reactive({
    owner_id: props.filters.owner_id ?? "",
    customer_relationship_id: props.filters.customer_relationship_id ?? "",
    supplier_relationship_id: props.filters.supplier_relationship_id ?? "",
    from: props.filters.from ?? "",
    to: props.filters.to ?? "",
    view: props.filters.view ?? "",
});
</script>
<template>
    <form
        class="crm-card crm-form-grid"
        @submit.prevent="router.get(endpoint, draft, { preserveState: true })"
    >
        <label class="crm-field"
            ><span class="crm-field-label">{{ tr("Owner") }}</span
            ><select v-model="draft.owner_id">
                <option value="">{{ tr("All accessible owners") }}</option>
                <option
                    v-for="person in owners"
                    :key="person.id"
                    :value="person.id"
                >
                    {{ person.name }}
                </option>
            </select></label
        ><label v-if="customer" class="crm-field"
            ><span class="crm-field-label">{{ tr("Customer") }}</span
            ><select v-model="draft.customer_relationship_id">
                <option value="">{{ tr("All accessible customers") }}</option>
                <option
                    v-for="person in customers"
                    :key="person.id"
                    :value="person.id"
                >
                    {{ person.name }}
                </option>
            </select></label
        ><label v-if="supplier" class="crm-field"
            ><span class="crm-field-label">{{ tr("Supplier") }}</span
            ><select v-model="draft.supplier_relationship_id">
                <option value="">{{ tr("All accessible suppliers") }}</option>
                <option
                    v-for="person in suppliers"
                    :key="person.id"
                    :value="person.id"
                >
                    {{ person.name }}
                </option>
            </select></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{ tr("Due from") }}</span
            ><input v-model="draft.from" type="date" /></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{ tr("Due to") }}</span
            ><input
                v-model="draft.to"
                type="date"
                :min="draft.from || undefined"
        /></label>
        <div class="crm-form-action">
            <button class="crm-button">{{ tr("Apply filters") }}</button
            ><Link class="crm-secondary" :href="endpoint">{{
                tr("Clear filters")
            }}</Link>
        </div>
    </form>
</template>
