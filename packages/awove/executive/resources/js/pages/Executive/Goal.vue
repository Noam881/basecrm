<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { Head, Link, router } from "@inertiajs/vue3";
import GoalEditor from "../../components/GoalEditor.vue";
import HistoryList from "../../components/History.vue";
import type { Goal, History, Options } from "../../types";
const props = defineProps<
    Options & { record: Goal; editable: boolean; history: History[] }
>();
</script>
<template>
    <Head :title="record.name" /><Link
        href="/executive?section=goals"
        class="crm-back"
        >{{ tr("← Back to goals") }}</Link
    >
    <div class="crm-heading">
        <h1>{{ record.name }}</h1>
        <button
            v-if="editable && canArchive"
            class="crm-secondary"
            @click="
                router.post(`/executive/goals/${record.id}/archive`, {
                    version: record.version,
                })
            "
        >
            {{ tr("Archive goal") }}
        </button>
    </div>
    <div class="crm-stack">
        <section class="crm-card crm-flow">
            <h2>{{ tr("Goal details") }}</h2>
            <p>
                {{ tr("Responsible person:") }}
                {{
                    owners.find((owner) => owner.id === record.owner_id)
                        ?.name ?? tr("Unassigned")
                }}
            </p>
            <p class="crm-muted">
                {{ goalStatuses[record.status]
                }}<span v-if="record.archived_at"> {{ tr("· Archived") }}</span>
            </p>
            <GoalEditor
                v-if="editable"
                :key="record.version"
                :options="props"
                :record="record"
            />
            <dl v-else class="crm-detail-grid">
                <div>
                    <dt>{{ tr("Success criterion") }}</dt>
                    <dd>{{ record.success_criterion }}</dd>
                </div>
                <div>
                    <dt>{{ tr("Target date") }}</dt>
                    <dd>{{ record.target_on }}</dd>
                </div>
                <div>
                    <dt>{{ tr("Target result") }}</dt>
                    <dd>{{ record.target_value }} {{ record.unit }}</dd>
                </div>
                <div>
                    <dt>{{ tr("Current result") }}</dt>
                    <dd>{{ record.current_value }} {{ record.unit }}</dd>
                </div>
            </dl>
        </section>
        <HistoryList :history="history" />
    </div>
</template>
