<script setup lang="ts">
import { t as tr, translateLabels } from "@/lib/i18n";
import { Head, Link, useForm } from "@inertiajs/vue3";
import NonprofitNav from "../../../components/NonprofitNav.vue";
import WorkEditor from "../../../components/Projects/WorkEditor.vue";
import Errors from "../../../components/Projects/Errors.vue";
import type {
    Options,
    WorkRecord,
    History,
} from "../../../components/Projects/types";
import HistoryList from "../../../../../../crm-core/resources/js/components/HistoryList.vue";
import WorkReferences, {
    type WorkReference,
} from "../../../../../../crm-core/resources/js/components/WorkReferences.vue";
const p = defineProps<
    Options & {
        record: WorkRecord;
        history: History[];
        instructionLinks: WorkReference[];
    }
>();
const archive = useForm({
    version: p.record.version,
    project_version: p.record.project.version,
});
function remove() {
    archive.version = p.record.version;
    archive.project_version = p.record.project.version;
    archive.post(`/nonprofit/${p.record.kind}/${p.record.id}/archive`);
}
</script>
<template>
    <Head :title="record.title" /><Link
        class="crm-back"
        :href="`/nonprofit/projects/${record.project.id}`"
        >← {{ record.project.name }}</Link
    >
    <div class="crm-heading">
        <h1>{{ record.title }}</h1>
        <span class="crm-badge">{{
            record.archived_at ? tr("Archived") : workStatuses[record.status]
        }}</span>
    </div>
    <div class="crm-flow">
        <NonprofitNav :sections="sections" />
        <section class="crm-card">
            <WorkEditor
                v-bind="p"
                :project="record.project"
                :kind="record.kind"
            />
        </section>
        <p v-if="!record.editable" class="crm-notice">
            {{
                tr(
                    "This work is read only. The project and work item must both be active and editable to make changes.",
                )
            }}
        </p>
        <WorkReferences :links="instructionLinks" /><HistoryList
            :history="history"
            :field-labels="
                translateLabels({
                    'module:nonprofit:project_id': 'Project',
                })
            "
        />
        <form
            v-if="record.editable && canArchive"
            class="crm-card crm-form"
            @submit.prevent="remove"
        >
            <p>
                {{
                    tr(
                        "Archiving retains this work and its history without claiming completion.",
                    )
                }}
            </p>
            <Errors :errors="archive.errors" />
            <div class="crm-form-action">
                <button class="crm-secondary" :disabled="archive.processing">
                    {{ tr("Archive work") }}
                </button>
            </div>
        </form>
    </div>
</template>
