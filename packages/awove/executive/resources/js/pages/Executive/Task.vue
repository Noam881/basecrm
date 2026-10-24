<script setup lang="ts">
import { Head, Link, router } from "@inertiajs/vue3";
import TaskEditor from "../../components/TaskEditor.vue";
import HistoryList from "../../components/History.vue";
import type { Task, History, Options } from "../../types";
const props = defineProps<
    Options & {
        record: Task;
        editable: boolean;
        history: History[];
        goalHistory: History[];
    }
>();
</script>
<template>
    <Head :title="record.title" /><Link
        href="/executive?section=tasks"
        class="crm-back"
        >← Back to management tasks</Link
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
            Archive task
        </button>
    </div>
    <div class="crm-stack">
        <section class="crm-card crm-flow">
            <h2>Management task</h2>
            <p>
                Owner:
                {{
                    owners.find((owner) => owner.id === record.owner_id)
                        ?.name ?? "Unassigned"
                }}
            </p>
            <p class="crm-muted">
                {{ taskStatuses[record.status]
                }}<span v-if="record.archived_at"> · Archived</span>
            </p>
            <Link
                v-if="record.goal"
                :href="`/executive/goals/${record.goal.id}`"
                >Goal: {{ record.goal.name }}</Link
            >
            <p v-else-if="record.goal_restricted" class="crm-muted">
                The linked goal is restricted.
            </p>
            <TaskEditor
                v-if="editable"
                :key="record.version"
                :options="props"
                :record="record"
            />
            <dl v-else class="crm-detail-grid">
                <div>
                    <dt>Description</dt>
                    <dd>{{ record.description || "No description" }}</dd>
                </div>
                <div>
                    <dt>Due date</dt>
                    <dd>{{ record.due_on }}</dd>
                </div>
                <div>
                    <dt>Priority</dt>
                    <dd>{{ record.priority }}</dd>
                </div>
                <div v-if="record.completed_on">
                    <dt>Completed on</dt>
                    <dd>{{ record.completed_on }}</dd>
                </div>
            </dl>
        </section>
        <HistoryList :history="history" />
        <HistoryList
            v-if="goalHistory.length"
            :history="goalHistory"
            title="Goal link history"
        />
    </div>
</template>
