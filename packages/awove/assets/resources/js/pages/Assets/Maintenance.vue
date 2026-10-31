<script setup lang="ts">
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import HistoryList from "../../../../../crm-core/resources/js/components/HistoryList.vue";
import { date } from "../../../../../crm-core/resources/js/types";
import AssetsNav from "../../components/AssetsNav.vue";
import ClassifyIdentity from "../../components/ClassifyIdentity.vue";
import MaintenanceEditor from "../../components/MaintenanceEditor.vue";
import CostEditor from "../../components/CostEditor.vue";
import CompletionEditor from "../../components/CompletionEditor.vue";
import FormErrors from "../../components/FormErrors.vue";
import {
    historyLabels,
    type Options,
    type MaintenanceRecord,
    type Completion,
    type CostSnapshot,
    type History,
} from "../../types";
const p = defineProps<
    Options & {
        record: MaintenanceRecord;
        editable: boolean;
        canComplete: boolean;
        canArchiveMaintenance: boolean;
        canRecordCost: boolean;
        costHistory?: CostSnapshot[];
        completions: Completion[];
        history: History[];
    }
>();
const archival = useForm({
    version: p.record.version,
    asset_version: p.record.asset.version,
});
const zone = (usePage().props.brand as { timezone?: string } | undefined)
    ?.timezone;
function archive() {
    archival.version = p.record.version;
    archival.asset_version = p.record.asset.version;
    archival.post(`/assets/maintenance/${p.record.id}/archive`, {
        preserveScroll: true,
    });
}
</script>
<template>
    <Head :title="record.action" /><Link
        class="crm-back"
        :href="`/assets/register/${record.asset.id}`"
        >← {{ record.asset.name }}</Link
    >
    <div class="crm-heading">
        <h1>{{ record.action }}</h1>
        <span class="crm-badge">{{
            record.archived_at ? "Archived" : maintenanceStatuses[record.status]
        }}</span>
    </div>
    <div class="crm-flow">
        <AssetsNav :sections="sections" :module-label="moduleLabel" />
        <ClassifyIdentity
            v-if="canClassify && editable && record.status !== 'completed'"
            v-bind="p"
            :roles="['supplier']"
        />
        <section class="crm-card crm-flow">
            <h2>Maintenance details</h2>
            <dl class="crm-detail-grid">
                <div class="crm-detail-item">
                    <dt>Asset</dt>
                    <dd>
                        <Link
                            class="crm-inline-link"
                            :href="`/assets/register/${record.asset.id}`"
                            >{{ record.asset.name }} ·
                            {{ record.asset.code }}</Link
                        >
                    </dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Asset status</dt>
                    <dd>{{ statuses[record.asset.status] }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Due date</dt>
                    <dd>{{ record.due_on }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Supplier</dt>
                    <dd>
                        {{
                            record.supplier?.name ??
                            (record.supplier_restricted
                                ? "Supplier reference restricted"
                                : "Not specified")
                        }}
                    </dd>
                </div>
            </dl>
            <MaintenanceEditor v-bind="p" />
        </section>
        <section
            v-if="
                editable &&
                canComplete &&
                record.status !== 'completed' &&
                !record.archived_at
            "
            class="crm-card crm-flow"
        >
            <h2>Record completion</h2>
            <CompletionEditor v-bind="p" />
        </section>
        <section v-if="completions.length" class="crm-card crm-flow">
            <h2>Completion records</h2>
            <article
                v-for="completion in completions"
                :key="completion.id"
                class="crm-note crm-flow"
            >
                <dl class="crm-detail-grid">
                    <div class="crm-detail-item">
                        <dt>Completion date</dt>
                        <dd>{{ completion.completed_on }}</dd>
                    </div>
                    <div class="crm-detail-item">
                        <dt>Manual next due date at completion</dt>
                        <dd>{{ completion.next_due_on ?? "Not specified" }}</dd>
                    </div>
                    <div v-if="canViewCosts" class="crm-detail-item">
                        <dt>Original cost at completion</dt>
                        <dd>
                            {{
                                completion.cost === null ||
                                completion.cost === undefined
                                    ? "Not recorded"
                                    : completion.cost +
                                      " " +
                                      (completion.currency ?? "")
                            }}
                        </dd>
                    </div>
                </dl>
                <small
                    >{{ completion.actor?.name ?? "Former staff" }} ·
                    <time :datetime="completion.created_at">{{
                        date(completion.created_at, zone)
                    }}</time></small
                >
            </article>
        </section>
        <section
            v-if="canViewCosts && record.status === 'completed'"
            class="crm-card crm-flow"
        >
            <h2>Maintenance cost</h2>
            <dl class="crm-detail-grid">
                <div class="crm-detail-item">
                    <dt>Current recorded cost</dt>
                    <dd>
                        {{
                            record.cost === null || record.cost === undefined
                                ? "Not recorded"
                                : record.cost + " " + (record.currency ?? "")
                        }}
                    </dd>
                </div>
            </dl>
            <CostEditor
                v-if="editable && canRecordCost && !record.archived_at"
                v-bind="p"
            />
            <h3 v-if="costHistory?.length">Cost history</h3>
            <article
                v-for="entry in costHistory ?? []"
                :key="entry.id"
                class="crm-note crm-flow"
            >
                <strong>{{ entry.amount }} {{ entry.currency }}</strong
                ><small
                    >{{ entry.actor?.name ?? "Former staff" }} ·
                    <time :datetime="entry.created_at">{{
                        date(entry.created_at, zone)
                    }}</time></small
                >
            </article>
        </section>
        <HistoryList :history="history" :field-labels="historyLabels" />
        <form
            v-if="editable && canArchiveMaintenance && !record.archived_at"
            class="crm-card crm-form"
            @submit.prevent="archive"
        >
            <p>
                Archiving retains this action's existing status and history. It
                records no completion and changes no asset status.
            </p>
            <FormErrors :errors="archival.errors" />
            <div class="crm-form-action">
                <button class="crm-secondary" :disabled="archival.processing">
                    Archive maintenance action
                </button>
            </div>
        </form>
    </div>
</template>
