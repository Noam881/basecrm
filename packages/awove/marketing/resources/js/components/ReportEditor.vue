<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { useForm } from "@inertiajs/vue3";
import type { Report } from "../types";
const p = defineProps<{
    campaignId: number;
    currency: string;
    report?: Report;
    available?: boolean;
}>();
const f = useForm({
    starts_on: p.report?.starts_on ?? "",
    ends_on: p.report?.ends_on ?? "",
    actual_spend: p.report?.actual_spend ?? "",
    reported_leads: p.report?.reported_leads ?? 0,
    notes: p.report?.notes ?? "",
    version: p.report?.version ?? 1,
});
function save() {
    if (p.report) {
        f.version = p.report.version;
        f.patch(`/marketing/campaigns/${p.campaignId}/reports/${p.report.id}`, {
            preserveScroll: true,
        });
    } else
        f.post(`/marketing/campaigns/${p.campaignId}/reports`, {
            preserveScroll: true,
            onSuccess: () => f.reset(),
        });
}
</script>
<template>
    <form class="crm-form-grid" @submit.prevent="save">
        <label class="crm-field"
            ><span class="crm-field-label">{{ tr("Period start") }}</span
            ><input v-model="f.starts_on" type="date" required /></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{ tr("Period end") }}</span
            ><input v-model="f.ends_on" type="date" required /></label
        ><label class="crm-field"
            ><span class="crm-field-label"
                >{{ tr("Actual spend (") }}{{ currency }})</span
            ><input
                v-model="f.actual_spend"
                type="text"
                inputmode="decimal"
                required /></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{ tr("Reported leads") }}</span
            ><input
                v-model="f.reported_leads"
                type="number"
                min="0"
                max="2147483647"
                step="1"
                required /></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{ tr("Performance notes") }}</span
            ><textarea v-model="f.notes" />
        </label>
        <div v-if="Object.keys(f.errors).length" class="crm-error" role="alert">
            <p v-for="(e, k) in f.errors" :key="k">{{ e }}</p>
        </div>
        <div class="crm-form-action">
            <button
                class="crm-button"
                :disabled="f.processing || available === false"
            >
                {{
                    report
                        ? tr("Save performance")
                        : tr("Add performance period")
                }}
            </button>
        </div>
    </form>
</template>
