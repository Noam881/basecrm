<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { Head, Link, router } from "@inertiajs/vue3";
import { reactive, ref } from "vue";
import DataTable from "../../../../../crm-core/resources/js/components/table/DataTable.vue";
import { queryString } from "../../../../../crm-core/resources/js/components/table/queryString";
import type { TablePayload } from "../../../../../crm-core/resources/js/components/table/types";
import GoalEditor from "../../components/GoalEditor.vue";
import TaskEditor from "../../components/TaskEditor.vue";
import RoutineEditor from "../../components/RoutineEditor.vue";
import type { Options } from "../../types";
const props = defineProps<
    Options & {
        section: "tasks" | "goals" | "routines";
        table: TablePayload;
        metrics: Record<string, number>;
        filters: Record<string, string>;
    }
>();
const create = ref(false);
const filters = reactive({
    section: props.section,
    owner_id: props.filters.owner_id ?? "",
    priority: props.filters.priority ?? "",
    from: props.filters.from ?? "",
    to: props.filters.to ?? "",
    view: props.filters.view ?? "",
});
function apply() {
    router.get("/executive", filters, { preserveState: false });
}
function exportUrl() {
    return (
        "/executive/export?" +
        queryString({
            ...props.filters,
            section: props.section,
            ...props.table.state,
        })
    );
}
</script>
<template>
    <Head :title="tr('Executive management')" />
    <div class="crm-heading">
        <h1>{{ tr("Executive management") }}</h1>
        <div class="crm-form-action">
            <a v-if="canExport" :href="exportUrl()" class="crm-secondary"
                >{{ tr("Export") }} {{ tr(section) }} {{ tr("(CSV)") }}</a
            ><button
                v-if="canCreate"
                class="crm-button"
                @click="create = !create"
            >
                {{
                    create
                        ? tr("Close form")
                        : section === "goals"
                          ? tr("Add goal")
                          : section === "routines"
                            ? tr("Add routine")
                            : tr("Add task")
                }}
            </button>
        </div>
    </div>
    <div class="crm-flow">
        <nav class="crm-tabs" :aria-label="tr('Executive sections')">
            <Link
                href="/executive?section=tasks"
                :aria-current="section === 'tasks' ? 'page' : undefined"
                >{{ tr("Tasks") }}</Link
            ><Link
                href="/executive?section=goals"
                :aria-current="section === 'goals' ? 'page' : undefined"
                >{{ tr("Goals") }}</Link
            ><Link
                href="/executive?section=routines"
                :aria-current="section === 'routines' ? 'page' : undefined"
                >{{ tr("Routines") }}</Link
            >
        </nav>
        <div class="crm-metrics">
            <div
                v-for="(label, key) in {
                    open_tasks: 'Open tasks',
                    overdue_tasks: 'Overdue tasks',
                    blocked_tasks: 'Blocked tasks',
                    active_goals: 'Active goals',
                    upcoming_routines: 'Routines due in seven days',
                }"
                :key="key"
                class="crm-card"
            >
                <span>{{ tr(label) }}</span
                ><strong>{{ metrics[key] }}</strong>
            </div>
        </div>
        <form class="crm-card crm-form-grid" @submit.prevent="apply">
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Owner filter") }}</span
                ><select v-model="filters.owner_id">
                    <option value="">{{ tr("All accessible owners") }}</option>
                    <option
                        v-for="owner in filterOwners"
                        :key="owner.id"
                        :value="owner.id"
                    >
                        {{ owner.name }}
                    </option>
                </select></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{
                    tr("Priority filter (tasks)")
                }}</span
                ><select v-model="filters.priority">
                    <option value="">{{ tr("All priorities") }}</option>
                    <option value="low">{{ tr("Low") }}</option>
                    <option value="normal">{{ tr("Normal") }}</option>
                    <option value="high">{{ tr("High") }}</option>
                </select></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("From date") }}</span
                ><input v-model="filters.from" type="date" /></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("To date") }}</span
                ><input
                    v-model="filters.to"
                    type="date"
                    :min="filters.from || undefined" /></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("View") }}</span
                ><select v-model="filters.view">
                    <option value="">{{ tr("All active records") }}</option>
                    <option value="open">{{ tr("Open") }}</option>
                    <option value="overdue">{{ tr("Overdue") }}</option>
                    <option v-if="section === 'tasks'" value="blocked">
                        {{ tr("Blocked") }}
                    </option>
                    <option value="archived">{{ tr("Archived") }}</option>
                </select></label
            >
            <p class="crm-muted">
                {{
                    tr(
                        "Period filters use task deadlines, goal target dates and the manually scheduled next routine date. Priority applies to tasks.",
                    )
                }}
            </p>
            <div class="crm-form-action">
                <button class="crm-button">{{ tr("Apply filters") }}</button
                ><Link
                    :href="`/executive?section=${section}`"
                    class="crm-secondary"
                    >{{ tr("Clear filters") }}</Link
                >
            </div>
        </form>
        <section v-if="create && canCreate" class="crm-card crm-flow">
            <h2>
                {{
                    section === "goals"
                        ? tr("New goal")
                        : section === "routines"
                          ? tr("New routine")
                          : tr("New management task")
                }}
            </h2>
            <GoalEditor
                v-if="section === 'goals'"
                :options="props"
            /><RoutineEditor
                v-else-if="section === 'routines'"
                :options="props"
            /><TaskEditor v-else :options="props" />
        </section>
        <DataTable
            :payload="table"
            endpoint="/executive"
            :base-query="{ ...props.filters, section }"
            :title="section.charAt(0).toUpperCase() + section.slice(1)"
        />
    </div>
</template>
