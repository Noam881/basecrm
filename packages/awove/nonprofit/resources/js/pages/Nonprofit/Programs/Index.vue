<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
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
const title = computed(() => tr(titles[p.section]));
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
            >{{ tr("Export CSV") }}</a
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
                    ><span class="crm-field-label">{{ tr("View") }}</span
                    ><select v-model="f.view" :aria-label="tr('View')">
                        <option value="all">
                            {{ tr("All current records") }}
                        </option>
                        <option v-if="rootKind" value="active">
                            {{ tr("Active") }}
                        </option>
                        <option v-if="rootKind" value="inactive">
                            {{ tr("Inactive") }}
                        </option>
                        <option value="archived">{{ tr("Archived") }}</option>
                        <option v-if="section === 'feedback'" value="due">
                            {{ tr("Follow-ups due") }}
                        </option>
                        <option v-if="section === 'feedback'" value="completed">
                            {{ tr("Follow-ups completed") }}
                        </option>
                    </select></label
                ><label v-if="canViewPrograms" class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Program filter")
                    }}</span
                    ><select
                        v-model="f.program_id"
                        :aria-label="tr('Program filter')"
                    >
                        <option value="">{{ tr("All programs") }}</option>
                        <option v-for="r in programs" :key="r.id" :value="r.id">
                            {{ r.name }}
                        </option>
                    </select></label
                ><label v-if="canViewPrograms" class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Location filter")
                    }}</span
                    ><select
                        v-model="f.location_id"
                        :aria-label="tr('Location filter')"
                    >
                        <option value="">{{ tr("All locations") }}</option>
                        <option
                            v-for="r in locations"
                            :key="r.id"
                            :value="r.id"
                        >
                            {{ r.name }}
                        </option>
                    </select></label
                ><label v-if="canViewPrograms" class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Group filter")
                    }}</span
                    ><select
                        v-model="f.group_id"
                        :aria-label="tr('Group filter')"
                    >
                        <option value="">{{ tr("All groups") }}</option>
                        <option v-for="r in groups" :key="r.id" :value="r.id">
                            {{ r.name }}
                        </option>
                    </select></label
                ><label class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Responsible staff filter")
                    }}</span
                    ><select
                        v-model="f.owner_id"
                        :aria-label="tr('Responsible staff filter')"
                    >
                        <option value="">{{ tr("All visible staff") }}</option>
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
                    ><span class="crm-field-label">{{ tr("From date") }}</span
                    ><input v-model="f.from" type="date" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">{{ tr("To date") }}</span
                    ><input
                        v-model="f.to"
                        type="date"
                        :min="f.from || undefined"
                /></label>
            </div>
            <p class="crm-muted">
                {{
                    section === "attendance" || section === "sessions"
                        ? tr("Dates filter session dates.")
                        : section === "programs"
                          ? tr("Dates filter program start dates.")
                          : section === "feedback"
                            ? tr("Dates filter feedback dates.")
                            : tr("Dates filter record creation dates.")
                }}
            </p>
            <div class="crm-form-action">
                <button class="crm-button">{{ tr("Apply filters") }}</button>
            </div>
        </form>
        <section v-if="canCreateHere && rootKind" class="crm-card">
            <details class="crm-form-disclosure">
                <summary>{{ tr("Add") }} {{ singular[section] }}</summary>
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
            {{ tr("Open a") }}
            <Link href="/nonprofit/programs?section=programs">{{
                tr("program")
            }}</Link>
            {{ tr("to add") }}
            {{ section === "sessions" ? tr("a session") : tr("feedback") }}.
        </p>
        <section v-if="summaries !== null" class="crm-card crm-flow">
            <h2>{{ tr("Attendance summary") }}</h2>
            <p class="crm-muted">
                {{
                    tr(
                        "Recorded attendance within the selected period, programs and locations, limited to participants you can access.",
                    )
                }}
            </p>
            <p v-if="!summaries.length">
                {{ tr("No recorded attendance in this selection.") }}
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
