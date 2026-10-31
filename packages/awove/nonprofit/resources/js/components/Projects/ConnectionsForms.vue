<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { computed } from "vue";
import Errors from "./Errors.vue";
import type {
    Options,
    Project,
    SourceOptions,
    Resource,
    Payment,
} from "./types";
const p = defineProps<
    Options & {
        project: Project;
        resourceOptions: Record<string, SourceOptions<Resource>>;
        paymentOptions: Record<string, SourceOptions<Payment>>;
    }
>();
const resource = useForm({
    project_version: p.project.version,
    target_type:
        Object.entries(p.resourceOptions).find(([, v]) => v.available)?.[0] ??
        "asset",
    target_id: null as number | null,
});
const payment = useForm({
    project_version: p.project.version,
    direction:
        Object.entries(p.paymentOptions).find(([, v]) => v.available)?.[0] ??
        "incoming",
    payment_id: null as number | null,
});
const availableResources = computed(() =>
    Object.entries(p.resourceOptions).filter(([, value]) => value.available),
);
const availablePayments = computed(() =>
    Object.entries(p.paymentOptions).filter(([, value]) => value.available),
);
function resourceSave() {
    resource.project_version = p.project.version;
    resource.post(`/nonprofit/projects/${p.project.id}/resources`, {
        preserveScroll: true,
        onSuccess: () => resource.reset("target_id"),
    });
}
function paymentSave() {
    payment.project_version = p.project.version;
    payment.post(`/nonprofit/projects/${p.project.id}/payments`, {
        preserveScroll: true,
        onSuccess: () => payment.reset("payment_id"),
    });
}
</script>
<template>
    <div class="crm-flow">
        <details v-if="availableResources.length" class="crm-form-disclosure">
            <summary>Link existing equipment or purchase</summary>
            <form class="crm-form" @submit.prevent="resourceSave">
                <label class="crm-field"
                    ><span class="crm-field-label">Resource type</span
                    ><select
                        v-model="resource.target_type"
                        @change="resource.target_id = null"
                    >
                        <option
                            v-for="[type] in availableResources"
                            :key="type"
                            :value="type"
                        >
                            {{
                                type === "asset"
                                    ? "Equipment"
                                    : "Operations purchase"
                            }}
                        </option>
                    </select></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Existing resource</span
                    ><select v-model="resource.target_id" required>
                        <option :value="null">Choose a resource</option>
                        <option
                            v-for="item in resourceOptions[resource.target_type]
                                ?.items ?? []"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.label }}
                        </option>
                    </select></label
                ><Errors :errors="resource.errors" />
                <div class="crm-form-action">
                    <button class="crm-button" :disabled="resource.processing">
                        Link resource
                    </button>
                </div>
            </form>
        </details>
        <p v-else class="crm-muted">
            Equipment and purchasing links are unavailable. Project planning
            works independently.
        </p>
        <details
            v-if="canManageBudget && availablePayments.length"
            class="crm-form-disclosure"
        >
            <summary>Associate an existing Finance payment</summary>
            <form class="crm-form" @submit.prevent="paymentSave">
                <p>
                    Associate a whole recorded receipt or supplier payment. This
                    does not create or change a financial transaction.
                </p>
                <label class="crm-field"
                    ><span class="crm-field-label">Payment direction</span
                    ><select
                        v-model="payment.direction"
                        @change="payment.payment_id = null"
                    >
                        <option
                            v-for="[direction] in availablePayments"
                            :key="direction"
                            :value="direction"
                        >
                            {{
                                direction === "incoming"
                                    ? "Incoming receipt"
                                    : "Outgoing supplier payment"
                            }}
                        </option>
                    </select></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Recorded payment</span
                    ><select v-model="payment.payment_id" required>
                        <option :value="null">Choose a recorded payment</option>
                        <option
                            v-for="item in paymentOptions[payment.direction]
                                ?.items ?? []"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.label }}
                        </option>
                    </select></label
                ><Errors :errors="payment.errors" />
                <p v-if="currencyError" class="crm-notice">
                    {{ currencyError }}
                </p>
                <div class="crm-form-action">
                    <button
                        class="crm-button"
                        :disabled="payment.processing || !!currencyError"
                    >
                        Associate payment
                    </button>
                </div>
            </form>
        </details>
    </div>
</template>
