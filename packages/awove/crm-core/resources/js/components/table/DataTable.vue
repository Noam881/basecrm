<script setup lang="ts">
import { t, isRtl, localeTag } from "@/lib/i18n";
import { computed, ref, watch, onBeforeUnmount, type CSSProperties } from "vue";
import { Link, router, usePage } from "@inertiajs/vue3";
import {
    useVueTable,
    getCoreRowModel,
    type ColumnDef,
    type Column,
    type ColumnSizingState,
    type RowSelectionState,
} from "@tanstack/vue-table";
import type {
    TablePayload,
    TableRow,
    TableColumn,
    Filter,
    Sort,
    QueryState,
    SavedView,
    Value,
} from "./types";
import { clipboardCell } from "./clipboard";
import { exactDecimal } from "./decimal";
const clone = <T,>(value: T): T => JSON.parse(JSON.stringify(value));
const props = withDefaults(
    defineProps<{
        payload: TablePayload;
        endpoint: string;
        title: string;
        baseQuery?: Record<string, string>;
        rowLink?: boolean;
    }>(),
    { baseQuery: () => ({}), rowLink: true },
);
const page = usePage();
const zone = (page.props.brand as { timezone: string }).timezone;
const state = ref<QueryState>(clone(props.payload.state));
const order = ref([...props.payload.preferences.columns]);
const pinned = ref([...(props.payload.preferences.pinned ?? [])]);
const widths = ref<ColumnSizingState>({ ...props.payload.preferences.widths });
const density = ref(props.payload.preferences.density ?? "comfortable");
const wrap = ref(props.payload.preferences.wrap ?? false);
const views = ref<SavedView[]>(clone(props.payload.preferences.views ?? []));
const selected = ref<RowSelectionState>({});
const loading = ref(false);
const saveStatus = ref("");
const copied = ref("");
const columnSearch = ref("");
const dragging = ref<string | null>(null);
const dropTarget = ref<string | null>(null);
const fieldsDialog = ref<HTMLDialogElement>();
const filterDialog = ref<HTMLDialogElement>();
const sortDialog = ref<HTMLDialogElement>();
const viewDialog = ref<HTMLDialogElement>();
const columnDialog = ref<HTMLDialogElement>();
const activeColumn = ref<string>("");
const viewName = ref("");
const activeView = ref("");
let saveTimer: ReturnType<typeof setTimeout> | undefined;
let searchTimer: ReturnType<typeof setTimeout> | undefined;
let requestedState: string | null = null;
const catalog = computed(
    () => new Map(props.payload.columns.map((c) => [c.id, c])),
);
const defs = computed<ColumnDef<TableRow>[]>(() => [
    {
        id: "__select",
        size: 48,
        minSize: 48,
        maxSize: 48,
        enableResizing: false,
    },
    ...props.payload.columns.map((c) => ({
        id: c.id,
        accessorFn: (row: TableRow) => row.cells[c.id],
        header: columnLabel(c),
        size: c.width,
        minSize: 100,
        maxSize: 600,
    })),
]);
const table = useVueTable({
    get data() {
        return props.payload.page.data;
    },
    get columns() {
        return defs.value;
    },
    getCoreRowModel: getCoreRowModel(),
    getRowId: (row) => String(row.id),
    manualSorting: true,
    manualFiltering: true,
    manualPagination: true,
    columnResizeMode: "onChange",
    get columnResizeDirection() {
        return isRtl() ? "rtl" : "ltr";
    },
    state: {
        get columnOrder() {
            return ["__select", ...order.value];
        },
        get columnVisibility() {
            return Object.fromEntries(
                props.payload.columns.map((c) => [
                    c.id,
                    order.value.includes(c.id),
                ]),
            );
        },
        get columnPinning() {
            const ids = [
                "__select",
                ...pinned.value.filter((id) => order.value.includes(id)),
            ];
            return isRtl() ? { right: ids } : { left: ids };
        },
        get columnSizing() {
            return widths.value;
        },
        get rowSelection() {
            return selected.value;
        },
    },
    onColumnSizingChange: (update) => {
        widths.value =
            typeof update === "function" ? update(widths.value) : update;
        persist();
    },
    onRowSelectionChange: (update) =>
        (selected.value =
            typeof update === "function" ? update(selected.value) : update),
});
function columnLabel(column: TableColumn | undefined): string {
    if (!column) return "";
    return column.label;
}
// Only trusted technical field IDs get a forced text direction. Other authored
// values use their own first strong character without inheriting the table's RTL.
const ltrFields = new Set([
    "email",
    "phone",
    "secondary_phone",
    "contact_email",
    "contact_phone",
    "contact_secondary_phone",
    "company_email",
    "company_phone",
    "document_url",
    "materials_url",
    "landing_url",
    "asset_url",
    "publication_url",
]);
function cellDirection(id: string): "ltr" | "auto" {
    return ltrFields.has(id) ? "ltr" : "auto";
}
const displayed = computed(() => [
    ...(isRtl()
        ? table.getRightVisibleLeafColumns()
        : table.getLeftVisibleLeafColumns()),
    ...table.getCenterVisibleLeafColumns(),
]);
const selectedRows = computed(() => table.getSelectedRowModel().rows);
const filteredFields = computed(() =>
    props.payload.columns.filter((c) =>
        `${columnLabel(c)} ${c.group}`
            .toLowerCase()
            .includes(columnSearch.value.toLowerCase()),
    ),
);
const groups = computed(() => [
    ...new Set(filteredFields.value.map((c) => c.group)),
]);
const sortSummary = computed(() =>
    state.value.sorts
        .map(
            (s) =>
                `${columnLabel(catalog.value.get(s.id)) || s.id} ${s.direction === "asc" ? "↑" : "↓"}`,
        )
        .join(", "),
);
function symbol(c: TableColumn) {
    return ["date", "datetime"].includes(c.type)
        ? "event"
        : c.type === "number"
          ? "tag"
          : c.type === "boolean"
            ? "toggle_on"
            : c.type === "select"
              ? "list_alt"
              : "text_fields";
}
function labelDirection(c: TableColumn | undefined, d: "asc" | "desc") {
    if (c?.id === "priority" || c?.type === "number")
        return d === "asc" ? t("Low → high") : t("High → low");
    if (["date", "datetime"].includes(c?.type ?? ""))
        return d === "asc" ? t("Oldest → newest") : t("Newest → oldest");
    return d === "asc" ? t("A → Z") : t("Z → A");
}
const opNames: Record<string, string> = {
    contains: "contains",
    not_contains: "does not contain",
    eq: "is",
    neq: "is not",
    starts: "starts with",
    ends: "ends with",
    empty: "is empty",
    not_empty: "is not empty",
    gt: "greater / after",
    gte: "at least / on or after",
    lt: "less / before",
    lte: "at most / on or before",
    between: "is between",
    in: "is one of",
};
function format(value: Value | undefined, c: TableColumn) {
    if (value === null || value === undefined || value === "") return "—";
    if (c.type === "boolean")
        return ["true", "1"].includes(String(value)) ? t("Yes") : t("No");
    if (c.type === "number") return exactDecimal(value);
    if (["date", "datetime"].includes(c.type)) {
        const d = new Date(String(value));
        if (Number.isNaN(d.valueOf())) return String(value);
        return new Intl.DateTimeFormat(
            localeTag(),
            c.type === "date"
                ? { dateStyle: "medium", timeZone: "UTC" }
                : { dateStyle: "medium", timeStyle: "short", timeZone: zone },
        ).format(d);
    }
    // These columns are composed exclusively from controlled contact availability labels.
    // Localize display only; retained English filter/query values stay stable.
    if (
        [
            "preferred_contact_times",
            "preferred_contact_days",
            "contact_preferred_contact_times",
            "contact_preferred_contact_days",
        ].includes(c.id)
    ) {
        return String(value)
            .split(", ")
            .map((segment) => t(segment))
            .join(", ");
    }
    const option = c.options.find((o) => String(o.value) === String(value));
    return option ? option.label : String(value);
}
function link(row: TableRow) {
    if (!props.rowLink) return "";
    if (row.record_url !== undefined) {
        // Module-provided links stay inside this installation and never become script or external URLs.
        return typeof row.record_url === "string" &&
            /^\/(?![\\/])[^\s\\]*$/.test(row.record_url)
            ? row.record_url
            : "";
    }
    if (["contacts", "companies"].includes(props.payload.id)) {
        return `/people/${props.payload.id}/${row.id}`;
    }
    return props.payload.id === "leads"
        ? `/leads/${row.id}`
        : props.payload.id === "tasks"
          ? `/leads/${row.lead_id}`
          : "";
}
function isRecordColumn(row: TableRow, column: string) {
    if (row.record_link_column !== undefined)
        return row.record_link_column === column && catalog.value.has(column);
    return (
        ["title", "lead", "name"].includes(column) ||
        (props.payload.id === "leads" &&
            ["contact", "company"].includes(column) &&
            Boolean(row.cells[column]))
    );
}
function recordName(row: TableRow) {
    if (row.record_link_column && row.cells[row.record_link_column])
        return row.cells[row.record_link_column];
    return props.payload.id === "leads"
        ? (row.cells.contact ?? row.cells.company ?? row.title ?? row.id)
        : (row.title ?? row.name ?? row.id);
}
function cellStyle(column: Column<TableRow>): CSSProperties {
    return {
        width: `${column.getSize()}px`,
        minWidth: `${column.getSize()}px`,
        maxWidth: `${column.getSize()}px`,
        ...(column.getIsPinned()
            ? {
                  position: "sticky",
                  [isRtl() ? "right" : "left"]:
                      `${column.getStart(isRtl() ? "right" : "left")}px`,
                  zIndex: 2,
              }
            : {}),
    };
}
function query(pageNumber = 1) {
    return { ...props.baseQuery, ...clone(state.value), page: pageNumber };
}
function apply(pageNumber = 1) {
    if (!state.value.sorts.length)
        state.value.sorts = [
            {
                id: props.payload.id === "tasks" ? "due_at" : "created_at",
                direction: props.payload.id === "tasks" ? "asc" : "desc",
            },
        ];
    requestedState = JSON.stringify(state.value);
    loading.value = true;
    selected.value = {};
    router.get(props.endpoint, query(pageNumber), {
        preserveState: true,
        queryStringArrayFormat: "indices",
        preserveScroll: true,
        replace: true,
        onFinish: () => (loading.value = false),
    });
}
function search() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => apply(), 350);
}
function sorted(id: string, multiple = false, direction?: "asc" | "desc") {
    const current = state.value.sorts.find((s) => s.id === id);
    const next =
        direction ??
        (current?.direction === "asc"
            ? "desc"
            : current
              ? "asc"
              : catalog.value.get(id)?.type === "number" || id === "priority"
                ? "desc"
                : "asc");
    state.value.sorts = multiple
        ? [
              ...state.value.sorts.filter((s) => s.id !== id),
              { id, direction: next },
          ].slice(-5)
        : [{ id, direction: next }];
    activeView.value = "";
    apply();
}
function sortNumber(id: string) {
    return state.value.sorts.findIndex((s) => s.id === id) + 1;
}
function toggle(id: string) {
    if (order.value.includes(id)) {
        if (order.value.length === 1) return;
        order.value = order.value.filter((c) => c !== id);
    } else order.value = [...order.value, id];
    persist();
}
function move(id: string, delta: number, visual = true) {
    const next = [...order.value];
    const index = next.indexOf(id);
    const target = index + (visual && isRtl() ? -delta : delta);
    if (target < 0 || target >= next.length) return;
    next.splice(index, 1);
    next.splice(target, 0, id);
    order.value = next;
    persist();
}
function drop(id: string) {
    if (dragging.value && dragging.value !== id) {
        const next = order.value.filter((c) => c !== dragging.value);
        next.splice(next.indexOf(id), 0, dragging.value);
        order.value = next;
        persist();
    }
    dragging.value = null;
    dropTarget.value = null;
}
function startDrag(event: DragEvent, id: string) {
    dragging.value = id;
    event.dataTransfer?.setData("text/plain", id);
    if (event.dataTransfer) event.dataTransfer.effectAllowed = "move";
}
function pin(id: string) {
    pinned.value = pinned.value.includes(id)
        ? pinned.value.filter((p) => p !== id)
        : [...pinned.value, id];
    persist();
}
function reset() {
    order.value = [...props.payload.defaultColumns];
    widths.value = {};
    pinned.value = [];
    density.value = "comfortable";
    wrap.value = false;
    persist();
}
function openColumn(id: string) {
    activeColumn.value = id;
    columnDialog.value?.showModal();
}
function resize(id: string, delta: number) {
    const col = table.getColumn(id);
    if (!col) return;
    table.setColumnSizing((current) => ({
        ...current,
        [id]: Math.min(
            600,
            Math.max(100, col.getSize() + (isRtl() ? -delta : delta)),
        ),
    }));
}
function addFilter() {
    const c =
        props.payload.columns.find((c) => c.type === "text") ??
        props.payload.columns[0];
    state.value.filters.push({ id: c.id, op: c.operators[0], value: "" });
}
function changeField(f: Filter) {
    const c = catalog.value.get(f.id)!;
    f.op = c.operators[0];
    f.value = "";
    f.value2 = "";
}
function clearFilters() {
    state.value.filters = [];
    state.value.q = "";
    activeView.value = "";
    apply();
}
function preferenceBody() {
    return {
        table: props.payload.id,
        columns: order.value,
        widths: Object.fromEntries(
            Object.entries(widths.value).filter(([key]) =>
                catalog.value.has(key),
            ),
        ),
        pinned: pinned.value.filter((id) => catalog.value.has(id)),
        density: density.value,
        wrap: wrap.value,
        views: views.value,
    };
}
async function saveNow() {
    clearTimeout(saveTimer);
    saveTimer = undefined;
    saveStatus.value = t("Saving…");
    try {
        const token = decodeURIComponent(
            document.cookie
                .split("; ")
                .find((c) => c.startsWith("XSRF-TOKEN="))
                ?.split("=")[1] ?? "",
        );
        const response = await fetch("/preferences", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-XSRF-TOKEN": token,
            },
            body: JSON.stringify(preferenceBody()),
            keepalive: true,
        });
        if (!response.ok) {
            const body = await response.json();
            throw new Error(
                body.message ?? t("Could not save table preferences."),
            );
        }
        saveStatus.value = t("Layout saved");
    } catch (e) {
        saveStatus.value = String(e);
    }
}
function persist() {
    saveStatus.value = t("Saving…");
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveNow, 250);
}
function saveView() {
    if (!viewName.value.trim()) return;
    const view = {
        id: crypto.randomUUID(),
        name: viewName.value.trim(),
        columns: [...order.value],
        state: clone(state.value),
    };
    views.value = [...views.value, view];
    activeView.value = view.id;
    viewName.value = "";
    persist();
    viewDialog.value?.close();
}
function loadView() {
    const view = views.value.find((v) => v.id === activeView.value);
    if (!view) return;
    state.value = clone(view.state);
    order.value = [...view.columns];
    persist();
    apply();
}
function removeView() {
    views.value = views.value.filter((v) => v.id !== activeView.value);
    activeView.value = "";
    persist();
}
async function copyRows() {
    const columns = displayed.value.filter((c) => c.id !== "__select");
    const text = [
        columns
            .map((c) => clipboardCell(columnLabel(catalog.value.get(c.id))))
            .join("\t"),
        ...selectedRows.value.map((row) =>
            columns
                .map((c) =>
                    clipboardCell(
                        format(
                            row.original.cells[c.id],
                            catalog.value.get(c.id)!,
                        ),
                    ),
                )
                .join("\t"),
        ),
    ].join("\n");
    try {
        await navigator.clipboard.writeText(text);
        copied.value = t("Copied {value0} rows", {
            value0: String(selectedRows.value.length ?? ""),
        });
    } catch {
        copied.value = t("Clipboard permission unavailable.");
    }
}
watch(
    () => props.payload.state,
    (next) => {
        if (JSON.stringify(next) === requestedState) {
            requestedState = null;
            return;
        }
        if (!loading.value) {
            state.value = clone(next);
            selected.value = {};
        }
    },
    { deep: true },
);
watch(
    () => props.payload.id,
    () => {
        state.value = clone(props.payload.state);
        order.value = [...props.payload.preferences.columns];
        widths.value = { ...props.payload.preferences.widths };
        pinned.value = [...(props.payload.preferences.pinned ?? [])];
        density.value = props.payload.preferences.density ?? "comfortable";
        wrap.value = props.payload.preferences.wrap ?? false;
        views.value = clone(props.payload.preferences.views ?? []);
        selected.value = {};
        activeView.value = "";
    },
);
onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    if (saveTimer) {
        clearTimeout(saveTimer);
        void saveNow();
    }
});
</script>
<template>
    <section
        class="md-table"
        :data-density="density"
        :data-wrap="wrap"
        :aria-label="title"
        :aria-busy="loading"
    >
        <div class="md-table-title">
            <div>
                <h2>{{ title }}</h2>
                <span
                    >{{ payload.page.total.toLocaleString(localeTag()) }}
                    {{ t("matching records") }}</span
                >
            </div>
            <span class="md-table-save" role="status">{{ saveStatus }}</span>
        </div>
        <div class="md-table-toolbar">
            <form class="md-table-search" @submit.prevent="apply()">
                <span class="material-symbols-outlined" aria-hidden="true"
                    >search</span
                ><input
                    v-model="state.q"
                    :aria-label="
                        t('Search {value0}', { value0: title.toLowerCase() })
                    "
                    :placeholder="t('Search all fields…')"
                    @input="search"
                /><button
                    v-if="state.q"
                    type="button"
                    class="md-icon-button"
                    :aria-label="t('Clear search')"
                    @click="
                        state.q = '';
                        apply();
                    "
                >
                    <span class="material-symbols-outlined" aria-hidden="true"
                        >close</span
                    >
                </button>
            </form>
            <div class="md-table-tools">
                <button
                    class="crm-secondary"
                    @click="fieldsDialog?.showModal()"
                >
                    <span class="material-symbols-outlined" aria-hidden="true"
                        >view_column</span
                    >{{ t("Columns") }}
                    <span class="md-count">{{ order.length }}</span></button
                ><button
                    class="crm-secondary"
                    @click="filterDialog?.showModal()"
                >
                    <span class="material-symbols-outlined" aria-hidden="true"
                        >filter_list</span
                    >{{ t("Filters") }}
                    <span v-if="state.filters.length" class="md-count">{{
                        state.filters.length
                    }}</span></button
                ><button class="crm-secondary" @click="sortDialog?.showModal()">
                    <span class="material-symbols-outlined" aria-hidden="true"
                        >sort</span
                    >{{ t("Sort") }}
                    <span class="md-count">{{
                        state.sorts.length
                    }}</span></button
                ><button
                    class="md-icon-button"
                    :aria-label="
                        density === 'compact'
                            ? t('Comfortable rows')
                            : t('Compact rows')
                    "
                    :aria-pressed="density === 'compact'"
                    @click="
                        density =
                            density === 'compact' ? 'comfortable' : 'compact';
                        persist();
                    "
                >
                    <span class="material-symbols-outlined" aria-hidden="true"
                        >density_small</span
                    ></button
                ><button
                    class="md-icon-button"
                    :aria-label="t('Wrap cell text')"
                    :aria-pressed="wrap"
                    @click="
                        wrap = !wrap;
                        persist();
                    "
                >
                    <span class="material-symbols-outlined" aria-hidden="true"
                        >wrap_text</span
                    ></button
                ><button
                    class="md-icon-button"
                    :aria-label="t('Refresh table')"
                    @click="apply(payload.page.current_page)"
                >
                    <span class="material-symbols-outlined" aria-hidden="true"
                        >refresh</span
                    >
                </button>
            </div>
        </div>
        <div class="md-table-viewbar">
            <div class="md-table-saved">
                <span class="material-symbols-outlined" aria-hidden="true"
                    >bookmark_border</span
                ><select
                    v-model="activeView"
                    :aria-label="t('Saved table views')"
                    @change="loadView"
                >
                    <option value="">{{ t("Current view") }}</option>
                    <option
                        v-for="view in views"
                        :key="view.id"
                        :value="view.id"
                    >
                        {{ view.name }}
                    </option></select
                ><button
                    class="crm-ghost md-text-button"
                    @click="viewDialog?.showModal()"
                >
                    {{ t("Save view") }}</button
                ><button
                    v-if="activeView"
                    class="crm-ghost md-text-button"
                    @click="removeView"
                >
                    {{ t("Delete view") }}
                </button>
            </div>
            <span class="md-table-sort-summary" :title="sortSummary">{{
                sortSummary
            }}</span>
        </div>
        <div v-if="state.filters.length" class="md-table-chips">
            <button
                v-for="(f, index) in state.filters"
                :key="index"
                class="md-filter-chip"
                :aria-label="
                    t('Remove filter {value0}', {
                        value0: columnLabel(catalog.get(f.id)),
                    })
                "
                @click="
                    state.filters.splice(index, 1);
                    apply();
                "
            >
                {{ columnLabel(catalog.get(f.id)) }} {{ t(opNames[f.op]) }}
                {{ Array.isArray(f.value) ? f.value.join(", ") : f.value
                }}<span class="material-symbols-outlined" aria-hidden="true"
                    >close</span
                ></button
            ><button class="crm-ghost md-text-button" @click="clearFilters">
                {{ t("Clear filters") }}
            </button>
        </div>
        <div
            v-if="selectedRows.length"
            class="md-table-selection"
            role="status"
        >
            <strong
                >{{ selectedRows.length }}
                {{ t("selected on this page") }}</strong
            ><button class="crm-ghost md-text-button" @click="copyRows">
                {{ t("Copy rows") }}</button
            ><button
                class="crm-ghost md-text-button"
                @click="
                    selected = {};
                    copied = '';
                "
            >
                {{ t("Clear selection") }}</button
            ><span>{{ copied }}</span>
        </div>
        <div
            class="md-data-scroll"
            tabindex="0"
            :aria-label="
                t('{title} data, scroll horizontally for more columns', {
                    title,
                })
            "
        >
            <table
                :style="{ width: `${table.getTotalSize()}px` }"
                :aria-label="t('{title} records', { title })"
            >
                <thead>
                    <tr>
                        <th
                            v-for="column in displayed"
                            :key="column.id"
                            scope="col"
                            :style="cellStyle(column)"
                            :class="{
                                'md-pinned': column.getIsPinned(),
                                'md-drop-target': dropTarget === column.id,
                            }"
                            :aria-sort="
                                sortNumber(column.id)
                                    ? state.sorts.find(
                                          (s) => s.id === column.id,
                                      )?.direction === 'asc'
                                        ? 'ascending'
                                        : 'descending'
                                    : 'none'
                            "
                            @dragover.prevent="dropTarget = column.id"
                            @dragleave="dropTarget = null"
                            @drop.prevent="drop(column.id)"
                        >
                            <label
                                v-if="column.id === '__select'"
                                class="md-checkbox-target"
                                ><input
                                    type="checkbox"
                                    :aria-label="
                                        t('Select all rows on this page')
                                    "
                                    :checked="table.getIsAllPageRowsSelected()"
                                    :indeterminate="
                                        table.getIsSomePageRowsSelected()
                                    "
                                    @change="table.toggleAllPageRowsSelected()"
                            /></label>
                            <template v-else
                                ><div class="md-header-content">
                                    <button
                                        class="crm-ghost md-header-sort"
                                        :aria-label="
                                            t('Sort by {value0}', {
                                                value0: columnLabel(
                                                    catalog.get(column.id),
                                                ),
                                            })
                                        "
                                        @click="
                                            sorted(column.id, $event.shiftKey)
                                        "
                                    >
                                        <span class="md-header-label">{{
                                            columnLabel(catalog.get(column.id))
                                        }}</span
                                        ><span
                                            v-if="sortNumber(column.id)"
                                            class="material-symbols-outlined"
                                            aria-hidden="true"
                                            >{{
                                                state.sorts.find(
                                                    (s) => s.id === column.id,
                                                )?.direction === "asc"
                                                    ? "arrow_upward"
                                                    : "arrow_downward"
                                            }}</span
                                        ><sup
                                            v-if="
                                                sortNumber(column.id) &&
                                                state.sorts.length > 1
                                            "
                                            >{{ sortNumber(column.id) }}</sup
                                        ></button
                                    ><button
                                        class="crm-icon-control md-drag-handle"
                                        draggable="true"
                                        :aria-label="
                                            t(
                                                'Move {value0} column; Alt and arrow keys to reorder',
                                                {
                                                    value0: columnLabel(
                                                        catalog.get(column.id),
                                                    ),
                                                },
                                            )
                                        "
                                        @dragstart="
                                            startDrag($event, column.id)
                                        "
                                        @dragend="
                                            dragging = null;
                                            dropTarget = null;
                                        "
                                        @keydown.alt.left.prevent="
                                            move(column.id, -1)
                                        "
                                        @keydown.alt.right.prevent="
                                            move(column.id, 1)
                                        "
                                    >
                                        <span
                                            class="material-symbols-outlined"
                                            aria-hidden="true"
                                            >drag_indicator</span
                                        ></button
                                    ><button
                                        class="crm-icon-control md-header-menu"
                                        :aria-label="
                                            t('Options for {value0}', {
                                                value0: columnLabel(
                                                    catalog.get(column.id),
                                                ),
                                            })
                                        "
                                        @click="openColumn(column.id)"
                                    >
                                        <span
                                            class="material-symbols-outlined"
                                            aria-hidden="true"
                                            >more_vert</span
                                        >
                                    </button>
                                </div>
                                <div
                                    class="md-resize-handle"
                                    role="separator"
                                    aria-orientation="vertical"
                                    :aria-label="
                                        t('Resize {value0} column', {
                                            value0: columnLabel(
                                                catalog.get(column.id),
                                            ),
                                        })
                                    "
                                    :aria-valuenow="column.getSize()"
                                    tabindex="0"
                                    @mousedown="
                                        table
                                            .getHeaderGroups()[0]
                                            .headers.find(
                                                (h) =>
                                                    h.column.id === column.id,
                                            )
                                            ?.getResizeHandler()($event)
                                    "
                                    @touchstart="
                                        table
                                            .getHeaderGroups()[0]
                                            .headers.find(
                                                (h) =>
                                                    h.column.id === column.id,
                                            )
                                            ?.getResizeHandler()($event)
                                    "
                                    @dblclick="
                                        column.resetSize();
                                        persist();
                                    "
                                    @keydown.left.prevent="
                                        resize(column.id, -20)
                                    "
                                    @keydown.right.prevent="
                                        resize(column.id, 20)
                                    "
                            /></template>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in table.getRowModel().rows"
                        :key="row.id"
                        :class="{ 'md-row-selected': row.getIsSelected() }"
                    >
                        <td
                            v-for="column in displayed"
                            :key="column.id"
                            :style="cellStyle(column)"
                            :class="{
                                'md-pinned': column.getIsPinned(),
                                'md-numeric':
                                    catalog.get(column.id)?.type === 'number',
                            }"
                        >
                            <label
                                v-if="column.id === '__select'"
                                class="md-checkbox-target"
                                ><input
                                    type="checkbox"
                                    :aria-label="
                                        t('Select {value0}', {
                                            value0: String(
                                                recordName(row.original) ?? '',
                                            ),
                                        })
                                    "
                                    :checked="row.getIsSelected()"
                                    @change="row.toggleSelected()" /></label
                            ><slot
                                v-else
                                :name="`cell-${column.id}`"
                                :row="row.original"
                                :column="catalog.get(column.id)!"
                                ><Link
                                    v-if="
                                        link(row.original) &&
                                        isRecordColumn(row.original, column.id)
                                    "
                                    :href="link(row.original)"
                                    class="md-table-record"
                                    dir="auto"
                                    :title="
                                        format(
                                            row.original.cells[column.id],
                                            catalog.get(column.id)!,
                                        )
                                    "
                                    ><bdi :dir="cellDirection(column.id)">{{
                                        format(
                                            row.original.cells[column.id],
                                            catalog.get(column.id)!,
                                        )
                                    }}</bdi></Link
                                ><span
                                    v-else
                                    :title="
                                        format(
                                            row.original.cells[column.id],
                                            catalog.get(column.id)!,
                                        )
                                    "
                                    :class="{
                                        'md-cell-chip': [
                                            'status',
                                            'priority',
                                            'outcome',
                                        ].includes(column.id),
                                    }"
                                    ><bdi :dir="cellDirection(column.id)">{{
                                        format(
                                            row.original.cells[column.id],
                                            catalog.get(column.id)!,
                                        )
                                    }}</bdi></span
                                ></slot
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
            <div v-if="!payload.page.data.length" class="crm-empty">
                <span
                    class="material-symbols-outlined crm-empty-icon"
                    aria-hidden="true"
                    >search_off</span
                >
                <h3>{{ t("No matching records") }}</h3>
                <p>{{ t("Try a different search or remove a filter.") }}</p>
                <button
                    v-if="state.q || state.filters.length"
                    class="crm-ghost md-text-button"
                    @click="clearFilters"
                >
                    {{ t("Clear search and filters") }}
                </button>
            </div>
        </div>
        <footer class="md-table-footer">
            <span class="md-table-hint">{{
                t("Drag columns to reorder · Shift + click to multi-sort")
            }}</span
            ><label
                >{{ t("Rows per page")
                }}<select
                    v-model.number="state.per_page"
                    :aria-label="t('Rows per page')"
                    @change="apply()"
                >
                    <option
                        v-for="n in [10, 20, 25, 50, 100]"
                        :key="n"
                        :value="n"
                    >
                        {{ n }}
                    </option>
                </select></label
            ><span
                >{{ payload.page.from ?? 0 }}–{{ payload.page.to ?? 0 }}
                {{ t("of") }}
                {{ payload.page.total.toLocaleString(localeTag()) }}</span
            ><button
                class="md-icon-button"
                :aria-label="t('First page')"
                :disabled="payload.page.current_page <= 1 || loading"
                @click="apply(1)"
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >first_page</span
                ></button
            ><button
                class="md-icon-button"
                :aria-label="t('Previous page')"
                :disabled="payload.page.current_page <= 1 || loading"
                @click="apply(payload.page.current_page - 1)"
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >chevron_left</span
                ></button
            ><button
                class="md-icon-button"
                :aria-label="t('Next page')"
                :disabled="
                    payload.page.current_page >= payload.page.last_page ||
                    loading
                "
                @click="apply(payload.page.current_page + 1)"
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >chevron_right</span
                ></button
            ><button
                class="md-icon-button"
                :aria-label="t('Last page')"
                :disabled="
                    payload.page.current_page >= payload.page.last_page ||
                    loading
                "
                @click="apply(payload.page.last_page)"
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >last_page</span
                >
            </button>
        </footer>
        <div
            v-if="loading"
            class="md-table-progress"
            role="status"
            :aria-label="t('Loading records')"
        />
    </section>
    <dialog
        ref="fieldsDialog"
        :aria-label="t('Columns')"
        class="md-table-dialog md-table-sheet"
    >
        <header>
            <div>
                <h2>{{ t("Columns") }}</h2>
                <p>
                    {{
                        t(
                            "Choose any available field. Drag selected fields to reorder.",
                        )
                    }}
                </p>
            </div>
            <button
                class="md-icon-button"
                :aria-label="t('Close columns')"
                @click="fieldsDialog?.close()"
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >close</span
                >
            </button>
        </header>
        <div class="md-dialog-body">
            <label class="md-dialog-search"
                ><span class="material-symbols-outlined" aria-hidden="true"
                    >search</span
                ><input
                    v-model="columnSearch"
                    :placeholder="t('Find a field…')"
                    :aria-label="t('Find a column')"
            /></label>
            <div class="md-dialog-actions-inline">
                <button
                    class="crm-ghost md-text-button"
                    @click="
                        order = payload.columns.map((c) => c.id);
                        persist();
                    "
                >
                    {{ t("Show all fields") }}</button
                ><button class="crm-ghost md-text-button" @click="reset">
                    {{ t("Reset layout") }}
                </button>
            </div>
            <h3>{{ t("Visible columns ·") }} {{ order.length }}</h3>
            <ol class="md-column-order">
                <li
                    v-for="(id, index) in order"
                    :key="id"
                    draggable="true"
                    :class="{ 'md-drop-target': dropTarget === id }"
                    @dragstart="startDrag($event, id)"
                    @dragover.prevent="dropTarget = id"
                    @drop.prevent="drop(id)"
                    @dragend="
                        dragging = null;
                        dropTarget = null;
                    "
                >
                    <span class="material-symbols-outlined" aria-hidden="true"
                        >drag_indicator</span
                    ><span>{{ columnLabel(catalog.get(id)) }}</span
                    ><button
                        class="md-icon-button"
                        :aria-label="
                            t(
                                isRtl()
                                    ? 'Move {value0} right'
                                    : 'Move {value0} left',
                                {
                                    value0: columnLabel(catalog.get(id)),
                                },
                            )
                        "
                        :disabled="index === 0"
                        @click="move(id, -1, false)"
                    >
                        <span
                            class="material-symbols-outlined"
                            aria-hidden="true"
                            >arrow_upward</span
                        ></button
                    ><button
                        class="md-icon-button"
                        :aria-label="
                            t(
                                isRtl()
                                    ? 'Move {value0} left'
                                    : 'Move {value0} right',
                                {
                                    value0: columnLabel(catalog.get(id)),
                                },
                            )
                        "
                        :disabled="index === order.length - 1"
                        @click="move(id, 1, false)"
                    >
                        <span
                            class="material-symbols-outlined"
                            aria-hidden="true"
                            >arrow_downward</span
                        ></button
                    ><button
                        class="md-icon-button"
                        :aria-label="
                            t('Pin {value0}', {
                                value0: columnLabel(catalog.get(id)),
                            })
                        "
                        :aria-pressed="pinned.includes(id)"
                        @click="pin(id)"
                    >
                        <span
                            class="material-symbols-outlined"
                            aria-hidden="true"
                            >keep</span
                        >
                    </button>
                </li>
            </ol>
            <section
                v-for="group in groups"
                :key="group"
                class="md-field-group"
            >
                <h3>{{ group }}</h3>
                <label
                    v-for="field in filteredFields.filter(
                        (c) => c.group === group,
                    )"
                    :key="field.id"
                    class="md-field-choice"
                    ><input
                        type="checkbox"
                        :checked="order.includes(field.id)"
                        :aria-label="
                            t('Show {value0} column', {
                                value0: columnLabel(field),
                            })
                        "
                        :disabled="
                            order.length === 1 && order.includes(field.id)
                        "
                        @change="toggle(field.id)"
                    /><span
                        class="material-symbols-outlined"
                        aria-hidden="true"
                        >{{ symbol(field) }}</span
                    ><span>{{ columnLabel(field) }}</span
                    ><small>{{
                        t(
                            {
                                text: t("Text"),
                                number: t("Number"),
                                date: t("Date"),
                                datetime: t("Date and time"),
                                boolean: t("Yes / no"),
                                select: t("Selection"),
                            }[field.type],
                        )
                    }}</small></label
                >
            </section>
            <p v-if="!filteredFields.length">
                {{ t("No fields match your search.") }}
            </p>
        </div>
        <footer>
            <span role="status">{{ saveStatus }}</span
            ><button class="crm-button" @click="fieldsDialog?.close()">
                {{ t("Done") }}
            </button>
        </footer>
    </dialog>
    <dialog
        ref="filterDialog"
        :aria-label="t('Advanced filters')"
        class="md-table-dialog md-filter-dialog"
    >
        <header>
            <div>
                <h2>{{ t("Advanced filters") }}</h2>
                <p>
                    {{
                        t(
                            "Filter the entire result set by any available field.",
                        )
                    }}
                </p>
            </div>
            <button
                class="md-icon-button"
                :aria-label="t('Close filters')"
                @click="filterDialog?.close()"
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >close</span
                >
            </button>
        </header>
        <div class="md-dialog-body">
            <label class="md-match-choice"
                >{{ t("Match")
                }}<select
                    v-model="state.match"
                    :aria-label="t('Filter match mode')"
                >
                    <option value="all">{{ t("All conditions (AND)") }}</option>
                    <option value="any">{{ t("Any condition (OR)") }}</option>
                </select></label
            >
            <div
                v-for="(f, index) in state.filters"
                :key="index"
                class="md-filter-row"
            >
                <select
                    v-model="f.id"
                    :aria-label="
                        t('Filter {value0} field', {
                            value0: String(index + 1),
                        })
                    "
                    @change="changeField(f)"
                >
                    <optgroup
                        v-for="group in [
                            ...new Set(payload.columns.map((c) => c.group)),
                        ]"
                        :key="group"
                        :label="group"
                    >
                        <option
                            v-for="c in payload.columns.filter(
                                (c) => c.group === group,
                            )"
                            :key="c.id"
                            :value="c.id"
                        >
                            {{ columnLabel(c) }}
                        </option>
                    </optgroup></select
                ><select
                    v-model="f.op"
                    :aria-label="
                        t('Filter {value0} operator', {
                            value0: String(index + 1),
                        })
                    "
                    @change="f.value = f.op === 'in' ? [] : ''"
                >
                    <option
                        v-for="op in catalog.get(f.id)?.operators"
                        :key="op"
                        :value="op"
                    >
                        {{ t(opNames[op]) }}
                    </option></select
                ><template v-if="!['empty', 'not_empty'].includes(f.op)"
                    ><select
                        v-if="
                            catalog.get(f.id)?.options.length ||
                            catalog.get(f.id)?.type === 'boolean'
                        "
                        v-model="f.value"
                        :multiple="f.op === 'in'"
                        :aria-label="
                            t('Filter {value0} value', {
                                value0: String(index + 1),
                            })
                        "
                    >
                        <template v-if="catalog.get(f.id)?.type === 'boolean'"
                            ><option value="true">{{ t("Yes") }}</option>
                            <option value="false">
                                {{ t("No") }}
                            </option></template
                        >
                        <option
                            v-for="o in catalog.get(f.id)?.options"
                            :key="o.value"
                            :value="o.value"
                        >
                            {{ o.label }}
                        </option></select
                    ><input
                        v-else
                        v-model="f.value"
                        :type="
                            ['date', 'datetime'].includes(
                                catalog.get(f.id)?.type ?? '',
                            )
                                ? 'date'
                                : catalog.get(f.id)?.type === 'number'
                                  ? 'number'
                                  : 'text'
                        "
                        :aria-label="
                            t('Filter {value0} value', {
                                value0: String(index + 1),
                            })
                        "
                        :placeholder="t('Value')" /><input
                        v-if="f.op === 'between'"
                        v-model="f.value2"
                        :type="
                            ['date', 'datetime'].includes(
                                catalog.get(f.id)?.type ?? '',
                            )
                                ? 'date'
                                : 'number'
                        "
                        :aria-label="
                            t('Filter {value0} range end', {
                                value0: String(index + 1),
                            })
                        "
                        :placeholder="t('To')" /></template
                ><button
                    class="md-icon-button"
                    :aria-label="
                        t('Remove condition {value0}', {
                            value0: String(index + 1),
                        })
                    "
                    @click="state.filters.splice(index, 1)"
                >
                    <span class="material-symbols-outlined" aria-hidden="true"
                        >close</span
                    >
                </button>
            </div>
            <p v-if="!state.filters.length" class="crm-muted">
                {{
                    t("No conditions yet. All accessible records are included.")
                }}
            </p>
            <button
                class="crm-ghost md-text-button"
                :disabled="state.filters.length >= 20"
                @click="addFilter"
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >add</span
                >{{ t("Add condition") }}
            </button>
        </div>
        <footer>
            <button
                class="crm-ghost md-text-button"
                @click="
                    state.filters = [];
                    apply();
                    filterDialog?.close();
                "
            >
                {{ t("Clear filters") }}</button
            ><button
                class="crm-button"
                @click="
                    apply();
                    filterDialog?.close();
                "
            >
                {{ t("Apply filters") }}
            </button>
        </footer>
    </dialog>
    <dialog
        ref="sortDialog"
        :aria-label="t('Sort records')"
        class="md-table-dialog md-filter-dialog"
    >
        <header>
            <div>
                <h2>{{ t("Sort records") }}</h2>
                <p>
                    {{
                        t("Sort by up to five fields. Top rules take priority.")
                    }}
                </p>
            </div>
            <button
                class="md-icon-button"
                :aria-label="t('Close sorting')"
                @click="sortDialog?.close()"
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >close</span
                >
            </button>
        </header>
        <div class="md-dialog-body">
            <div
                v-for="(s, index) in state.sorts"
                :key="index"
                class="md-sort-row"
            >
                <span>{{ index + 1 }}</span
                ><select
                    v-model="s.id"
                    :aria-label="
                        t('Sort {value0} field', {
                            value0: String(index + 1),
                        })
                    "
                >
                    <option
                        v-for="c in payload.columns"
                        :key="c.id"
                        :value="c.id"
                    >
                        {{ columnLabel(c) }}
                    </option></select
                ><select
                    v-model="s.direction"
                    :aria-label="
                        t('Sort {value0} direction', {
                            value0: String(index + 1),
                        })
                    "
                >
                    <option value="asc">
                        {{ labelDirection(catalog.get(s.id), "asc") }}
                    </option>
                    <option value="desc">
                        {{ labelDirection(catalog.get(s.id), "desc") }}
                    </option></select
                ><button
                    class="md-icon-button"
                    :aria-label="
                        t('Remove sort {value0}', {
                            value0: String(index + 1),
                        })
                    "
                    @click="state.sorts.splice(index, 1)"
                >
                    <span class="material-symbols-outlined" aria-hidden="true"
                        >close</span
                    >
                </button>
            </div>
            <button
                class="crm-ghost md-text-button"
                :disabled="state.sorts.length >= 5"
                @click="
                    state.sorts.push({
                        id: payload.columns[0].id,
                        direction: 'asc',
                    })
                "
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >add</span
                >{{ t("Add sort") }}
            </button>
        </div>
        <footer>
            <span class="crm-muted">{{
                t("Empty values always appear last.")
            }}</span
            ><button
                class="crm-button"
                @click="
                    apply();
                    sortDialog?.close();
                "
            >
                {{ t("Apply sorting") }}
            </button>
        </footer>
    </dialog>
    <dialog
        ref="viewDialog"
        :aria-label="t('Save view')"
        class="md-table-dialog md-small-dialog"
    >
        <header>
            <h2>{{ t("Save this view") }}</h2>
            <button
                class="md-icon-button"
                :aria-label="t('Close save view')"
                @click="viewDialog?.close()"
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >close</span
                >
            </button>
        </header>
        <form @submit.prevent="saveView">
            <div class="md-dialog-body">
                <p>
                    {{
                        t(
                            "Save these columns, search, sorting and filters for your account.",
                        )
                    }}
                </p>
                <label
                    >{{ t("View name")
                    }}<input
                        v-model="viewName"
                        required
                        maxlength="60"
                        :placeholder="t('e.g. High-priority prospects')"
                /></label>
            </div>
            <footer>
                <button class="crm-button" :disabled="views.length >= 20">
                    {{ t("Save view") }}
                </button>
            </footer>
        </form>
    </dialog>
    <dialog
        ref="columnDialog"
        :aria-label="t('Column options')"
        class="md-table-dialog md-small-dialog"
    >
        <header>
            <h2>{{ columnLabel(catalog.get(activeColumn)) }}</h2>
            <button
                class="md-icon-button"
                :aria-label="t('Close column options')"
                @click="columnDialog?.close()"
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >close</span
                >
            </button>
        </header>
        <div class="md-dialog-body md-column-actions">
            <button
                class="crm-menu-item"
                @click="
                    sorted(activeColumn, false, 'asc');
                    columnDialog?.close();
                "
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >arrow_upward</span
                >{{ labelDirection(catalog.get(activeColumn), "asc") }}</button
            ><button
                class="crm-menu-item"
                @click="
                    sorted(activeColumn, false, 'desc');
                    columnDialog?.close();
                "
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >arrow_downward</span
                >{{ labelDirection(catalog.get(activeColumn), "desc") }}</button
            ><button
                class="crm-menu-item"
                :aria-pressed="pinned.includes(activeColumn)"
                @click="pin(activeColumn)"
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >keep</span
                >{{
                    pinned.includes(activeColumn)
                        ? t("Unpin column")
                        : t(isRtl() ? "Pin to right" : "Pin to left")
                }}</button
            ><button
                class="crm-menu-item"
                :disabled="
                    isRtl()
                        ? order.indexOf(activeColumn) >= order.length - 1
                        : order.indexOf(activeColumn) <= 0
                "
                @click="move(activeColumn, -1)"
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >arrow_back</span
                >{{ t("Move left") }}</button
            ><button
                class="crm-menu-item"
                :disabled="
                    isRtl()
                        ? order.indexOf(activeColumn) <= 0
                        : order.indexOf(activeColumn) >= order.length - 1
                "
                @click="move(activeColumn, 1)"
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >arrow_forward</span
                >{{ t("Move right") }}</button
            ><button
                class="crm-menu-item"
                :disabled="order.length <= 1"
                @click="
                    toggle(activeColumn);
                    columnDialog?.close();
                "
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >visibility_off</span
                >{{ t("Hide column") }}</button
            ><label
                >{{ t("Column width")
                }}<input
                    type="range"
                    min="100"
                    max="600"
                    step="10"
                    :value="table.getColumn(activeColumn)?.getSize()"
                    @input="
                        table.setColumnSizing((current) => ({
                            ...current,
                            [activeColumn]: Number(
                                ($event.target as HTMLInputElement).value,
                            ),
                        }))
                    "
            /></label>
        </div>
    </dialog>
</template>
