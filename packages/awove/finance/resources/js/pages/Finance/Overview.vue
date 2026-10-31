<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { Head, Link, router } from "@inertiajs/vue3";
import { reactive } from "vue";
import FinanceNav from "../../components/FinanceNav.vue";
import type { Section } from "../../types";
const props = defineProps<{
    sections: Section[];
    metrics: Record<
        string,
        {
            expected: string | null;
            actual: string | null;
            overdue_count: number;
            currency_mismatch: boolean;
        }
    >;
    filters: Record<string, string>;
    currency: string;
    currencyError: string | null;
}>();
const period = reactive({
    from: props.filters.from ?? "",
    to: props.filters.to ?? "",
    owner_id: props.filters.owner_id ?? "",
});
</script>
<template>
    <Head :title="tr('Finance')" />
    <div class="crm-heading">
        <h1>{{ tr("Finance") }}</h1>
    </div>
    <div class="crm-flow">
        <FinanceNav :sections="sections" />
        <p v-if="currencyError" class="crm-alert" role="alert">
            {{ currencyError }}
        </p>
        <form
            v-if="Object.keys(metrics).length"
            class="crm-card crm-form-grid"
            @submit.prevent="
                router.get('/finance', period, { preserveState: true })
            "
        >
            <label class="crm-field"
                ><span class="crm-field-label">{{ tr("Period from") }}</span
                ><input v-model="period.from" type="date" /></label
            ><label class="crm-field"
                ><span class="crm-field-label">{{ tr("Period to") }}</span
                ><input v-model="period.to" type="date"
            /></label>
            <div class="crm-form-action">
                <button class="crm-button">{{ tr("Apply period") }}</button
                ><Link class="crm-secondary" href="/finance">{{
                    tr("Clear period")
                }}</Link>
            </div>
        </form>
        <p v-if="Object.keys(metrics).length" class="crm-muted">
            {{
                tr(
                    "Expected amounts are current outstanding balances by due date. Actual amounts use recorded payment dates. These are separate views, not an account balance.",
                )
            }}
        </p>
        <section
            v-for="(metric, cap) in metrics"
            :key="cap"
            class="crm-card crm-flow"
        >
            <h2>
                {{
                    cap === "collections"
                        ? tr("Collections")
                        : tr("Supplier expenses")
                }}
            </h2>
            <dl class="crm-detail-grid">
                <div class="crm-detail-item">
                    <dt>
                        {{
                            cap === "collections"
                                ? tr("Expected receipts")
                                : tr("Expected supplier payments")
                        }}
                    </dt>
                    <dd>
                        {{
                            metric.expected === null
                                ? tr("Unavailable")
                                : `${metric.expected} ${currency}`
                        }}
                    </dd>
                </div>
                <div class="crm-detail-item">
                    <dt>
                        {{
                            cap === "collections"
                                ? tr("Actual recorded receipts")
                                : tr("Actual recorded supplier payments")
                        }}
                    </dt>
                    <dd>
                        {{
                            metric.actual === null
                                ? tr("Unavailable")
                                : `${metric.actual} ${currency}`
                        }}
                    </dd>
                </div>
                <div class="crm-detail-item">
                    <dt>{{ tr("Overdue balances") }}</dt>
                    <dd>{{ metric.overdue_count }}</dd>
                </div>
            </dl>
            <Link class="crm-secondary" :href="`/finance/${cap}`"
                >{{ tr("Open") }}
                {{
                    cap === "collections"
                        ? tr("customer charges")
                        : tr("supplier expenses")
                }}</Link
            >
        </section>
    </div>
</template>
