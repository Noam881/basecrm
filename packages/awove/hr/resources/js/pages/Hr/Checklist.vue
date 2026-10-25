<script setup lang="ts">
import WorkReferences, {
    type WorkReference,
} from "../../../../../crm-core/resources/js/components/WorkReferences.vue";
import { Head, Link } from "@inertiajs/vue3";
import ChecklistForm from "../../components/ChecklistForm.vue";
import HistoryList from "../../components/History.vue";
import type { Options, ChecklistItem, History } from "../../types";
const props = defineProps<
    Options & {
        record: ChecklistItem;
        parentCase: { id: number; name: string } | null;
        editable: boolean;
        canEditMetadata: boolean;
        history: History[];
        instructionLinks: WorkReference[];
    }
>();
</script>
<template>
    <Head :title="record.title" /><Link
        v-if="parentCase"
        class="crm-back"
        :href="'/hr/cases/' + parentCase.id"
        >← {{ parentCase.name }}</Link
    ><Link v-else class="crm-back" href="/hr?section=cases">← Back to HR</Link>
    <h1>{{ record.title }}</h1>
    <div class="crm-flow">
        <p v-if="!parentCase" class="crm-muted">
            The related HR case is restricted. Its identity and details remain
            hidden.
        </p>
        <section class="crm-card crm-flow">
            <h2>Checklist item</h2>
            <ChecklistForm
                v-if="editable"
                :key="record.version"
                :options="props"
                :record="record"
                :can-edit-metadata="canEditMetadata"
            />
            <dl v-else class="crm-detail-grid">
                <div>
                    <dt>Description</dt>
                    <dd>{{ record.description ?? "—" }}</dd>
                </div>
                <div>
                    <dt>Status</dt>
                    <dd>
                        {{ checklistStatuses[record.status] ?? record.status }}
                    </dd>
                </div>
                <div>
                    <dt>Due date</dt>
                    <dd>{{ record.due_on }}</dd>
                </div>
                <div>
                    <dt>Completion date</dt>
                    <dd>{{ record.completed_on ?? "—" }}</dd>
                </div>
            </dl>
        </section>
        <WorkReferences :links="instructionLinks" />
        <HistoryList :history="history" />
    </div>
</template>
