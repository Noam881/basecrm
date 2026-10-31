<script setup lang="ts">
import { t as tr } from "@/lib/i18n";
import { router, Link } from "@inertiajs/vue3";
import { reactive } from "vue";
import type { Options, Section } from "../types";
defineOptions({ inheritAttrs: false });
const p = defineProps<
    Options & { section: Section; filters: Record<string, string> }
>();
const f = reactive({
    type: p.filters.type ?? "",
    location: p.filters.location ?? "",
    owner_id: p.filters.owner_id ?? "",
    status: p.filters.status ?? "",
    from: p.filters.from ?? "",
    to: p.filters.to ?? "",
    view: p.filters.view ?? "all",
});
</script>
<template>
    <form
        class="crm-card crm-form-grid"
        @submit.prevent="
            router.get('/assets', { ...f, section }, { preserveState: true })
        "
    >
        <label class="crm-field"
            ><span class="crm-field-label">{{ tr("Asset type") }}</span
            ><input v-model="f.type" maxlength="120" /></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{ tr("Location") }}</span
            ><input v-model="f.location" maxlength="180" /></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{
                tr("Responsible staff member")
            }}</span
            ><select
                v-model="f.owner_id"
                :aria-label="tr('Filter responsible staff member')"
            >
                <option value="">{{ tr("All accessible staff") }}</option>
                <option v-for="o in filterOwners" :key="o.id" :value="o.id">
                    {{ o.name }}
                </option>
            </select></label
        ><label v-if="section !== 'assignments'" class="crm-field"
            ><span class="crm-field-label">{{ tr("Status") }}</span
            ><select v-model="f.status" :aria-label="tr('Filter status')">
                <option value="">{{ tr("All statuses") }}</option>
                <option
                    v-for="(label, key) in section === 'maintenance'
                        ? maintenanceStatuses
                        : statuses"
                    :key="key"
                    :value="key"
                >
                    {{ label }}
                </option>
            </select></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{ tr("From date") }}</span
            ><input v-model="f.from" type="date" /></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{ tr("Through date") }}</span
            ><input
                v-model="f.to"
                type="date"
                :min="f.from || undefined" /></label
        ><label class="crm-field"
            ><span class="crm-field-label">{{ tr("View") }}</span
            ><select v-model="f.view" :aria-label="tr('Asset records view')">
                <option value="all">{{ tr("All current records") }}</option>
                <option v-if="section === 'assignments'" value="open">
                    {{ tr("Open assignments") }}
                </option>
                <option v-if="section === 'assignments'" value="returned">
                    {{ tr("Returned assignments") }}
                </option>
                <option v-if="section === 'maintenance'" value="open">
                    {{ tr("Open maintenance") }}
                </option>
                <option v-if="section !== 'register'" value="upcoming">
                    {{ tr("Upcoming (next 30 days)") }}
                </option>
                <option v-if="section !== 'register'" value="overdue">
                    {{ tr("Overdue") }}
                </option>
                <option value="archived">{{ tr("Archived") }}</option>
            </select></label
        >
        <p class="crm-muted crm-form-span">
            {{
                section === "register"
                    ? tr("Dates filter the recorded purchase date.")
                    : section === "assignments"
                      ? tr(
                            "Dates filter assignment dates. Upcoming and overdue views use the expected return date.",
                        )
                      : tr(
                            "Dates use the due date for open actions and the manual next due date for completed actions.",
                        )
            }}
        </p>
        <div class="crm-form-action">
            <button class="crm-secondary">
                {{ tr("Apply asset filters") }}</button
            ><Link class="crm-secondary" :href="`/assets?section=${section}`">{{
                tr("Clear filters")
            }}</Link>
        </div>
    </form>
</template>
