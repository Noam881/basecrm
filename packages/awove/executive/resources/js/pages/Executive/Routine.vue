<script setup lang="ts">
import WorkReferences, {
    type WorkReference,
} from "../../../../../crm-core/resources/js/components/WorkReferences.vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import RoutineEditor from "../../components/RoutineEditor.vue";
import HistoryList from "../../components/History.vue";
import RoutineCompletionFields, {
    type RoutineCompletionDraft,
} from "../../../../../crm-core/resources/js/components/RoutineCompletionFields.vue";
import type { Routine, History, Options, Completion } from "../../types";
const props = defineProps<
    Options & {
        record: Routine;
        editable: boolean;
        history: History[];
        instructionLinks: WorkReference[];
        completions: Completion[];
    }
>();
const form = useForm({
    version: props.record.version,
    completed_on: props.today,
    next_due_on: "",
    checked_items: [] as string[],
});
const draft = computed<RoutineCompletionDraft>({
    get: () => form,
    set: (value) => Object.assign(form, value),
});
watch(
    () => props.record.version,
    (value) => (form.version = value),
);
function complete() {
    form.post(`/executive/routines/${props.record.id}/complete`, {
        preserveScroll: true,
        onSuccess: () => form.reset("next_due_on"),
    });
}
</script>
<template>
    <Head :title="record.name" /><Link
        href="/executive?section=routines"
        class="crm-back"
        >← Back to routines</Link
    >
    <div class="crm-heading">
        <h1>{{ record.name }}</h1>
        <button
            v-if="editable && canArchive"
            class="crm-secondary"
            @click="
                router.post(`/executive/routines/${record.id}/archive`, {
                    version: record.version,
                })
            "
        >
            Archive routine
        </button>
    </div>
    <div class="crm-stack">
        <section class="crm-card crm-flow">
            <h2>Routine details</h2>
            <p>
                Responsible person:
                {{
                    owners.find((owner) => owner.id === record.owner_id)
                        ?.name ?? "Unassigned"
                }}
            </p>
            <RoutineEditor
                v-if="editable"
                :key="record.version"
                :options="props"
                :record="record"
            />
            <dl v-else class="crm-detail-grid">
                <div>
                    <dt>Frequency</dt>
                    <dd>{{ record.frequency }}</dd>
                </div>
                <div>
                    <dt>Next due date</dt>
                    <dd>{{ record.next_due_on ?? "Not scheduled" }}</dd>
                </div>
            </dl>
        </section>
        <section v-if="editable" class="crm-card crm-flow">
            <h2>Complete scheduled occurrence</h2>
            <form
                v-if="record.next_due_on"
                class="crm-form-grid"
                @submit.prevent="complete"
            >
                <p>
                    Scheduled for {{ record.next_due_on }}. Choose the next date
                    yourself; leaving it blank leaves the routine unscheduled.
                </p>
                <RoutineCompletionFields
                    v-model="draft"
                    :checklist="record.checklist"
                    :errors="form.errors"
                    :show-checklist="record.checklist.length > 0"
                />
                <div
                    v-if="Object.keys(form.errors).length"
                    class="crm-form-action crm-error"
                    role="alert"
                >
                    <p v-for="(error, key) in form.errors" :key="key">
                        {{ error }}
                    </p>
                </div>
                <div class="crm-form-action">
                    <button class="crm-button" :disabled="form.processing">
                        {{
                            form.processing
                                ? "Recording…"
                                : "Complete occurrence"
                        }}
                    </button>
                </div>
            </form>
            <p v-else class="crm-muted">
                Set the next due date in the routine details to schedule another
                occurrence.
            </p>
        </section>
        <section class="crm-card crm-flow">
            <h2>Completed occurrences</h2>
            <p v-if="!completions.length" class="crm-muted">
                No occurrences completed yet.
            </p>
            <article
                v-for="entry in completions"
                :key="entry.id"
                class="crm-note"
            >
                <strong>Completed {{ entry.completed_on }}</strong>
                <p>
                    Scheduled for {{ entry.occurrence_due_on }} ·
                    {{ entry.actor?.name ?? "Former team member" }}
                </p>
            </article>
        </section>
        <WorkReferences :links="instructionLinks" />
        <HistoryList :history="history" />
    </div>
</template>
