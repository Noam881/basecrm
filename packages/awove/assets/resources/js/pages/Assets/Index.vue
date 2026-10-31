<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { Head, Link } from "@inertiajs/vue3";
import { computed } from "vue";
import DataTable from "../../../../../crm-core/resources/js/components/table/DataTable.vue";
import type { TablePayload } from "../../../../../crm-core/resources/js/components/table/types";
import AssetsNav from "../../components/AssetsNav.vue";
import AssetFilters from "../../components/AssetFilters.vue";
import AssetEditor from "../../components/AssetEditor.vue";
import {
    exportUrl,
    type Options,
    type Section,
    type Metric,
} from "../../types";
const p = defineProps<
    Options & {
        section: Section;
        table: TablePayload;
        filters: Record<string, string>;
        metrics: Metric[];
    }
>();
const sectionTitle = computed(() =>
    p.section === "register"
        ? p.moduleLabel
        : p.section === "assignments"
          ? tr("Assignments")
          : tr("Maintenance"),
);
const title = computed(() =>
    p.section === "register"
        ? p.moduleLabel
        : `${p.moduleLabel} · ${sectionTitle.value}`,
);
</script>
<template>
    <Head :title="title" />
    <div class="crm-heading">
        <h1>{{ title }}</h1>
        <a
            v-if="canExport"
            class="crm-secondary"
            :href="exportUrl(section, filters, table.state)"
            >{{ tr("Export CSV") }}</a
        >
    </div>
    <div class="crm-flow">
        <AssetsNav :sections="sections" :module-label="moduleLabel" />
        <div v-if="metrics.length" class="crm-metrics">
            <Link
                v-for="metric in metrics"
                :key="metric.id"
                class="crm-card"
                :href="metric.href"
                ><span>{{ tr(metric.label) }}</span
                ><strong>{{ metric.value }}</strong></Link
            >
        </div>
        <AssetFilters v-bind="p" />
        <section v-if="section === 'register' && canCreate" class="crm-card">
            <details class="crm-form-disclosure">
                <summary>{{ tr("Add asset") }}</summary>
                <AssetEditor v-bind="p" />
            </details>
        </section>
        <DataTable
            :payload="table"
            endpoint="/assets"
            :title="sectionTitle"
            :base-query="{ ...filters, section }"
        />
    </div>
</template>
