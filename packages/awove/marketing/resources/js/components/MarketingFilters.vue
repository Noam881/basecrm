<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { router } from "@inertiajs/vue3";
import { reactive } from "vue";
const p = defineProps<{
    endpoint: string;
    filters: Record<string, string>;
    owners: { id: number; name: string }[];
    channels: string[];
    reports?: boolean;
    campaignOptions?: { id: number; name: string }[];
}>();
const f = reactive({
    owner_id: p.filters.owner_id ?? "",
    campaign_id: p.filters.campaign_id ?? "",
    channel: p.filters.channel ?? "",
    from: p.filters.from ?? "",
    to: p.filters.to ?? "",
});
</script>
<template>
    <form
        class="crm-card crm-form-grid"
        @submit.prevent="router.get(endpoint, f)"
    >
        <label v-if="reports && campaignOptions?.length" class="crm-field"
            ><span class="crm-field-label">{{ tr("Campaign") }}</span
            ><select v-model="f.campaign_id">
                <option value="">{{ tr("All accessible campaigns") }}</option>
                <option v-for="c in campaignOptions" :key="c.id" :value="c.id">
                    {{ c.name }}
                </option>
            </select></label
        >
        <label class="crm-field"
            ><span class="crm-field-label">{{ tr("Owner") }}</span
            ><select v-model="f.owner_id">
                <option value="">{{ tr("All accessible owners") }}</option>
                <option v-for="o in owners" :key="o.id" :value="o.id">
                    {{ o.name }}
                </option>
            </select></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{ tr("Channel") }}</span
            ><select v-model="f.channel">
                <option value="">{{ tr("All channels") }}</option>
                <option v-for="c in channels" :key="c" :value="c">
                    {{ tr(c.charAt(0).toUpperCase() + c.slice(1)) }}
                </option>
            </select></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{ tr("From") }}</span
            ><input v-model="f.from" type="date" /></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{ tr("Through") }}</span
            ><input v-model="f.to" type="date"
        /></label>
        <div class="crm-form-action">
            <button class="crm-secondary">{{ tr("Apply filters") }}</button>
        </div>
        <p v-if="reports" class="crm-muted crm-form-span">
            {{
                tr(
                    "Includes whole reporting periods within the selected dates. Spend and leads are never prorated.",
                )
            }}
        </p>
    </form>
</template>
