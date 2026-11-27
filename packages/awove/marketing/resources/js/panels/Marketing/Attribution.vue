<script setup lang="ts">
import Originals from "./Originals.vue";
import { t as tr } from "@/lib/i18n";
import { computed, ref, onMounted } from "vue";
import HistoryList, {
    type HistoryEntry,
} from "../../../../../crm-core/resources/js/components/HistoryList.vue";
const p = defineProps<{ endpoint: string; editable: boolean }>();
interface FieldDefinition {
    label: string;
    type: "text" | "url" | "select" | "boolean" | "textarea";
    max_length: number | null;
    options: string[];
}
interface Data {
    field_schema: Record<string, FieldDefinition>;
    version: number;
    history: HistoryEntry[];
    fields: Record<string, string | boolean | null>;
    linked_campaign_id: number | null;
    linked_campaign: string | null;
    has_hidden_campaign: boolean;
    has_legacy_snapshot: boolean;
    campaigns: { id: number; name: string }[];
    canEdit: boolean;
}
const data = ref<Data | null>(null);
let originalFields: Record<string, string | boolean | null> = {};
const error = ref("");
const saved = ref(false);
const busy = ref(false);
const labels = computed(() =>
    Object.fromEntries(
        Object.entries(data.value?.field_schema ?? {}).map(([key, field]) => [
            key,
            field.label,
        ]),
    ),
);
function textValue(key: string): string {
    const value = data.value?.fields[key];
    return typeof value === "string" ? value : "";
}
function setTextValue(key: string, event: Event) {
    if (data.value) {
        data.value.fields[key] = (
            event.target as HTMLInputElement | HTMLTextAreaElement
        ).value;
    }
}
function adopt(result: Data) {
    // Null is a presentation placeholder only. Saves still send changed fields,
    // preserving unregistered or malformed historical JSON values verbatim.
    result.fields = Object.fromEntries(
        Object.keys(result.field_schema).map((key) => [
            key,
            result.fields[key] ?? null,
        ]),
    );
    data.value = result;
    originalFields = { ...result.fields };
}
onMounted(async () => {
    try {
        const r = await fetch(p.endpoint, {
            headers: { Accept: "application/json" },
        });
        if (!r.ok)
            throw new Error(tr("Marketing attribution could not be loaded."));
        adopt(await r.json());
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
        adopt(result);
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
                    v-for="(field, key) in data.field_schema"
                    :key="key"
                    class="crm-field"
                    ><span class="crm-field-label">{{ field.label }}</span
                    ><select
                        v-if="field.type === 'boolean'"
                        v-model="data.fields[key]"
                    >
                        <option :value="null">{{ tr("Not recorded") }}</option>
                        <option :value="true">{{ tr("Yes") }}</option>
                        <option :value="false">{{ tr("No") }}</option></select
                    ><textarea
                        v-else-if="field.type === 'textarea'"
                        :value="textValue(key)"
                        @input="setTextValue(key, $event)"
                        :maxlength="field.max_length ?? undefined"
                        rows="3"
                    ></textarea
                    ><select
                        v-else-if="field.type === 'select'"
                        v-model="data.fields[key]"
                    >
                        <option :value="null">{{ tr("Not specified") }}</option>
                        <option v-for="c in field.options" :key="c" :value="c">
                            {{ tr(c) }}
                        </option></select
                    ><input
                        v-else
                        :value="textValue(key)"
                        @input="setTextValue(key, $event)"
                        :type="field.type === 'url' ? 'url' : 'text'"
                        :maxlength="field.max_length ?? undefined"
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
        <Originals
            :endpoint="p.endpoint.replace(/\/attribution$/, '/originals')"
        />
        <HistoryList
            v-if="data"
            :history="data.history"
            :title="tr('Attribution history')"
            :field-labels="{
                ...labels,
                linked_campaign_id: tr('Linked CRM campaign'),
            }"
        />
    </div>
</template>
