<script setup lang="ts">
import { Head, Link, router } from "@inertiajs/vue3";
import { reactive, computed } from "vue";
import NonprofitNav from "../../../components/NonprofitNav.vue";
import ProgramTabs from "../../../components/Programs/ProgramTabs.vue";
import Editor from "../../../components/Programs/Editor.vue";
import {
    titles,
    singular,
    exportUrl,
    type Options,
    type Kind,
} from "../../../components/Programs/types";
import DataTable from "../../../../../../crm-core/resources/js/components/table/DataTable.vue";
import type { TablePayload } from "../../../../../../crm-core/resources/js/components/table/types";
const p = defineProps<
    Options & {
        section: Kind;
        table: TablePayload;
        filters: Record<string, string>;
        canExport: boolean;
        summaries:
            | {
                  program: string;
                  location: string;
                  present: number;
                  absent: number;
                  excused: number;
              }[]
            | null;
    }
>();
const f = reactive({
    view: p.filters.view ?? "all",
    program_id: p.filters.program_id ?? "",
    location_id: p.filters.location_id ?? "",
    group_id: p.filters.group_id ?? "",
    owner_id: p.filters.owner_id ?? "",
    from: p.filters.from ?? "",
    to: p.filters.to ?? "",
});
const canCreateHere = computed(() =>
    p.section === "participants" ? p.canCreateParticipants : p.canCreate,
);
const rootKind = computed(() =>
    ["programs", "participants", "locations", "groups"].includes(p.section),
);
const title = computed(() => titles[p.section]);
function apply() {
    router.get(
        "/nonprofit/programs",
        { ...f, section: p.section },
        { preserveState: true, preserveScroll: true },
    );
}
</script>
<template>
    <Head :title="title" />
    <div class="crm-heading">
        <h1>{{ title }}</h1>
        <a
            v-if="canExport"
            class="crm-secondary"
            :href="exportUrl(section, filters, table.state)"
            >Export CSV</a
        >
    </div>
    <div class="crm-flow">
        <NonprofitNav :sections="sections" /><ProgramTabs
            :can-view-programs="canViewPrograms"
            :can-view-participants="canViewParticipants"
        />
        <form class="crm-card crm-form" @submit.prevent="apply">
            <div class="crm-form-grid">
                <label class="crm-field"
                    ><span class="crm-field-label">View</span
                    ><select v-model="f.view" aria-label="View">
                        <option value="all">All current records</option>
                        <option v-if="rootKind" value="active">Active</option>
                        <option v-if="rootKind" value="inactive">
                            Inactive
                        </option>
                        <option value="archived">Archived</option>
                        <option v-if="section === 'feedback'" value="due">
                            Follow-ups due
                        </option>
                        <option v-if="section === 'feedback'" value="completed">
                            Follow-ups completed
                        </option>
                    </select></label
                ><label v-if="canViewPrograms" class="crm-field"
                    ><span class="crm-field-label">Program filter</span
                    ><select v-model="f.program_id" aria-label="Program filter">
                        <option value="">All programs</option>
                        <option v-for="r in programs" :key="r.id" :value="r.id">
                            {{ r.name }}
                        </option>
                    </select></label
                ><label v-if="canViewPrograms" class="crm-field"
                    ><span class="crm-field-label">Location filter</span
                    ><select
                        v-model="f.location_id"
                        aria-label="Location filter"
                    >
                        <option value="">All locations</option>
                        <option
                            v-for="r in locations"
                            :key="r.id"
                            :value="r.id"
                        >
                            {{ r.name }}
                        </option>
                    </select></label
                ><label v-if="canViewPrograms" class="crm-field"
                    ><span class="crm-field-label">Group filter</span
                    ><select v-model="f.group_id" aria-label="Group filter">
                        <option value="">All groups</option>
                        <option v-for="r in groups" :key="r.id" :value="r.id">
                            {{ r.name }}
                        </option>
                    </select></label
                ><label class="crm-field"
                    ><span class="crm-field-label"
                        >Responsible staff filter</span
                    ><select
                        v-model="f.owner_id"
                        aria-label="Responsible staff filter"
                    >
                        <option value="">All visible staff</option>
                        <option
                            v-for="r in section === 'participants'
                                ? participantOwners
                                : owners"
                            :key="r.id"
                            :value="r.id"
                        >
                            {{ r.name }}
                        </option>
                    </select></label
                ><label class="crm-field"
                    ><span class="crm-field-label">From date</span
                    ><input v-model="f.from" type="date" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">To date</span
                    ><input
                        v-model="f.to"
                        type="date"
                        :min="f.from || undefined"
                /></label>
            </div>
            <p class="crm-muted">
                {{
                    section === "attendance" || section === "sessions"
                        ? "Dates filter session dates."
                        : section === "programs"
                          ? "Dates filter program start dates."
                          : section === "feedback"
                            ? "Dates filter feedback dates."
                            : "Dates filter record creation dates."
                }}
            </p>
            <div class="crm-form-action">
                <button class="crm-button">Apply filters</button>
            </div>
        </form>
        <section v-if="canCreateHere && rootKind" class="crm-card">
            <details class="crm-form-disclosure">
                <summary>Add {{ singular[section] }}</summary>
                <Editor
                    v-bind="p"
                    :kind="
                        section as
                            'programs' | 'participants' | 'locations' | 'groups'
                    "
                />
            </details>
        </section>
        <p
            v-if="canCreateHere && ['sessions', 'feedback'].includes(section)"
            class="crm-notice"
        >
            Open a
            <Link href="/nonprofit/programs?section=programs">program</Link> to
            add {{ section === "sessions" ? "a session" : "feedback" }}.
        </p>
        <section v-if="summaries !== null" class="crm-card crm-flow">
            <h2>Attendance summary</h2>
            <p class="crm-muted">
                Recorded attendance within the selected period, programs and
                locations, limited to participants you can access.
            </p>
            <p v-if="!summaries.length">
                No recorded attendance in this selection.
            </p>
            <article
                v-for="(row, i) in summaries"
                :key="i"
                class="crm-note crm-flow"
            >
                <h3>{{ row.program }} · {{ row.location }}</h3>
                <dl class="crm-detail-grid">
                    <div
                        v-for="status in [
                            'present',
                            'absent',
                            'excused',
                        ] as const"
                        :key="status"
                        class="crm-detail-item"
                    >
                        <dt>{{ statuses[status] }}</dt>
                        <dd>{{ row[status] }}</dd>
                    </div>
                </dl>
            </article>
        </section>
        <DataTable
            :payload="table"
            endpoint="/nonprofit/programs"
            :title="title"
            :base-query="{ ...filters, section }"
        />
    </div>
</template>
