<script setup lang="ts">
import { t } from "@/lib/i18n";
import { Head, router, useForm } from "@inertiajs/vue3";
import { ref } from "vue";
import DataTable from "../../components/table/DataTable.vue";
import { queryString } from "../../components/table/queryString";
import type { TablePayload } from "../../components/table/types";
import EmployeeReferenceFields from "../../components/EmployeeReferenceFields.vue";
const props = defineProps<{
    table: TablePayload;
    filters: Record<string, string>;
    canManage: boolean;
    canExport: boolean;
    contacts: { id: number; name: string }[];
    accounts: { id: number; name: string }[];
}>();
const active = ref(props.filters.active ?? "");
const form = useForm({
    name: "",
    active: true,
    contact_id: null as number | null,
    user_id: null as number | null,
    contact_action: "keep",
    user_action: "keep",
});
function save() {
    form.post("/people/employees");
}
</script>
<template>
    <Head :title="t('Employee references')" />
    <div class="crm-heading">
        <h1>{{ t("Employee references") }}</h1>
        <a
            v-if="canExport"
            class="crm-secondary"
            :href="
                '/people/employees/export?' +
                queryString({ ...filters, ...table.state })
            "
            >{{ t("Export CSV") }}</a
        >
    </div>
    <div class="crm-flow">
        <form
            class="crm-card crm-form-grid"
            @submit.prevent="
                router.get('/people/employees', active ? { active } : {})
            "
        >
            <label class="crm-field"
                ><span class="crm-field-label">{{
                    t("Reference status filter")
                }}</span
                ><select v-model="active">
                    <option value="">{{ t("All references") }}</option>
                    <option value="1">{{ t("Active") }}</option>
                    <option value="0">{{ t("Inactive") }}</option>
                </select></label
            >
            <div class="crm-form-action">
                <button class="crm-button">{{ t("Apply filter") }}</button>
            </div>
        </form>
        <section v-if="canManage" class="crm-card">
            <details class="crm-form-disclosure">
                <summary>{{ t("Add employee reference") }}</summary>
                <form class="crm-form-grid" @submit.prevent="save">
                    <EmployeeReferenceFields
                        :model-value="form"
                        :contacts="contacts"
                        :accounts="accounts"
                        :errors="form.errors"
                    />
                    <p
                        v-if="form.hasErrors"
                        class="crm-error crm-form-span"
                        role="alert"
                    >
                        {{ Object.values(form.errors).join(" ") }}
                    </p>
                    <div class="crm-form-action">
                        <button class="crm-button" :disabled="form.processing">
                            {{
                                form.processing
                                    ? t("Saving…")
                                    : t("Create employee reference")
                            }}
                        </button>
                    </div>
                </form>
            </details>
        </section>
        <DataTable
            :payload="table"
            endpoint="/people/employees"
            :title="t('Employee references')"
            :base-query="filters"
        />
    </div>
</template>
