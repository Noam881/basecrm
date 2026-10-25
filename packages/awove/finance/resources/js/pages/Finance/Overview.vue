<script setup lang="ts">
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
    <Head title="Finance" />
    <div class="crm-heading"><h1>Finance</h1></div>
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
                ><span class="crm-field-label">Period from</span
                ><input v-model="period.from" type="date" /></label
            ><label class="crm-field"
                ><span class="crm-field-label">Period to</span
                ><input v-model="period.to" type="date"
            /></label>
            <div class="crm-form-action">
                <button class="crm-button">Apply period</button
                ><Link class="crm-secondary" href="/finance">Clear period</Link>
            </div>
        </form>
        <p v-if="Object.keys(metrics).length" class="crm-muted">
            Expected amounts are current outstanding balances by due date.
            Actual amounts use recorded payment dates. These are separate views,
            not an account balance.
        </p>
        <section
            v-for="(metric, cap) in metrics"
            :key="cap"
            class="crm-card crm-flow"
        >
            <h2>
                {{
                    cap === "collections" ? "Collections" : "Supplier expenses"
                }}
            </h2>
            <dl class="crm-detail-grid">
                <div class="crm-detail-item">
                    <dt>
                        {{
                            cap === "collections"
                                ? "Expected receipts"
                                : "Expected supplier payments"
                        }}
                    </dt>
                    <dd>
                        {{
                            metric.expected === null
                                ? "Unavailable"
                                : `${metric.expected} ${currency}`
                        }}
                    </dd>
                </div>
                <div class="crm-detail-item">
                    <dt>
                        {{
                            cap === "collections"
                                ? "Actual recorded receipts"
                                : "Actual recorded supplier payments"
                        }}
                    </dt>
                    <dd>
                        {{
                            metric.actual === null
                                ? "Unavailable"
                                : `${metric.actual} ${currency}`
                        }}
                    </dd>
                </div>
                <div class="crm-detail-item">
                    <dt>Overdue balances</dt>
                    <dd>{{ metric.overdue_count }}</dd>
                </div>
            </dl>
            <Link class="crm-secondary" :href="`/finance/${cap}`"
                >Open
                {{
                    cap === "collections"
                        ? "customer charges"
                        : "supplier expenses"
                }}</Link
            >
        </section>
    </div>
</template>
