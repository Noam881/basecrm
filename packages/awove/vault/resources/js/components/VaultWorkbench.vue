<script setup lang="ts">
import { t } from "@/lib/i18n";
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from "vue";
import DataTable from "../../../../crm-core/resources/js/components/table/DataTable.vue";
import type {
    TablePayload,
    TableRow,
} from "../../../../crm-core/resources/js/components/table/types";
import VaultUnlock from "./VaultUnlock.vue";
import {
    currentGeneration,
    downloadFile,
    isUnlocked,
    request,
    securityGeneration,
    useVaultSecurity,
    VaultRequestError,
} from "../security";
import {
    fieldLabels,
    fieldTypes,
    queryString,
    type Content,
    type CustomerType,
    type Draft,
    type Field,
    type InitialData,
    type Item,
    type Options,
    type FileEntry,
} from "../types";
const p = withDefaults(
    defineProps<{
        endpoint?: string;
        initial?: InitialData;
        editable?: boolean;
    }>(),
    { endpoint: "/vault/api/items", editable: true },
);
const table = ref<TablePayload | null>(p.initial?.table ?? null);
const options = ref<Options>({
    customers: p.initial?.customers ?? [],
    types: p.initial?.types ?? [],
    actions: p.initial?.actions ?? {},
});
const selected = ref<Item | null>(null);
const content = ref<Content | null>(null);
const editing = ref(false);
const draft = ref<Draft | null>(null);
const revealed = ref<Record<string, boolean>>({});
const authVisible = ref(false);
const busy = ref(false);
const error = ref("");
const notice = ref("");
const errors = ref<Record<string, string[]>>({});
const confirmDelete = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);
const attachment = ref<File | null>(null);
const detailHeading = ref<HTMLElement | null>(null);
const pendingItem = ref<Item | null>(null);
const pendingCreate = ref(false);
const listQuery = ref<Record<string, unknown>>({});
const invitationUserId = ref("");
const issuedInvitation = ref("");
let operation = 0;
let monitoring: ReturnType<typeof setInterval> | undefined;
function wipe() {
    operation++;
    selected.value = null;
    content.value = null;
    draft.value = null;
    editing.value = false;
    revealed.value = {};
    confirmDelete.value = false;
    attachment.value = null;
    issuedInvitation.value = "";
    invitationUserId.value = "";
    if (fileInput.value) fileInput.value.value = "";
}
const security = useVaultSecurity(wipe);
const filter = computed(() => {
    const parsed = new URL(p.endpoint, window.location.origin);
    return Object.fromEntries(parsed.searchParams.entries());
});
const fixedCustomer = computed(() =>
    filter.value.customer_type && filter.value.customer_id
        ? {
              type: filter.value.customer_type as CustomerType,
              id: Number(filter.value.customer_id),
          }
        : null,
);
const countdown = computed(
    () =>
        `${Math.floor(security.remaining.value / 60)}:${String(security.remaining.value % 60).padStart(2, "0")}`,
);
const inputCustomer = computed({
    get: () =>
        draft.value?.customer_id
            ? `${draft.value.customer_type}:${draft.value.customer_id}`
            : "",
    set: (value: string) => {
        const [type, id] = value.split(":");
        if (draft.value) {
            draft.value.customer_type = type as CustomerType;
            draft.value.customer_id = Number(id) || null;
        }
    },
});
const currentActions = computed(
    () => selected.value?.actions ?? options.value.actions,
);
const title = computed(() =>
    editing.value
        ? selected.value
            ? t("Edit Vault item")
            : t("New Vault item")
        : (selected.value?.title ?? t("Vault item")),
);
function failed(e: unknown) {
    if (e instanceof Error && e.name === "AbortError") return;
    error.value =
        e instanceof VaultRequestError
            ? t(e.message)
            : t("The Vault request could not be completed.");
    errors.value = e instanceof VaultRequestError ? e.errors : {};
}
function clearErrors() {
    error.value = "";
    errors.value = {};
    notice.value = "";
}
async function load(query: Record<string, unknown> = listQuery.value) {
    listQuery.value = query;
    try {
        table.value = await request<TablePayload>(
            `/vault/api/items?${queryString({ ...query, ...filter.value })}`,
        );
    } catch (e) {
        table.value = null;
        failed(e);
    }
}
async function metadataOptions() {
    try {
        options.value = await request<Options>("/vault/api/options");
    } catch (e) {
        failed(e);
    }
}
async function focusDetails() {
    await nextTick();
    detailHeading.value?.focus();
    detailHeading.value?.scrollIntoView({
        block: "nearest",
        behavior: "smooth",
    });
}
async function sensitive<T>(
    url: string,
    method: string,
    body: unknown,
): Promise<T> {
    const captured = operation;
    const result = await request<T>(url, method, body, true);
    if (captured !== operation)
        throw new DOMException("Vault view closed", "AbortError");
    return result;
}
async function openItem(item: Item) {
    clearErrors();
    if (!item.actions?.read) {
        error.value = t("You do not have permission to read this Vault item.");
        return;
    }
    if (!isUnlocked()) {
        pendingItem.value = item;
        pendingCreate.value = false;
        authVisible.value = true;
        return;
    }
    wipe();
    busy.value = true;
    try {
        const result = await sensitive<Content>(
            `/vault/api/items/${encodeURIComponent(item.id)}/content`,
            "POST",
            {},
        );
        selected.value = result.item;
        content.value = result;
        await focusDetails();
    } catch (e) {
        failed(e);
    } finally {
        busy.value = false;
    }
}
function newDraft() {
    clearErrors();
    wipe();
    draft.value = {
        customer_type: fixedCustomer.value?.type ?? "contacts",
        customer_id: fixedCustomer.value?.id ?? null,
        title: "",
        type: options.value.types[0]?.id ?? "note",
        level: null,
        payload: { fields: [], notes: "" },
    };
    editing.value = true;
    appendTemplate();
    void focusDetails();
}
function createItem() {
    if (!isUnlocked()) {
        pendingCreate.value = true;
        pendingItem.value = null;
        authVisible.value = true;
    } else newDraft();
}
async function unlocked() {
    authVisible.value = false;
    const item = pendingItem.value;
    const create = pendingCreate.value;
    pendingItem.value = null;
    pendingCreate.value = false;
    if (item) await openItem(item);
    else if (create) newDraft();
}
function closeAuth() {
    authVisible.value = false;
    pendingItem.value = null;
    pendingCreate.value = false;
}
function appendTemplate() {
    if (!draft.value) return;
    const fields = draft.value.payload.fields;
    for (const field of options.value.types.find(
        (type) => type.id === draft.value?.type,
    )?.fields ?? []) {
        if (!fields.some((existing) => existing.id === field.id))
            fields.push({
                ...field,
                value: "",
                hidden: field.hidden ?? true,
                order: fields.length,
            });
    }
}
async function editItem() {
    if (!selected.value?.actions.update) return;
    busy.value = true;
    clearErrors();
    try {
        const result = await sensitive<Content>(
            `/vault/api/items/${encodeURIComponent(selected.value.id)}/content`,
            "POST",
            {},
        );
        selected.value = result.item;
        content.value = result;
        draft.value = {
            customer_type: result.item.customer_type,
            customer_id: result.item.customer_id,
            title: result.item.title,
            type: result.item.type,
            level: result.item.level,
            payload: {
                fields: result.payload.fields.map((field) => ({ ...field })),
                notes: result.payload.notes ?? "",
            },
        };
        revealed.value = {};
        editing.value = true;
        await focusDetails();
    } catch (e) {
        failed(e);
    } finally {
        busy.value = false;
    }
}
function addField() {
    if (draft.value)
        draft.value.payload.fields.push({
            id: `field-${crypto.randomUUID()}`,
            label: "",
            type: "password",
            value: "",
            hidden: true,
            order: draft.value.payload.fields.length,
        });
}
function moveField(index: number, direction: number) {
    if (!draft.value) return;
    const fields = draft.value.payload.fields;
    const destination = index + direction;
    if (destination < 0 || destination >= fields.length) return;
    [fields[index], fields[destination]] = [fields[destination], fields[index]];
    fields.forEach((field, i) => {
        field.order = i;
    });
}
function removeField(index: number) {
    draft.value?.payload.fields.splice(index, 1);
    draft.value?.payload.fields.forEach((field, i) => {
        field.order = i;
    });
}
function multiline(field: Field) {
    return ["multiline", "secret_multiline"].includes(field.type);
}
function concealed(field: Field) {
    return field.hidden !== false;
}
async function save() {
    if (!draft.value || !isUnlocked()) return;
    clearErrors();
    busy.value = true;
    try {
        const body = {
            ...draft.value,
            ...(selected.value ? { version: selected.value.version } : {}),
            payload: {
                ...draft.value.payload,
                fields: draft.value.payload.fields.map((field, order) => ({
                    ...field,
                    order,
                })),
            },
        };
        await sensitive<{ item: Item }>(
            selected.value
                ? `/vault/api/items/${encodeURIComponent(selected.value.id)}`
                : "/vault/api/items",
            selected.value ? "PATCH" : "POST",
            body,
        );
        wipe();
        notice.value = t("Vault item saved.");
        await load();
    } catch (e) {
        failed(e);
    } finally {
        busy.value = false;
    }
}
async function refreshContent() {
    if (!selected.value) throw new Error(t("Select a Vault item first."));
    const result = await sensitive<Content>(
        `/vault/api/items/${encodeURIComponent(selected.value.id)}/content`,
        "POST",
        {},
    );
    content.value = result;
    selected.value = result.item;
    return result;
}
async function showField(id: string) {
    if (revealed.value[id]) {
        revealed.value[id] = false;
        return;
    }
    clearErrors();
    busy.value = true;
    try {
        await refreshContent();
        revealed.value[id] = true;
    } catch (e) {
        failed(e);
    } finally {
        busy.value = false;
    }
}
async function copyField(id: string) {
    clearErrors();
    busy.value = true;
    try {
        const result = await refreshContent();
        const captured = securityGeneration();
        const value = result.payload.fields.find(
            (field) => field.id === id,
        )?.value;
        if (value === undefined || !currentGeneration(captured)) return;
        await navigator.clipboard.writeText(value ?? "");
        if (currentGeneration(captured)) notice.value = t("Copied.");
    } catch (e) {
        failed(e);
    } finally {
        busy.value = false;
    }
}
async function showNotes() {
    if (revealed.value.__notes) {
        revealed.value.__notes = false;
        return;
    }
    clearErrors();
    busy.value = true;
    try {
        await refreshContent();
        revealed.value.__notes = true;
    } catch (e) {
        failed(e);
    } finally {
        busy.value = false;
    }
}
async function copyNotes() {
    clearErrors();
    busy.value = true;
    try {
        const result = await refreshContent();
        const captured = securityGeneration();
        if (!currentGeneration(captured)) return;
        await navigator.clipboard.writeText(result.payload.notes ?? "");
        if (currentGeneration(captured)) notice.value = t("Copied.");
    } catch (e) {
        failed(e);
    } finally {
        busy.value = false;
    }
}
async function removeItem() {
    if (!selected.value) return;
    clearErrors();
    busy.value = true;
    try {
        await sensitive(
            `/vault/api/items/${encodeURIComponent(selected.value.id)}`,
            "DELETE",
            { version: selected.value.version },
        );
        wipe();
        notice.value = t("Vault item deleted.");
        await load();
    } catch (e) {
        failed(e);
    } finally {
        busy.value = false;
    }
}
async function upload() {
    if (!selected.value || !attachment.value) return;
    clearErrors();
    busy.value = true;
    try {
        const form = new FormData();
        form.append("file", attachment.value);
        form.append("version", String(selected.value.version));
        const result = await sensitive<{ item: Item; files: FileEntry[] }>(
            `/vault/api/items/${encodeURIComponent(selected.value.id)}/files`,
            "POST",
            form,
        );
        selected.value = result.item;
        if (content.value) {
            content.value.item = result.item;
            content.value.files = result.files;
        }
        attachment.value = null;
        if (fileInput.value) fileInput.value.value = "";
        notice.value = t("Encrypted attachment uploaded.");
        await load();
    } catch (e) {
        failed(e);
    } finally {
        busy.value = false;
    }
}
async function removeFile(id: string) {
    if (!selected.value) return;
    clearErrors();
    busy.value = true;
    try {
        const result = await sensitive<{ item: Item; files: FileEntry[] }>(
            `/vault/api/files/${encodeURIComponent(id)}`,
            "DELETE",
            { version: selected.value.version },
        );
        selected.value = result.item;
        if (content.value) {
            content.value.item = result.item;
            content.value.files = result.files;
        }
        await load();
    } catch (e) {
        failed(e);
    } finally {
        busy.value = false;
    }
}
async function download(id: string) {
    clearErrors();
    busy.value = true;
    try {
        const captured = operation;
        await downloadFile(id, () => captured === operation);
    } catch (e) {
        failed(e);
    } finally {
        busy.value = false;
    }
}
async function lock() {
    clearErrors();
    authVisible.value = false;
    pendingItem.value = null;
    pendingCreate.value = false;
    try {
        await security.lockVault();
        await load();
    } catch (e) {
        failed(e);
    }
}
async function approve() {
    clearErrors();
    busy.value = true;
    try {
        const result = await sensitive<{
            invitation: string;
            expires_at: string;
        }>("/vault/api/auth/approve", "POST", {
            user_id: Number(invitationUserId.value),
        });
        issuedInvitation.value = result.invitation;
        notice.value = t(
            "Share this invitation once through a protected channel. It is not emailed automatically.",
        );
    } catch (e) {
        failed(e);
    } finally {
        busy.value = false;
    }
}
function asItem(row: TableRow) {
    return row as unknown as Item;
}
watch(security.unlocked, (next, old) => {
    if (!next && old) {
        table.value = null;
        void load();
    } else if (next && !old) {
        void unlocked();
    }
});
onMounted(async () => {
    if (!p.initial) await Promise.all([load(), metadataOptions()]);
    monitoring = setInterval(async () => {
        if (
            !selected.value ||
            !isUnlocked() ||
            document.visibilityState !== "visible"
        )
            return;
        const id = selected.value.id;
        const version = selected.value.version;
        const captured = operation;
        try {
            const result = await request<{ item: Item }>(
                `/vault/api/items/${encodeURIComponent(id)}`,
            );
            if (captured !== operation) return;
            if (result.item.version !== version) {
                wipe();
                notice.value = t(
                    "This item changed. Reopen it before continuing.",
                );
                await load();
            } else {
                selected.value = result.item;
                if (content.value) content.value.item = result.item;
            }
        } catch (e) {
            wipe();
            failed(e);
            await load();
        }
    }, 10000);
});
onBeforeUnmount(() => {
    if (monitoring) clearInterval(monitoring);
});
</script>
<template>
    <div class="crm-flow">
        <section class="crm-card crm-flow" :aria-label="t('Vault status')">
            <div class="crm-form-action">
                <span
                    v-if="security.unlocked.value"
                    class="crm-badge"
                    role="timer"
                    aria-live="off"
                    >{{ t("Vault open") }} ·
                    <bdi dir="ltr">{{ countdown }}</bdi></span
                >
                <span v-else>{{ t("Vault locked") }}</span>
                <button
                    v-if="security.unlocked.value"
                    type="button"
                    class="crm-secondary"
                    @click="lock"
                >
                    {{ t("Lock Vault") }}
                </button>
                <button
                    v-else
                    type="button"
                    class="crm-button"
                    @click="authVisible = true"
                >
                    {{ t("Unlock Vault") }}
                </button>
                <button
                    v-if="options.actions.create && editable"
                    type="button"
                    class="crm-secondary"
                    :disabled="busy"
                    @click="createItem"
                >
                    {{ t("New Vault item") }}
                </button>
            </div>
            <p
                v-if="security.unlocked.value && security.remaining.value <= 30"
                role="status"
                class="crm-notice"
            >
                {{
                    t(
                        "The Vault will lock soon. Save your changes before the countdown ends.",
                    )
                }}
            </p>
            <p
                v-if="security.status.value?.dev_question_mode"
                class="crm-notice"
                role="alert"
            >
                {{
                    t(
                        "Development mode: fictional data only. The test question must never protect real information.",
                    )
                }}
            </p>
        </section>
        <p v-if="error" class="crm-notice" role="alert">{{ error }}</p>
        <ul v-if="Object.keys(errors).length" class="crm-notice" role="alert">
            <li v-for="(messages, key) in errors" :key="key">
                {{ messages.map((message) => t(message)).join(" ") }}
            </li>
        </ul>
        <p v-if="notice" class="crm-notice" role="status">{{ notice }}</p>
        <p v-if="busy" role="status">{{ t("Working…") }}</p>
        <VaultUnlock
            v-if="authVisible"
            @close="closeAuth"
            @unlocked="unlocked"
        />
        <DataTable
            v-if="table"
            :payload="table"
            endpoint="/vault/api/items"
            :title="t('Vault')"
            :base-query="filter"
            :load="load"
            :row-link="false"
        >
            <template #cell-title="{ row }"
                ><a
                    v-if="asItem(row).actions?.read"
                    class="md-table-record"
                    :href="`/vault#item=${row.id}`"
                    @click.prevent="openItem(asItem(row))"
                    >{{ row.cells.title }}</a
                ><span v-else>{{ row.cells.title }}</span></template
            >
            <template #cell-level="{ row }"
                ><span class="crm-badge">{{
                    Number(row.cells.level) === 1
                        ? t("Level 1 — Restricted access")
                        : t("Level 2 — Customer permissions")
                }}</span></template
            >
        </DataTable>
        <section
            v-if="selected || editing"
            class="crm-card crm-flow"
            aria-labelledby="vault-item-title"
        >
            <div class="crm-heading">
                <h2 id="vault-item-title" ref="detailHeading" tabindex="-1">
                    {{ title }}
                </h2>
                <button type="button" class="crm-secondary" @click="wipe">
                    {{ t("Close item") }}
                </button>
            </div>
            <form
                v-if="editing && draft"
                class="crm-form"
                autocomplete="off"
                @submit.prevent="save"
            >
                <fieldset
                    class="crm-form-grid"
                    :disabled="busy || !security.unlocked.value"
                >
                    <label class="crm-field"
                        ><span class="crm-field-label">{{
                            t("Item title")
                        }}</span
                        ><input
                            v-model="draft.title"
                            :aria-label="t('Item title')"
                            maxlength="160"
                            required
                        /><small>{{
                            t(
                                "Use a recognizable title. Do not put a password, full card number or private key in the title.",
                            )
                        }}</small></label
                    >
                    <label class="crm-field"
                        ><span class="crm-field-label">{{ t("Customer") }}</span
                        ><select
                            v-model="inputCustomer"
                            required
                            :disabled="
                                selected
                                    ? !currentActions.transfer
                                    : !!fixedCustomer
                            "
                        >
                            <option value="">{{ t("Select customer") }}</option>
                            <option
                                v-for="customer in options.customers"
                                :key="`${customer.type}:${customer.id}`"
                                :value="`${customer.type}:${customer.id}`"
                            >
                                {{ customer.name }} ·
                                {{
                                    customer.type === "contacts"
                                        ? t("Contact")
                                        : t("Company")
                                }}
                            </option>
                        </select></label
                    >
                    <label class="crm-field"
                        ><span class="crm-field-label">{{
                            t("Item type")
                        }}</span
                        ><select
                            v-model="draft.type"
                            required
                            @change="appendTemplate"
                        >
                            <option
                                v-for="type in options.types"
                                :key="type.id"
                                :value="type.id"
                            >
                                {{ type.label }}
                            </option></select
                        ><small>{{
                            t("Changing the template keeps existing fields.")
                        }}</small></label
                    >
                    <fieldset class="crm-field">
                        <legend>{{ t("Access level") }}</legend>
                        <label class="crm-checkbox"
                            ><input
                                v-model="draft.level"
                                type="radio"
                                name="vault-item-level"
                                :value="1"
                                required
                                :disabled="
                                    !!selected && !currentActions.change_level
                                "
                            />{{ t("Level 1 — Restricted access") }}</label
                        ><label class="crm-checkbox"
                            ><input
                                v-model="draft.level"
                                type="radio"
                                name="vault-item-level"
                                :value="2"
                                required
                                :disabled="
                                    !!selected && !currentActions.change_level
                                "
                            />{{ t("Level 2 — Customer permissions") }}</label
                        >
                    </fieldset>
                </fieldset>
                <p class="crm-notice">
                    {{
                        t(
                            "Never store card CVV/CVC, card PINs or magnetic-stripe data, including in notes or custom fields. Field-name checks cannot detect every prohibited value in free text.",
                        )
                    }}
                </p>
                <fieldset
                    v-for="(field, index) in draft.payload.fields"
                    :key="field.id"
                    class="crm-flow"
                    :disabled="busy || !security.unlocked.value"
                >
                    <legend>{{ t("Field") }} {{ index + 1 }}</legend>
                    <div class="crm-form-grid">
                        <label class="crm-field"
                            ><span class="crm-field-label">{{
                                t("Field label")
                            }}</span
                            ><input
                                v-model="field.label"
                                data-vault-sensitive
                                maxlength="120"
                                required
                        /></label>
                        <label class="crm-field"
                            ><span class="crm-field-label">{{
                                t("Field type")
                            }}</span
                            ><select v-model="field.type">
                                <option
                                    v-for="type in fieldTypes"
                                    :key="type"
                                    :value="type"
                                >
                                    {{ t(fieldLabels[type]) }}
                                </option>
                            </select></label
                        >
                    </div>
                    <label class="crm-checkbox"
                        ><input v-model="field.hidden" type="checkbox" />{{
                            t("Hide this value by default")
                        }}</label
                    >
                    <label
                        v-if="
                            !selected ||
                            !concealed(field) ||
                            revealed[field.id] ||
                            field.value === '' ||
                            field.value === null
                        "
                        class="crm-field"
                        ><span class="crm-field-label">{{
                            t("Field value")
                        }}</span
                        ><textarea
                            v-if="multiline(field)"
                            v-model="field.value"
                            data-vault-sensitive
                            rows="4"
                            dir="auto"
                            spellcheck="false"
                        ></textarea
                        ><input
                            v-else
                            v-model="field.value"
                            data-vault-sensitive
                            :type="
                                field.type === 'password' && !revealed[field.id]
                                    ? 'password'
                                    : ['url', 'email', 'date'].includes(
                                            field.type,
                                        )
                                      ? field.type
                                      : 'text'
                            "
                            :dir="
                                ['url', 'email', 'password', 'date'].includes(
                                    field.type,
                                )
                                    ? 'ltr'
                                    : 'auto'
                            "
                            autocomplete="off"
                            spellcheck="false"
                    /></label>
                    <p v-else class="crm-muted">
                        {{
                            t(
                                "Existing value is hidden and will be preserved unless you edit it.",
                            )
                        }}
                    </p>
                    <div class="crm-form-action">
                        <button
                            v-if="concealed(field)"
                            type="button"
                            class="crm-secondary"
                            @click="revealed[field.id] = !revealed[field.id]"
                        >
                            {{
                                revealed[field.id]
                                    ? t("Hide value")
                                    : t("Edit hidden value")
                            }}</button
                        ><button
                            type="button"
                            class="crm-secondary"
                            :disabled="index === 0"
                            @click="moveField(index, -1)"
                        >
                            {{ t("Move up") }}</button
                        ><button
                            type="button"
                            class="crm-secondary"
                            :disabled="
                                index === draft.payload.fields.length - 1
                            "
                            @click="moveField(index, 1)"
                        >
                            {{ t("Move down") }}</button
                        ><button
                            type="button"
                            class="crm-secondary"
                            @click="removeField(index)"
                        >
                            {{ t("Remove field") }}
                        </button>
                    </div>
                </fieldset>
                <div class="crm-form-action">
                    <button
                        type="button"
                        class="crm-secondary"
                        :disabled="busy || !security.unlocked.value"
                        @click="addField"
                    >
                        {{ t("Add custom field") }}
                    </button>
                </div>
                <label class="crm-field"
                    ><span class="crm-field-label">{{ t("Secure notes") }}</span
                    ><textarea
                        v-model="draft.payload.notes"
                        data-vault-sensitive
                        rows="4"
                        dir="auto"
                        :disabled="busy || !security.unlocked.value"
                    ></textarea>
                </label>
                <div class="crm-form-action">
                    <button
                        class="crm-button"
                        :disabled="busy || !security.unlocked.value"
                    >
                        {{ t("Save Vault item") }}</button
                    ><button type="button" class="crm-secondary" @click="wipe">
                        {{ t("Cancel") }}
                    </button>
                </div>
            </form>
            <template v-else-if="content && selected">
                <dl class="crm-detail-list">
                    <div class="crm-detail-item">
                        <dt>{{ t("Customer") }}</dt>
                        <dd dir="auto">{{ selected.customer_name }}</dd>
                    </div>
                    <div class="crm-detail-item">
                        <dt>{{ t("Access level") }}</dt>
                        <dd>
                            {{
                                selected.level === 1
                                    ? t("Level 1 — Restricted access")
                                    : t("Level 2 — Customer permissions")
                            }}
                        </dd>
                    </div>
                </dl>
                <article
                    v-for="field in content.payload.fields"
                    :key="field.id"
                    class="crm-flow"
                >
                    <strong data-vault-sensitive dir="auto">{{
                        field.label
                    }}</strong>
                    <pre
                        v-if="!concealed(field) || revealed[field.id]"
                        data-vault-sensitive
                        :dir="
                            [
                                'url',
                                'email',
                                'password',
                                'secret_multiline',
                                'date',
                            ].includes(field.type)
                                ? 'ltr'
                                : 'auto'
                        "
                        class="crm-system"
                        >{{ field.value || t("Not provided") }}</pre>
                    <p v-else>{{ t("Value hidden") }}</p>
                    <div class="crm-form-action">
                        <button
                            v-if="concealed(field)"
                            class="crm-secondary"
                            type="button"
                            :disabled="busy"
                            @click="showField(field.id)"
                        >
                            {{
                                revealed[field.id]
                                    ? t("Hide value")
                                    : t("Show value")
                            }}</button
                        ><button
                            class="crm-secondary"
                            type="button"
                            :disabled="busy"
                            @click="copyField(field.id)"
                        >
                            {{ t("Copy value") }}
                        </button>
                    </div>
                </article>
                <div v-if="content.payload.notes" class="crm-flow">
                    <h3>{{ t("Secure notes") }}</h3>
                    <pre
                        v-if="revealed.__notes"
                        data-vault-sensitive
                        dir="auto"
                        class="crm-system"
                        >{{ content.payload.notes }}</pre>
                    <p v-else>{{ t("Notes hidden") }}</p>
                    <div class="crm-form-action">
                        <button
                            type="button"
                            class="crm-secondary"
                            :disabled="busy"
                            @click="showNotes"
                        >
                            {{
                                revealed.__notes
                                    ? t("Hide notes")
                                    : t("Show notes")
                            }}</button
                        ><button
                            type="button"
                            class="crm-secondary"
                            :disabled="busy"
                            @click="copyNotes"
                        >
                            {{ t("Copy notes") }}
                        </button>
                    </div>
                </div>
                <section
                    class="crm-flow"
                    :aria-label="t('Encrypted attachments')"
                >
                    <h3>{{ t("Encrypted attachments") }}</h3>
                    <p v-if="!content.files.length" class="crm-muted">
                        {{ t("No attachments yet.") }}
                    </p>
                    <article
                        v-for="file in content.files"
                        :key="file.id"
                        class="crm-flow"
                    >
                        <strong data-vault-sensitive dir="auto">{{
                            file.name
                        }}</strong
                        ><small
                            >{{ Math.ceil(file.size_bytes / 1024) }}
                            {{ t("KB") }}</small
                        >
                        <div class="crm-form-action">
                            <button
                                v-if="currentActions.files_download"
                                type="button"
                                class="crm-secondary"
                                :disabled="busy"
                                @click="download(file.id)"
                            >
                                {{ t("Download attachment") }}</button
                            ><button
                                v-if="currentActions.files_delete && editable"
                                type="button"
                                class="crm-secondary"
                                :disabled="busy"
                                @click="removeFile(file.id)"
                            >
                                {{ t("Delete attachment") }}
                            </button>
                        </div>
                    </article>
                    <form
                        v-if="currentActions.files_upload && editable"
                        class="crm-form"
                        @submit.prevent="upload"
                    >
                        <label class="crm-field"
                            ><span class="crm-field-label">{{
                                t("Add encrypted attachment")
                            }}</span
                            ><input
                                ref="fileInput"
                                :aria-label="t('Add encrypted attachment')"
                                type="file"
                                data-vault-sensitive
                                accept=".pdf,.txt,.png,.jpg,.jpeg,.doc,.docx"
                                required
                                :disabled="busy"
                                @change="
                                    attachment =
                                        ($event.target as HTMLInputElement)
                                            .files?.[0] ?? null
                                "
                            /><small>{{
                                t(
                                    "Files are encrypted in private storage. Maximum size: 10 MB.",
                                )
                            }}</small></label
                        >
                        <div class="crm-form-action">
                            <button
                                class="crm-button"
                                :disabled="busy || !attachment"
                            >
                                {{ t("Upload encrypted attachment") }}
                            </button>
                        </div>
                    </form>
                </section>
                <div class="crm-form-action">
                    <button
                        v-if="currentActions.update && editable"
                        type="button"
                        class="crm-button"
                        :disabled="busy"
                        @click="editItem"
                    >
                        {{ t("Edit Vault item") }}</button
                    ><button
                        v-if="currentActions.delete && editable"
                        type="button"
                        class="crm-secondary"
                        :disabled="busy"
                        @click="confirmDelete = !confirmDelete"
                    >
                        {{ t("Delete Vault item") }}
                    </button>
                </div>
                <div v-if="confirmDelete" class="crm-flow" role="alert">
                    <p>
                        {{
                            t(
                                "Delete this Vault item? It will no longer appear in customer or Vault lists.",
                            )
                        }}
                    </p>
                    <div class="crm-form-action">
                        <button
                            class="crm-destructive"
                            type="button"
                            :disabled="busy"
                            @click="removeItem"
                        >
                            {{ t("Confirm deletion") }}</button
                        ><button
                            class="crm-secondary"
                            type="button"
                            @click="confirmDelete = false"
                        >
                            {{ t("Cancel") }}
                        </button>
                    </div>
                </div>
            </template>
        </section>
        <section
            v-if="
                security.unlocked.value &&
                security.status.value?.can_admin &&
                security.status.value?.auth_mode !== 'email_riddle'
            "
            class="crm-card crm-flow"
        >
            <details>
                <summary>{{ t("Approve Vault enrollment") }}</summary>
                <form class="crm-form" @submit.prevent="approve">
                    <label class="crm-field"
                        ><span class="crm-field-label">{{ t("User ID") }}</span
                        ><input
                            v-model="invitationUserId"
                            type="number"
                            min="1"
                            required
                            :disabled="busy"
                    /></label>
                    <div class="crm-form-action">
                        <button class="crm-secondary" :disabled="busy">
                            {{ t("Create protected invitation") }}
                        </button>
                    </div>
                </form>
                <label v-if="issuedInvitation" class="crm-field"
                    ><span class="crm-field-label">{{
                        t("One-time Vault invitation")
                    }}</span
                    ><input
                        :value="issuedInvitation"
                        data-vault-sensitive
                        readonly
                        dir="ltr"
                        autocomplete="off"
                /></label>
            </details>
        </section>
    </div>
</template>
