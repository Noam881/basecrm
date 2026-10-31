<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { Head, Link } from "@inertiajs/vue3";
import OperationsNav from "../../components/OperationsNav.vue";
import Filters from "../../components/Filters.vue";
import type { Options } from "../../types";
const props = defineProps<
    Options & {
        filters: Record<string, string>;
        metrics: Record<string, number>;
    }
>();
</script>
<template>
    <Head :title="tr('Operations')" />
    <div class="crm-heading">
        <h1>{{ tr("Operations") }}</h1>
    </div>
    <div class="crm-flow">
        <OperationsNav :can-view-directory="canViewDirectory" /><Filters
            v-bind="props"
            endpoint="/operations"
            customer
            supplier
        />
        <p class="crm-muted">
            {{
                tr(
                    "Customer filters apply to requests; supplier filters apply to orders. Date filters use request due dates, expected delivery dates and routine next dates.",
                )
            }}
        </p>
        <div class="crm-metrics">
            <Link class="crm-card" href="/operations/requests"
                ><span>{{ tr("Open requests") }}</span
                ><strong>{{ metrics.open_requests }}</strong></Link
            ><Link class="crm-card" href="/operations/requests?view=overdue"
                ><span>{{ tr("Overdue requests") }}</span
                ><strong>{{ metrics.overdue_requests }}</strong></Link
            ><Link class="crm-card" href="/operations/orders?view=outstanding"
                ><span>{{ tr("Outstanding orders") }}</span
                ><strong>{{ metrics.outstanding_orders }}</strong></Link
            ><Link class="crm-card" href="/operations/orders?view=overdue"
                ><span>{{ tr("Overdue deliveries") }}</span
                ><strong>{{ metrics.overdue_orders }}</strong></Link
            ><Link class="crm-card" href="/operations/routines?view=upcoming"
                ><span>{{ tr("Upcoming routines") }}</span
                ><strong>{{ metrics.upcoming_routines }}</strong></Link
            ><Link class="crm-card" href="/operations/routines?view=overdue"
                ><span>{{ tr("Overdue routines") }}</span
                ><strong>{{ metrics.overdue_routines }}</strong></Link
            >
        </div>
    </div>
</template>
