<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import WorkReferences, {
    type WorkReference,
} from "../../../../../crm-core/resources/js/components/WorkReferences.vue";
import { Head, Link, router } from "@inertiajs/vue3";
import TaskEditor from "../../components/TaskEditor.vue";
import HistoryList from "../../components/History.vue";
import type { Task, History, Options } from "../../types";
const props = defineProps<
    Options & {
        record: Task;
        editable: boolean;
        history: History[];
        instructionLinks: WorkReference[];
        goalHistory: History[];
    }
>();
</script>
<template>
    <Head :title="record.title" /><Link
        href="/executive?section=tasks"
        class="crm-back"
        >{{ tr("← Back to management tasks") }}</Link
    >
    <div class="crm-heading">
        <h1>{{ record.title }}</h1>
        <button
            v-if="editable && canArchive"
            class="crm-secondary"
            @click="
                router.post(`/executive/tasks/${record.id}/archive`, {
                    version: record.version,
                })
            "
        >
            {{ tr("Archive task") }}
        </button>
    </div>
    <div class="crm-stack">
        <section class="crm-card crm-flow">
            <h2>{{ tr("Management task") }}</h2>
            <p>
                {{ tr("Owner:") }}
                {{
                    owners.find((owner) => owner.id === record.owner_id)
                        ?.name ?? tr("Unassigned")
                }}
            </p>
            <p class="crm-muted">
                {{ taskStatuses[record.status]
                }}<span v-if="record.archived_at"> {{ tr("· Archived") }}</span>
            </p>
            <Link
                v-if="record.goal"
                :href="`/executive/goals/${record.goal.id}`"
                >{{ tr("Goal:") }} {{ record.goal.name }}</Link
            >
            <p v-else-if="record.goal_restricted" class="crm-muted">
                {{ tr("The linked goal is restricted.") }}
            </p>
            <TaskEditor
                v-if="editable"
                :key="record.version"
                :options="props"
                :record="record"
            />
            <dl v-else class="crm-detail-grid">
                <div>
                    <dt>{{ tr("Description") }}</dt>
                    <dd>{{ record.description || tr("No description") }}</dd>
                </div>
                <div>
                    <dt>{{ tr("Due date") }}</dt>
                    <dd>{{ record.due_on }}</dd>
                </div>
                <div>
                    <dt>{{ tr("Priority") }}</dt>
                    <dd>{{ tr(record.priority) }}</dd>
                </div>
                <div v-if="record.completed_on">
                    <dt>{{ tr("Completed on") }}</dt>
                    <dd>{{ record.completed_on }}</dd>
                </div>
            </dl>
        </section>
        <WorkReferences :links="instructionLinks" />
        <HistoryList :history="history" />
        <HistoryList
            v-if="goalHistory.length"
            :history="goalHistory"
            :title="tr('Goal link history')"
        />
    </div>
</template>
