<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import { computed } from "vue";
import NonprofitNav from "../../../components/NonprofitNav.vue";
import ProjectForm from "../../../components/Projects/ProjectForm.vue";
import type { Options } from "../../../components/Projects/types";
import DataTable from "../../../../../../crm-core/resources/js/components/table/DataTable.vue";
import type { TablePayload } from "../../../../../../crm-core/resources/js/components/table/types";
import { queryString } from "../../../../../../crm-core/resources/js/components/table/queryString";
const p = defineProps<
    Options & {
        section: string;
        filters: Record<string, string>;
        table: TablePayload;
    }
>();
const title = computed(() =>
    p.section === "projects"
        ? "Projects"
        : p.section === "project-tasks"
          ? "Project tasks"
          : "Project milestones",
);
const csv = computed(
    () =>
        "/nonprofit/projects/export?" +
        queryString({ ...p.filters, section: p.section, ...p.table.state }),
);
</script>
<template>
    <Head :title="title" />
    <div class="crm-heading">
        <h1>{{ title }}</h1>
        <a v-if="canExport" class="crm-secondary" :href="csv">Export CSV</a>
    </div>
    <div class="crm-flow">
        <NonprofitNav :sections="sections" />
        <nav class="crm-tabs" aria-label="Projects navigation">
            <Link href="/nonprofit/projects">Projects</Link
            ><Link href="/nonprofit/projects?section=project-tasks">Tasks</Link
            ><Link href="/nonprofit/projects?section=project-milestones"
                >Milestones</Link
            >
        </nav>
        <section class="crm-card">
            <form
                class="crm-form-grid"
                method="get"
                action="/nonprofit/projects"
            >
                <input type="hidden" name="section" :value="section" /><label
                    class="crm-field"
                    ><span class="crm-field-label">View</span
                    ><select name="view" :value="filters.view ?? 'active'">
                        <option value="active">Active records</option>
                        <option value="overdue">Overdue</option>
                        <option value="archived">Archived</option>
                    </select></label
                ><label class="crm-field"
                    ><span class="crm-field-label">{{
                        section === "projects"
                            ? "Start date from"
                            : "Due date from"
                    }}</span
                    ><input
                        type="date"
                        name="from"
                        :value="filters.from" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">To date</span
                    ><input type="date" name="to" :value="filters.to"
                /></label>
                <div class="crm-form-action">
                    <button class="crm-secondary">Apply filters</button>
                </div>
            </form>
        </section>
        <section v-if="section === 'projects' && canCreate" class="crm-card">
            <details class="crm-form-disclosure">
                <summary>Add project</summary>
                <ProjectForm v-bind="p" />
            </details>
        </section>
        <p v-else-if="section !== 'projects'" class="crm-muted">
            Create tasks and milestones from their project profile.
        </p>
        <DataTable
            :payload="table"
            endpoint="/nonprofit/projects"
            :title="title"
            :base-query="{ ...filters, section }"
        />
    </div>
</template>
