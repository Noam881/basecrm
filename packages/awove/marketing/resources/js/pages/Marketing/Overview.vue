<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import MarketingNav from "../../components/MarketingNav.vue";
import MarketingFilters from "../../components/MarketingFilters.vue";
import type { Options, MarketingRecord, Totals } from "../../types";
const p = defineProps<
    Options & {
        campaigns: MarketingRecord[];
        content: MarketingRecord[];
        totals: Totals | null;
        filters: Record<string, string>;
    }
>();
</script>
<template>
    <Head title="Marketing" />
    <div class="crm-heading"><h1>Marketing</h1></div>
    <div class="crm-flow">
        <MarketingNav v-bind="p" /><MarketingFilters
            endpoint="/marketing"
            :reports="true"
            v-bind="p"
        />
        <p v-if="!currencyConsistent" class="crm-notice" role="alert">
            The configured currency differs from retained records. Restore it to
            calculate totals.
        </p>
        <section v-if="totals" class="crm-card crm-flow">
            <h2>Performance</h2>
            <dl class="crm-detail-grid">
                <div class="crm-detail-item">
                    <dt>Actual spend</dt>
                    <dd>{{ totals.actual_spend }} {{ currency }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Reported leads</dt>
                    <dd>{{ totals.reported_leads }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Cost per lead</dt>
                    <dd>{{ totals.cost_per_lead ?? "Not available" }}</dd>
                </div>
            </dl>
            <p>
                Cost per lead uses total actual spend divided by total reported
                leads, including spend from periods with no leads. Planned
                budgets are separate.
            </p>
        </section>
        <section v-if="canCampaigns" class="crm-card crm-flow">
            <h2>Active campaigns</h2>
            <p v-if="!campaigns.length">No active campaigns in this view.</p>
            <article v-for="c in campaigns" :key="c.id" class="crm-note">
                <Link
                    class="md-table-record"
                    :href="`/marketing/campaigns/${c.id}`"
                    >{{ c.name }}</Link
                >
                <p>{{ c.channel }} · {{ c.starts_on }} – {{ c.ends_on }}</p>
            </article>
        </section>
        <section v-if="canContent" class="crm-card crm-flow">
            <h2>Upcoming content</h2>
            <p v-if="!content.length">No pending content in this view.</p>
            <article v-for="c in content" :key="c.id" class="crm-note">
                <Link
                    class="md-table-record"
                    :href="`/marketing/content/${c.id}`"
                    >{{ c.title }}</Link
                >
                <p>{{ c.channel }} · {{ c.planned_on }}</p>
            </article>
        </section>
    </div>
</template>
