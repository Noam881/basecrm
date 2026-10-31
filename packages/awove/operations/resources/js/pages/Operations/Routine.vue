<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import WorkReferences, {
    type WorkReference,
} from "../../../../../crm-core/resources/js/components/WorkReferences.vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import OperationsNav from "../../components/OperationsNav.vue";
import RoutineEditor from "../../components/RoutineEditor.vue";
import RoutineCompletionFields from "../../../../../crm-core/resources/js/components/RoutineCompletionFields.vue";
import HistoryList from "../../components/HistoryList.vue";
import type { Options, Routine, Completion, History } from "../../types";
const props = defineProps<
    Options & {
        record: Routine;
        completions: Completion[];
        history: History[];
        instructionLinks: WorkReference[];
    }
>();
const completion = useForm({
    version: props.record.version,
    completed_on: props.today,
    next_due_on: "",
    checked_items: [] as string[],
});
function complete() {
    completion.version = props.record.version;
    completion.post(`/operations/routines/${props.record.id}/complete`, {
        preserveScroll: true,
        onSuccess: () => completion.reset("next_due_on", "checked_items"),
    });
}
function archive() {
    if (confirm(tr("Archive this routine and retain completion history?")))
        router.post(`/operations/routines/${props.record.id}/archive`, {
            version: props.record.version,
        });
}
</script>
<template>
    <Head :title="record.name" /><Link
        class="crm-back"
        href="/operations/routines"
        >{{ tr("← Operational routines") }}</Link
    >
    <div class="crm-heading">
        <h1>{{ record.name }}</h1>
    </div>
    <div class="crm-flow">
        <OperationsNav :can-view-directory="canViewDirectory" />
        <section class="crm-card crm-flow">
            <p>
                {{ tr("Owner:") }} {{ record.owner?.name ?? tr("Unassigned") }}
            </p>
            <p>{{ tr("Frequency:") }} {{ record.frequency }}</p>
            <p>
                {{ tr("Next occurrence:") }}
                {{ record.next_due_on ?? tr("Not scheduled") }}
            </p>
            <p v-if="record.archived_at">
                {{ tr("Archived · retained read-only record.") }}
            </p>
        </section>
        <section v-if="record.editable" class="crm-card crm-flow">
            <h2>{{ tr("Edit routine") }}</h2>
            <RoutineEditor :key="record.version" v-bind="props" />
            <div v-if="canArchive" class="crm-form-action">
                <button class="crm-secondary" @click="archive">
                    {{ tr("Archive routine") }}
                </button>
            </div>
        </section>
        <section
            v-if="record.editable && record.next_due_on"
            class="crm-card crm-flow"
            :aria-label="tr('Complete occurrence')"
        >
            <h2>{{ tr("Complete occurrence") }}</h2>
            <form class="crm-form-grid" @submit.prevent="complete">
                <RoutineCompletionFields
                    :model-value="completion"
                    :checklist="record.checklist"
                    :errors="completion.errors"
                />
                <div class="crm-form-action">
                    <button
                        class="crm-button"
                        :disabled="completion.processing"
                    >
                        {{
                            completion.processing
                                ? tr("Saving…")
                                : tr("Complete occurrence")
                        }}
                    </button>
                </div>
            </form>
        </section>
        <section class="crm-card crm-flow">
            <h2>{{ tr("Completed occurrences") }}</h2>
            <p v-if="!completions.length">
                {{ tr("No occurrences completed.") }}
            </p>
            <article v-for="entry in completions" :key="entry.id">
                <strong
                    >{{ tr("Due") }} {{ entry.occurrence_due_on }}
                    {{ tr("· completed") }} {{ entry.completed_on }}</strong
                >
                <ul v-if="entry.checklist_snapshot.length">
                    <li
                        v-for="(item, index) in entry.checklist_snapshot"
                        :key="index"
                    >
                        {{ item.completed ? "✓" : "○" }} {{ item.label }}
                    </li>
                </ul>
                <small>{{ entry.actor?.name ?? tr("Former staff") }}</small>
            </article>
        </section>
        <WorkReferences :links="instructionLinks" />
        <HistoryList :history="history" />
    </div>
</template>
