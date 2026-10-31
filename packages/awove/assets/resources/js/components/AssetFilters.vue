<script setup lang="ts">
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
            ><span class="crm-field-label">Asset type</span
            ><input v-model="f.type" maxlength="120" /></label
        ><label class="crm-field"
            ><span class="crm-field-label">Location</span
            ><input v-model="f.location" maxlength="180" /></label
        ><label class="crm-field"
            ><span class="crm-field-label">Responsible staff member</span
            ><select
                v-model="f.owner_id"
                aria-label="Filter responsible staff member"
            >
                <option value="">All accessible staff</option>
                <option v-for="o in filterOwners" :key="o.id" :value="o.id">
                    {{ o.name }}
                </option>
            </select></label
        ><label v-if="section !== 'assignments'" class="crm-field"
            ><span class="crm-field-label">Status</span
            ><select v-model="f.status" aria-label="Filter status">
                <option value="">All statuses</option>
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
            ><span class="crm-field-label">From date</span
            ><input v-model="f.from" type="date" /></label
        ><label class="crm-field"
            ><span class="crm-field-label">Through date</span
            ><input
                v-model="f.to"
                type="date"
                :min="f.from || undefined" /></label
        ><label class="crm-field"
            ><span class="crm-field-label">View</span
            ><select v-model="f.view" aria-label="Asset records view">
                <option value="all">All current records</option>
                <option v-if="section === 'assignments'" value="open">
                    Open assignments
                </option>
                <option v-if="section === 'assignments'" value="returned">
                    Returned assignments
                </option>
                <option v-if="section === 'maintenance'" value="open">
                    Open maintenance
                </option>
                <option v-if="section !== 'register'" value="upcoming">
                    Upcoming (next 30 days)
                </option>
                <option v-if="section !== 'register'" value="overdue">
                    Overdue
                </option>
                <option value="archived">Archived</option>
            </select></label
        >
        <p class="crm-muted crm-form-span">
            {{
                section === "register"
                    ? "Dates filter the recorded purchase date."
                    : section === "assignments"
                      ? "Dates filter assignment dates. Upcoming and overdue views use the expected return date."
                      : "Dates use the due date for open actions and the manual next due date for completed actions."
            }}
        </p>
        <div class="crm-form-action">
            <button class="crm-secondary">Apply asset filters</button
            ><Link class="crm-secondary" :href="`/assets?section=${section}`"
                >Clear filters</Link
            >
        </div>
    </form>
</template>
