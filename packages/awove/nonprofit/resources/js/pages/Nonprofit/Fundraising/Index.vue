<script setup lang="ts">
import { Head, Link, router } from "@inertiajs/vue3";
import { reactive } from "vue";
import DataTable from "../../../../../../crm-core/resources/js/components/table/DataTable.vue";
import type { TablePayload } from "../../../../../../crm-core/resources/js/components/table/types";
import { queryString } from "../../../../../../crm-core/resources/js/components/table/queryString";
import NonprofitNav from "../../../components/NonprofitNav.vue";
import ConstituentEditor from "../../../components/Fundraising/ConstituentEditor.vue";
import Classify from "../../../components/Fundraising/Classify.vue";
import type { Options } from "../../../components/Fundraising/types";
const p = defineProps<
    Options & {
        tab: "constituents" | "pledges";
        table: TablePayload;
        filters: Record<string, string>;
        report: {
            constituents: number;
            actions_due: number;
            pledged_amount?: string | null;
            received_amount?: string;
            receipts_available?: boolean;
        };
    }
>();
const f = reactive({
    owner_id: p.filters.owner_id ?? "",
    role: p.filters.role ?? "",
    from: p.filters.from ?? "",
    to: p.filters.to ?? "",
    view: p.filters.view ?? "all",
});
function apply() {
    router.get(
        "/nonprofit/fundraising",
        { ...f, tab: p.tab },
        { preserveState: true },
    );
}
</script>
<template>
    <Head title="Fundraising" />
    <div class="crm-heading">
        <h1>Fundraising</h1>
        <a
            v-if="canExport"
            class="crm-secondary"
            :href="
                '/nonprofit/fundraising/export?' +
                queryString({ ...filters, tab, ...table.state })
            "
            >Export CSV</a
        >
    </div>
    <div class="crm-flow">
        <NonprofitNav :sections="sections" />
        <nav class="crm-tabs" aria-label="Fundraising sections">
            <Link href="/nonprofit/fundraising"
                >Donors, partners and prospects</Link
            ><Link
                v-if="canViewPledges"
                href="/nonprofit/fundraising?tab=pledges"
                >Pledges</Link
            >
        </nav>
        <div class="crm-metrics">
            <Link
                class="crm-card"
                :href="
                    '/nonprofit/fundraising?' +
                    queryString({
                        owner_id: filters.owner_id,
                        role: filters.role,
                    })
                "
                ><span>Active fundraising contexts</span
                ><strong>{{ report.constituents }}</strong></Link
            ><Link
                class="crm-card"
                :href="
                    '/nonprofit/fundraising?' +
                    queryString({
                        owner_id: filters.owner_id,
                        role: filters.role,
                        view: 'due',
                    })
                "
                ><span>Follow-ups due · all dates</span
                ><strong>{{ report.actions_due }}</strong></Link
            >
            <div v-if="canViewPledges" class="crm-card">
                <span>Active pledges · expected date in period</span
                ><strong
                    >{{ report.pledged_amount ?? "Unavailable" }}
                    {{ report.pledged_amount !== null ? currency : "" }}</strong
                >
            </div>
            <div v-if="canViewPledges && canViewReceipts" class="crm-card">
                <span>Retained linked receipts · payment date in period</span
                ><strong>{{
                    report.receipts_available
                        ? report.received_amount + " " + currency
                        : "Unavailable"
                }}</strong>
            </div>
        </div>
        <p class="crm-muted">
            Pledges and linked Finance payments are separate measures. Actuals
            include distinct linked payments only; no receipting or automatic
            Finance entry occurs.
        </p>
        <form class="crm-card crm-form-grid" @submit.prevent="apply">
            <label class="crm-field"
                ><span class="crm-field-label">Responsible person</span
                ><select v-model="f.owner_id">
                    <option value="">Any visible owner</option>
                    <option v-for="o in owners" :key="o.id" :value="o.id">
                        {{ o.name }}
                    </option>
                </select></label
            ><label class="crm-field"
                ><span class="crm-field-label">Classification</span
                ><select v-model="f.role">
                    <option value="">All classifications</option>
                    <option v-for="r in roles" :key="r" :value="r">
                        {{ r.charAt(0).toUpperCase() + r.slice(1) }}
                    </option>
                </select></label
            ><label class="crm-field"
                ><span class="crm-field-label">From</span
                ><input v-model="f.from" type="date" /></label
            ><label class="crm-field"
                ><span class="crm-field-label">To</span
                ><input v-model="f.to" type="date" /></label
            ><label class="crm-field"
                ><span class="crm-field-label">View</span
                ><select v-model="f.view">
                    <option value="all">Active records</option>
                    <option value="due">Date due</option>
                    <option value="archived">Archived records</option>
                </select></label
            >
            <div class="crm-form-action">
                <button class="crm-secondary">Apply filters</button>
            </div>
        </form>
        <p class="crm-muted">
            Table period:
            {{
                tab === "pledges"
                    ? "pledge expected donation date"
                    : "next action date"
            }}. Receipt reports use actual payment date.
        </p>
        <Classify v-bind="p" />
        <details
            v-if="canCreate && tab === 'constituents'"
            class="crm-card crm-form-disclosure"
        >
            <summary>Add fundraising context</summary>
            <ConstituentEditor v-bind="p" />
        </details>
        <DataTable
            :payload="table"
            endpoint="/nonprofit/fundraising"
            :base-query="{ ...filters, tab }"
            :title="
                tab === 'pledges' ? 'Pledges' : 'Donors, partners and prospects'
            "
        />
    </div>
</template>
