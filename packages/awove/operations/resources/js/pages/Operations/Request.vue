<script setup lang="ts">
import WorkReferences, {
    type WorkReference,
} from "../../../../../crm-core/resources/js/components/WorkReferences.vue";
import { Head, Link, router } from "@inertiajs/vue3";
import OperationsNav from "../../components/OperationsNav.vue";
import RequestEditor from "../../components/RequestEditor.vue";
import HistoryList from "../../components/HistoryList.vue";
import type { Options, Work, History } from "../../types";
const props = defineProps<
    Options & {
        record: Work;
        history: History[];
        instructionLinks: WorkReference[];
    }
>();
function archive() {
    if (confirm("Archive this request and retain its history?"))
        router.post(`/operations/requests/${props.record.id}/archive`, {
            version: props.record.version,
        });
}
</script>
<template>
    <Head :title="record.title" /><Link
        class="crm-back"
        href="/operations/requests"
        >← Operational requests</Link
    >
    <div class="crm-heading">
        <h1>{{ record.title }}</h1>
        <span class="crm-badge">{{ statuses[record.status] }}</span>
    </div>
    <div class="crm-flow">
        <OperationsNav :can-view-directory="canViewDirectory" />
        <section class="crm-card crm-flow">
            <dl class="crm-detail-grid">
                <div class="crm-detail-item">
                    <dt>Owner</dt>
                    <dd>{{ record.owner?.name ?? "Unassigned" }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Customer</dt>
                    <dd>
                        <Link
                            v-if="record.customer"
                            :href="`/operations/directory/${record.customer.id}`"
                            >{{ record.customer.name }}</Link
                        ><template v-else>No visible customer link</template>
                    </dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Due date</dt>
                    <dd>{{ record.due_on }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Priority</dt>
                    <dd>{{ record.priority }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Completion date</dt>
                    <dd>{{ record.completed_on ?? "Not completed" }}</dd>
                </div>
            </dl>
            <p v-if="record.description">{{ record.description }}</p>
            <p v-if="record.notes">{{ record.notes }}</p>
            <a
                v-if="record.document_link"
                class="crm-secondary"
                :href="record.document_link"
                target="_blank"
                rel="noopener noreferrer"
                >Open document</a
            >
            <p v-if="record.archived_at">
                Archived · retained read-only record.
            </p>
        </section>
        <section v-if="record.editable" class="crm-card crm-flow">
            <h2>Edit request</h2>
            <RequestEditor :key="record.version" v-bind="props" />
            <div v-if="canArchive" class="crm-form-action">
                <button class="crm-secondary" @click="archive">
                    Archive request
                </button>
            </div>
        </section>
        <WorkReferences :links="instructionLinks" />
        <HistoryList :history="history" />
    </div>
</template>
