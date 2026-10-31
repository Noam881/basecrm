<script setup lang="ts">
import { t as tr, translateLabels } from "@/lib/i18n";
import { Head, Link, useForm } from "@inertiajs/vue3";
import HistoryList from "../../../../../../crm-core/resources/js/components/HistoryList.vue";
import type { HistoryEntry } from "../../../../../../crm-core/resources/js/components/HistoryList.vue";
import NonprofitNav from "../../../components/NonprofitNav.vue";
import ConstituentEditor from "../../../components/Fundraising/ConstituentEditor.vue";
import PledgeEditor from "../../../components/Fundraising/PledgeEditor.vue";
import Errors from "../../../components/Fundraising/Errors.vue";
import {
    fields,
    events,
    type Options,
    type Constituent,
    type Pledge,
} from "../../../components/Fundraising/types";
const p = defineProps<
    Options & {
        record: Constituent;
        editable: boolean;
        pledges: Pledge[];
        history: HistoryEntry[];
    }
>();
const archival = useForm({ version: p.record.version });
function archive() {
    archival.version = p.record.version;
    archival.post(`/nonprofit/fundraising/constituents/${p.record.id}/archive`);
}
</script>
<template>
    <Head :title="record.relationship.name" /><Link
        class="crm-back"
        href="/nonprofit/fundraising"
        >{{ tr("← Fundraising") }}</Link
    >
    <div class="crm-heading">
        <h1>{{ record.relationship.name }}</h1>
        <span class="crm-badge">{{
            record.archived_at
                ? tr("Archived")
                : record.relationship.role.charAt(0).toUpperCase() +
                  record.relationship.role.slice(1)
        }}</span>
    </div>
    <div class="crm-flow">
        <NonprofitNav :sections="sections" />
        <section class="crm-card crm-flow">
            <h2>{{ tr("Fundraising details") }}</h2>
            <ConstituentEditor v-bind="p" />
        </section>
        <section v-if="canViewPledges" class="crm-card crm-flow">
            <h2>{{ tr("Pledges") }}</h2>
            <p v-if="!pledges.length" class="crm-muted">
                {{ tr("No pledges recorded.") }}
            </p>
            <article
                v-for="pledge in pledges"
                :key="pledge.id"
                class="crm-note crm-flow"
            >
                <Link
                    class="crm-inline-link"
                    :href="`/nonprofit/fundraising/pledges/${pledge.id}`"
                    >{{ pledge.reference ?? tr("Pledge #") + pledge.id }}</Link
                ><span
                    >{{ pledge.amount }} {{ pledge.currency }}
                    {{ tr("· Expected") }} {{ pledge.expected_on
                    }}{{ pledge.archived_at ? tr(" · Archived") : "" }}</span
                >
                <p>{{ pledge.restrictions ?? tr("Unrestricted") }}</p>
            </article>
            <details
                v-if="editable && canCreate && canManagePledges"
                class="crm-form-disclosure"
            >
                <summary>{{ tr("Add pledge") }}</summary>
                <PledgeEditor
                    v-bind="p"
                    :record="undefined"
                    :constituent="record"
                />
            </details>
        </section>
        <HistoryList
            :history="history"
            :event-labels="translateLabels(events)"
            :field-labels="translateLabels(fields)"
        />
        <form
            v-if="editable && canArchive"
            class="crm-card crm-form"
            @submit.prevent="archive"
        >
            <p>
                {{
                    tr(
                        "Archiving retains pledges and receipt links, makes this context read-only and stops its follow-up reminders.",
                    )
                }}
            </p>
            <Errors :errors="archival.errors" />
            <div class="crm-form-action">
                <button class="crm-secondary" :disabled="archival.processing">
                    {{ tr("Archive fundraising context") }}
                </button>
            </div>
        </form>
    </div>
</template>
