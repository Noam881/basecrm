<script setup lang="ts">
import { t as tr, translateLabels } from "@/lib/i18n";
import { ref, onMounted } from "vue";
import HistoryList, {
    type HistoryEntry,
} from "../../../../../crm-core/resources/js/components/HistoryList.vue";
const p = defineProps<{ endpoint: string; editable: boolean }>();
interface Data {
    version: number;
    history: HistoryEntry[];
    fields: Record<string, string | null>;
    linked_campaign_id: number | null;
    linked_campaign: string | null;
    has_hidden_campaign: boolean;
    has_legacy_snapshot: boolean;
    campaigns: { id: number; name: string }[];
    canEdit: boolean;
}
const data = ref<Data | null>(null);
let originalFields: Record<string, string | null> = {};
const error = ref("");
const saved = ref(false);
const busy = ref(false);
const labels: Record<string, string> = {
    platform: "Media / platform",
    landing_page_url: "Website / landing page",
    campaign_id: "Campaign ID",
    campaign_name: "Campaign name",
    ad_id: "Ad ID",
    ad_name: "Ad name",
    referrer_name: "Referred by",
    utm_source: "UTM source",
    utm_medium: "UTM medium",
    utm_campaign: "UTM campaign",
    utm_content: "UTM content",
};
onMounted(async () => {
    try {
        const r = await fetch(p.endpoint, {
            headers: { Accept: "application/json" },
        });
        if (!r.ok)
            throw new Error(tr("Marketing attribution could not be loaded."));
        data.value = await r.json();
        originalFields = { ...data.value!.fields };
    } catch (e) {
        error.value = String(e);
    }
});
async function save() {
    if (!data.value) return;
    busy.value = true;
    error.value = "";
    saved.value = false;
    try {
        const token = decodeURIComponent(
            document.cookie
                .split("; ")
                .find((c) => c.startsWith("XSRF-TOKEN="))
                ?.split("=")[1] ?? "",
        );
        const body = {
            version: data.value.version,
            fields: Object.fromEntries(
                Object.entries(data.value.fields).filter(
                    ([key, value]) => value !== originalFields[key],
                ),
            ),
            ...(!data.value.has_hidden_campaign ||
            data.value.linked_campaign_id !== null
                ? { linked_campaign_id: data.value.linked_campaign_id }
                : {}),
        };
        const r = await fetch(p.endpoint, {
            method: "PATCH",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-XSRF-TOKEN": token,
            },
            body: JSON.stringify(body),
        });
        const result = await r.json();
        if (!r.ok)
            throw new Error(
                result.message ?? tr("Unable to save attribution."),
            );
        data.value = result;
        originalFields = { ...result.fields };
        saved.value = true;
    } catch (e) {
        error.value = String(e);
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <div class="crm-flow">
        <p v-if="error" class="crm-notice" role="alert">{{ error }}</p>
        <form v-if="data" class="crm-form" @submit.prevent="save">
            <p v-if="data.has_legacy_snapshot" class="crm-muted">
                {{
                    tr(
                        "The original attribution was preserved in an immutable migration snapshot. Linked CRM campaign is separate from external campaign IDs.",
                    )
                }}
            </p>
            <fieldset
                class="crm-form-grid"
                :disabled="!editable || !data.canEdit"
            >
                <label class="crm-field"
                    ><span class="crm-field-label">{{
                        tr("Linked CRM campaign")
                    }}</span
                    ><select v-model="data.linked_campaign_id">
                        <option :value="null">
                            {{
                                data.has_hidden_campaign
                                    ? tr("Existing campaign unavailable")
                                    : tr("No linked campaign")
                            }}
                        </option>
                        <option
                            v-for="c in data.campaigns"
                            :key="c.id"
                            :value="c.id"
                        >
                            {{ c.name }}
                        </option>
                        <option
                            v-if="
                                data.linked_campaign_id &&
                                !data.campaigns.some(
                                    (c) => c.id === data?.linked_campaign_id,
                                )
                            "
                            :value="data.linked_campaign_id"
                        >
                            {{ data.linked_campaign }}
                        </option>
                    </select></label
                ><label
                    v-for="(label, key) in labels"
                    :key="key"
                    class="crm-field"
                    ><span class="crm-field-label">{{ tr(label) }}</span
                    ><select
                        v-if="key === 'platform'"
                        v-model="data.fields[key]"
                    >
                        <option :value="null">{{ tr("Not specified") }}</option>
                        <option
                            v-for="c in [
                                'website',
                                'facebook',
                                'instagram',
                                'linkedin',
                                'google',
                                'referral',
                                'email',
                                'direct',
                                'other',
                            ]"
                            :key="c"
                            :value="c"
                        >
                            {{ tr(c) }}
                        </option></select
                    ><input
                        v-else
                        v-model="data.fields[key]"
                        :type="key === 'landing_page_url' ? 'url' : 'text'"
                        :maxlength="key === 'landing_page_url' ? 2048 : 200"
                /></label>
            </fieldset>
            <p v-if="saved" class="crm-notice" role="status">
                {{ tr("Marketing attribution saved.") }}
            </p>
            <div v-if="editable && data.canEdit" class="crm-form-action">
                <button class="crm-button" :disabled="busy">
                    {{ tr("Save attribution") }}
                </button>
            </div>
        </form>
        <p v-else-if="!error">{{ tr("Loading attribution…") }}</p>
        <HistoryList
            v-if="data"
            :history="data.history"
            :title="tr('Attribution history')"
            :field-labels="
                translateLabels({
                    ...labels,
                    linked_campaign_id: 'Linked CRM campaign',
                })
            "
        />
    </div>
</template>
