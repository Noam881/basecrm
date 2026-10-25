<script setup lang="ts">
import { useForm, usePage } from "@inertiajs/vue3";
import { computed } from "vue";
import type { Options, MarketingRecord } from "../types";
defineOptions({ inheritAttrs: false });
const p = defineProps<
    Options & { record?: MarketingRecord; editable?: boolean }
>();
const f = useForm({
    name: p.record?.name ?? "",
    objective: p.record?.objective ?? "",
    title: p.record?.title ?? "",
    channel: p.record?.channel ?? "website",
    format: p.record?.format ?? "Post",
    owner_id: p.record
        ? p.record.owner_id
        : (usePage().props.auth as { user: { id: number } }).user.id,
    starts_on: p.record?.starts_on ?? "",
    ends_on: p.record?.ends_on ?? "",
    planned_budget: p.record?.planned_budget ?? "",
    materials_url: p.record?.materials_url ?? "",
    landing_url: p.record?.landing_url ?? "",
    status: p.record?.status ?? (p.kind === "campaigns" ? "planned" : "idea"),
    planned_on: p.record?.planned_on ?? "",
    draft: p.record?.draft ?? "",
    asset_url: p.record?.asset_url ?? "",
    publication_url: p.record?.publication_url ?? "",
    published_on: p.record?.published_on ?? "",
    version: p.record?.version ?? 1,
});
const errors = computed(() => f.errors as Record<string, string>);
const disabled = computed(
    () =>
        (!!p.record && !p.editable) ||
        (p.kind === "campaigns" && !p.currencyConsistent),
);
function save() {
    if (p.record) {
        f.version = p.record.version;
        f.patch(`/marketing/${p.kind}/${p.record.id}`, {
            preserveScroll: true,
        });
    } else f.post(`/marketing/${p.kind}`);
}
</script>
<template>
    <form class="crm-form" @submit.prevent="save">
        <fieldset :disabled="disabled" class="crm-form-grid">
            <template v-if="kind === 'campaigns'"
                ><label class="crm-field"
                    ><span class="crm-field-label">Campaign name</span
                    ><input v-model="f.name" required maxlength="160" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Objective</span
                    ><textarea
                        v-model="f.objective"
                        required
                    /></label></template
            ><template v-else
                ><label class="crm-field"
                    ><span class="crm-field-label">Content title</span
                    ><input v-model="f.title" required maxlength="160" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Format</span
                    ><input
                        v-model="f.format"
                        required
                        maxlength="80" /></label></template
            ><label class="crm-field"
                ><span class="crm-field-label">Channel</span
                ><select v-model="f.channel">
                    <option v-for="c in channels" :key="c" :value="c">
                        {{ c.charAt(0).toUpperCase() + c.slice(1) }}
                    </option>
                    <option
                        v-if="!channels.includes(f.channel)"
                        :value="f.channel"
                    >
                        {{ f.channel }}
                    </option>
                </select></label
            ><label class="crm-field"
                ><span class="crm-field-label">Owner</span
                ><select v-model="f.owner_id" :disabled="!canAssign">
                    <option :value="null">Unassigned</option>
                    <option v-for="o in owners" :key="o.id" :value="o.id">
                        {{ o.name }}
                    </option>
                    <option
                        v-if="
                            f.owner_id !== null &&
                            !owners.some((o) => o.id === f.owner_id)
                        "
                        :value="f.owner_id"
                    >
                        Current owner
                    </option>
                </select></label
            ><label class="crm-field"
                ><span class="crm-field-label">Status</span
                ><select v-model="f.status">
                    <option v-for="(v, k) in statuses" :key="k" :value="k">
                        {{ v }}
                    </option>
                </select></label
            ><template v-if="kind === 'campaigns'"
                ><label class="crm-field"
                    ><span class="crm-field-label">Start date</span
                    ><input v-model="f.starts_on" type="date" required /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">End date</span
                    ><input v-model="f.ends_on" type="date" required /></label
                ><label class="crm-field"
                    ><span class="crm-field-label"
                        >Planned budget ({{
                            record?.currency ?? currency
                        }})</span
                    ><input
                        v-model="f.planned_budget"
                        type="text"
                        inputmode="decimal"
                        required /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Materials link</span
                    ><input
                        v-model="f.materials_url"
                        type="url"
                        placeholder="https://" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Landing page link</span
                    ><input
                        v-model="f.landing_url"
                        type="url"
                        placeholder="https://" /></label></template
            ><template v-else
                ><label class="crm-field"
                    ><span class="crm-field-label"
                        >Planned publication date</span
                    ><input
                        v-model="f.planned_on"
                        type="date"
                        required /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Draft</span
                    ><textarea v-model="f.draft" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Asset link</span
                    ><input
                        v-model="f.asset_url"
                        type="url"
                        placeholder="https://" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Publication link</span
                    ><input
                        v-model="f.publication_url"
                        type="url"
                        :required="f.status === 'published'"
                        placeholder="https://" /></label
                ><label class="crm-field"
                    ><span class="crm-field-label">Actual publication date</span
                    ><input
                        v-model="f.published_on"
                        type="date"
                        :required="f.status === 'published'" /></label
            ></template>
        </fieldset>
        <div v-if="Object.keys(errors).length" class="crm-error" role="alert">
            <p v-for="(e, k) in errors" :key="k">{{ e }}</p>
        </div>
        <div v-if="!disabled" class="crm-form-action">
            <button class="crm-button" :disabled="f.processing">
                {{
                    record
                        ? "Save changes"
                        : kind === "campaigns"
                          ? "Create campaign"
                          : "Create content"
                }}
            </button>
        </div>
    </form>
</template>
