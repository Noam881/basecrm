<script setup lang="ts">
import { t } from "@/lib/i18n";
import { Head } from "@inertiajs/vue3";
import DataTable from "../../../../../crm-core/resources/js/components/table/DataTable.vue";
import type { TablePayload } from "../../../../../crm-core/resources/js/components/table/types";
import FinanceNav from "../../components/FinanceNav.vue";
import type { Section } from "../../types";
defineProps<{ table: TablePayload; sections: Section[] }>();
</script>
<template>
    <Head :title="t('Financial history')" />
    <div class="crm-heading">
        <h1>{{ t("Financial history") }}</h1>
    </div>
    <div class="crm-flow">
        <FinanceNav :sections="sections" />
        <p class="crm-muted">
            {{
                t(
                    "Historical references are excluded from balances and reminders.",
                )
            }}
        </p>
        <DataTable
            :payload="table"
            endpoint="/finance/history"
            :title="t('Financial history')"
        />
    </div>
</template>
