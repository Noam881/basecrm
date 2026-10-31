<script setup lang="ts">
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
    <Head title="Operations" />
    <div class="crm-heading"><h1>Operations</h1></div>
    <div class="crm-flow">
        <OperationsNav :can-view-directory="canViewDirectory" /><Filters
            v-bind="props"
            endpoint="/operations"
            customer
            supplier
        />
        <p class="crm-muted">
            Customer filters apply to requests; supplier filters apply to
            orders. Date filters use request due dates, expected delivery dates
            and routine next dates.
        </p>
        <div class="crm-metrics">
            <Link class="crm-card" href="/operations/requests"
                ><span>Open requests</span
                ><strong>{{ metrics.open_requests }}</strong></Link
            ><Link class="crm-card" href="/operations/requests?view=overdue"
                ><span>Overdue requests</span
                ><strong>{{ metrics.overdue_requests }}</strong></Link
            ><Link class="crm-card" href="/operations/orders?view=outstanding"
                ><span>Outstanding orders</span
                ><strong>{{ metrics.outstanding_orders }}</strong></Link
            ><Link class="crm-card" href="/operations/orders?view=overdue"
                ><span>Overdue deliveries</span
                ><strong>{{ metrics.overdue_orders }}</strong></Link
            ><Link class="crm-card" href="/operations/routines?view=upcoming"
                ><span>Upcoming routines</span
                ><strong>{{ metrics.upcoming_routines }}</strong></Link
            ><Link class="crm-card" href="/operations/routines?view=overdue"
                ><span>Overdue routines</span
                ><strong>{{ metrics.overdue_routines }}</strong></Link
            >
        </div>
    </div>
</template>
