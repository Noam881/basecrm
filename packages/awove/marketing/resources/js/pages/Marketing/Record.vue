<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import MarketingNav from "../../components/MarketingNav.vue";
import Editor from "../../components/Editor.vue";
import ReportEditor from "../../components/ReportEditor.vue";
import HistoryList from "../../../../../crm-core/resources/js/components/HistoryList.vue";
import type { HistoryEntry } from "../../../../../crm-core/resources/js/components/HistoryList.vue";
import type { Options, MarketingRecord, Report } from "../../types";
const p = defineProps<
    Options & {
        record: MarketingRecord;
        editable: boolean;
        history: HistoryEntry[];
        reports: Report[];
        linkedOpportunities: number | null;
    }
>();
const archive = useForm({ version: p.record.version });
function archiveRecord() {
    archive.version = p.record.version;
    archive.post(`/marketing/${p.kind}/${p.record.id}/archive`, {
        preserveScroll: true,
    });
}
</script>
<template>
    <Head :title="record.name ?? record.title" /><Link
        class="crm-back"
        :href="`/marketing/${kind}`"
        >← {{ kind === "campaigns" ? "Campaigns" : "Content calendar" }}</Link
    >
    <div class="crm-heading">
        <h1>{{ record.name ?? record.title }}</h1>
        <span class="crm-badge">{{
            record.archived_at ? "Archived" : statuses[record.status]
        }}</span>
    </div>
    <div class="crm-flow">
        <MarketingNav v-bind="p" />
        <p v-if="!currencyConsistent" class="crm-notice" role="alert">
            Currency configuration differs from retained financial records.
            Financial edits and totals are unavailable.
        </p>
        <section class="crm-card">
            <Editor :key="record.id" v-bind="p" />
        </section>
        <section v-if="kind === 'campaigns'" class="crm-card crm-flow">
            <h2>Performance periods</h2>
            <p>
                Reported leads are entered manually. They are separate from
                linked CRM opportunities and are never added together.
            </p>
            <p v-if="linkedOpportunities !== null">
                Accessible linked opportunities: {{ linkedOpportunities }}
            </p>
            <details v-if="editable" class="crm-form-disclosure">
                <summary>Add performance</summary>
                <ReportEditor
                    :available="currencyConsistent"
                    :campaign-id="record.id"
                    :currency="record.currency ?? currency"
                />
            </details>
            <p v-if="!reports.length" class="crm-muted">
                No reporting periods yet.
            </p>
            <article v-for="r in reports" :key="r.id" class="crm-note crm-flow">
                <h3>{{ r.starts_on }} – {{ r.ends_on }}</h3>
                <p>
                    Actual spend: {{ r.actual_spend }} {{ r.currency }} ·
                    Reported leads: {{ r.reported_leads }} · Cost per lead:
                    {{ r.cost_per_lead ?? "Not available" }}
                </p>
                <p v-if="r.notes">{{ r.notes }}</p>
                <details v-if="editable" class="crm-form-disclosure">
                    <summary>Edit reporting period</summary>
                    <ReportEditor
                        :available="currencyConsistent"
                        :campaign-id="record.id"
                        :currency="r.currency"
                        :report="r"
                    />
                </details>
                <HistoryList :history="r.history" title="Performance history" />
            </article>
        </section>
        <section
            v-if="
                record.materials_url ||
                record.landing_url ||
                record.asset_url ||
                record.publication_url
            "
            class="crm-card crm-flow"
        >
            <h2>Links</h2>
            <a
                v-for="field in [
                    'materials_url',
                    'landing_url',
                    'asset_url',
                    'publication_url',
                ] as const"
                v-show="record[field]"
                :key="field"
                class="crm-secondary"
                :href="`/marketing/${kind}/${record.id}/document/${field}`"
                rel="noreferrer"
                >{{ field.replace("_url", "").replace("_", " ") }}</a
            >
        </section>
        <HistoryList :history="history" />
        <form
            v-if="editable && canArchive"
            class="crm-card crm-form"
            @submit.prevent="archiveRecord"
        >
            <p>
                Archiving retains all history and performance figures and stops
                content reminders.
            </p>
            <div class="crm-form-action">
                <button class="crm-secondary" :disabled="archive.processing">
                    Archive {{ kind === "campaigns" ? "campaign" : "content" }}
                </button>
            </div>
        </form>
    </div>
</template>
