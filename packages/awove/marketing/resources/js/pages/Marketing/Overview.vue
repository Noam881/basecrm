<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
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
    <Head :title="tr('Marketing')" />
    <div class="crm-heading">
        <h1>{{ tr("Marketing") }}</h1>
    </div>
    <div class="crm-flow">
        <MarketingNav v-bind="p" /><MarketingFilters
            endpoint="/marketing"
            :reports="true"
            v-bind="p"
        />
        <p v-if="!currencyConsistent" class="crm-notice" role="alert">
            {{
                tr(
                    "The configured currency differs from retained records. Restore it to calculate totals.",
                )
            }}
        </p>
        <section v-if="totals" class="crm-card crm-flow">
            <h2>{{ tr("Performance") }}</h2>
            <dl class="crm-detail-grid">
                <div class="crm-detail-item">
                    <dt>{{ tr("Actual spend") }}</dt>
                    <dd>{{ totals.actual_spend }} {{ currency }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>{{ tr("Reported leads") }}</dt>
                    <dd>{{ totals.reported_leads }}</dd>
                </div>
                <div class="crm-detail-item">
                    <dt>{{ tr("Cost per lead") }}</dt>
                    <dd>{{ totals.cost_per_lead ?? tr("Not available") }}</dd>
                </div>
            </dl>
            <p>
                {{
                    tr(
                        "Cost per lead uses total actual spend divided by total reported leads, including spend from periods with no leads. Planned budgets are separate.",
                    )
                }}
            </p>
        </section>
        <section v-if="canCampaigns" class="crm-card crm-flow">
            <h2>{{ tr("Active campaigns") }}</h2>
            <p v-if="!campaigns.length">
                {{ tr("No active campaigns in this view.") }}
            </p>
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
            <h2>{{ tr("Upcoming content") }}</h2>
            <p v-if="!content.length">
                {{ tr("No pending content in this view.") }}
            </p>
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
