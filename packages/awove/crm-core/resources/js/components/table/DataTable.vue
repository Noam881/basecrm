<script setup lang="ts">
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
        header: c.label,
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
            return {
                left: [
                    "__select",
                    ...pinned.value.filter((id) => order.value.includes(id)),
                ],
            };
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
const displayed = computed(() => [
    ...table.getLeftVisibleLeafColumns(),
    ...table.getCenterVisibleLeafColumns(),
]);
const selectedRows = computed(() => table.getSelectedRowModel().rows);
const filteredFields = computed(() =>
    props.payload.columns.filter((c) =>
        `${c.label} ${c.group}`
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
                `${catalog.value.get(s.id)?.label ?? s.id} ${s.direction === "asc" ? "↑" : "↓"}`,
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
        return d === "asc" ? "Low → high" : "High → low";
    if (["date", "datetime"].includes(c?.type ?? ""))
        return d === "asc" ? "Oldest → newest" : "Newest → oldest";
    return d === "asc" ? "A → Z" : "Z → A";
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
        return ["true", "1"].includes(String(value)) ? "Yes" : "No";
    if (c.type === "number")
        return new Intl.NumberFormat("en", { maximumFractionDigits: 6 }).format(
            Number(value),
        );
    if (["date", "datetime"].includes(c.type)) {
        const d = new Date(String(value));
        if (Number.isNaN(d.valueOf())) return String(value);
        return new Intl.DateTimeFormat(
            "en",
            c.type === "date"
                ? { dateStyle: "medium", timeZone: "UTC" }
                : { dateStyle: "medium", timeStyle: "short", timeZone: zone },
        ).format(d);
    }
    return (
        c.options.find((o) => String(o.value) === String(value))?.label ??
        String(value)
    );
}
function link(row: TableRow) {
    if (!props.rowLink) return "";
    if (["contacts", "companies"].includes(props.payload.id)) {
        return `/people/${props.payload.id}/${row.id}`;
    }
    return props.payload.id === "leads"
        ? `/leads/${row.id}`
        : props.payload.id === "tasks"
          ? `/leads/${row.lead_id}`
          : "";
}
function cellStyle(column: Column<TableRow>): CSSProperties {
    return {
        width: `${column.getSize()}px`,
        minWidth: `${column.getSize()}px`,
        maxWidth: `${column.getSize()}px`,
        ...(column.getIsPinned()
            ? {
                  position: "sticky",
                  left: `${column.getStart("left")}px`,
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
function move(id: string, delta: number) {
    const next = [...order.value];
    const index = next.indexOf(id);
    const target = index + delta;
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
        [id]: Math.min(600, Math.max(100, col.getSize() + delta)),
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
    saveStatus.value = "Saving…";
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
                body.message ?? "Could not save table preferences.",
            );
        }
        saveStatus.value = "Layout saved";
    } catch (e) {
        saveStatus.value = String(e);
    }
}
function persist() {
    saveStatus.value = "Saving…";
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
        columns.map((c) => catalog.value.get(c.id)!.label).join("\t"),
        ...selectedRows.value.map((row) =>
            columns
                .map((c) =>
                    format(row.original.cells[c.id], catalog.value.get(c.id)!)
                        .replaceAll("\t", " ")
                        .replaceAll("\n", " "),
                )
                .join("\t"),
        ),
    ].join("\n");
    try {
        await navigator.clipboard.writeText(text);
        copied.value = `Copied ${selectedRows.value.length} rows`;
    } catch {
        copied.value = "Clipboard permission unavailable.";
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
                    >{{ payload.page.total.toLocaleString() }} matching
                    records</span
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
                    :aria-label="`Search ${title.toLowerCase()}`"
                    placeholder="Search all fields…"
                    @input="search"
                /><button
                    v-if="state.q"
                    type="button"
                    class="md-icon-button"
                    aria-label="Clear search"
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
                    >Columns
                    <span class="md-count">{{ order.length }}</span></button
                ><button
                    class="crm-secondary"
                    @click="filterDialog?.showModal()"
                >
                    <span class="material-symbols-outlined" aria-hidden="true"
                        >filter_list</span
                    >Filters
                    <span v-if="state.filters.length" class="md-count">{{
                        state.filters.length
                    }}</span></button
                ><button class="crm-secondary" @click="sortDialog?.showModal()">
                    <span class="material-symbols-outlined" aria-hidden="true"
                        >sort</span
                    >Sort
                    <span class="md-count">{{
                        state.sorts.length
                    }}</span></button
                ><button
                    class="md-icon-button"
                    :aria-label="
                        density === 'compact'
                            ? 'Comfortable rows'
                            : 'Compact rows'
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
                    aria-label="Wrap cell text"
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
                    aria-label="Refresh table"
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
                    aria-label="Saved table views"
                    @change="loadView"
                >
                    <option value="">Current view</option>
                    <option
                        v-for="view in views"
                        :key="view.id"
                        :value="view.id"
                    >
                        {{ view.name }}
                    </option></select
                ><button
                    class="md-text-button"
                    @click="viewDialog?.showModal()"
                >
                    Save view</button
                ><button
                    v-if="activeView"
                    class="md-text-button"
                    @click="removeView"
                >
                    Delete view
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
                :aria-label="`Remove filter ${catalog.get(f.id)?.label}`"
                @click="
                    state.filters.splice(index, 1);
                    apply();
                "
            >
                {{ catalog.get(f.id)?.label }} {{ opNames[f.op] }}
                {{ Array.isArray(f.value) ? f.value.join(", ") : f.value
                }}<span class="material-symbols-outlined" aria-hidden="true"
                    >close</span
                ></button
            ><button class="md-text-button" @click="clearFilters">
                Clear filters
            </button>
        </div>
        <div
            v-if="selectedRows.length"
            class="md-table-selection"
            role="status"
        >
            <strong>{{ selectedRows.length }} selected on this page</strong
            ><button class="md-text-button" @click="copyRows">Copy rows</button
            ><button
                class="md-text-button"
                @click="
                    selected = {};
                    copied = '';
                "
            >
                Clear selection</button
            ><span>{{ copied }}</span>
        </div>
        <div
            class="md-data-scroll"
            tabindex="0"
            :aria-label="`${title} data, scroll horizontally for more columns`"
        >
            <table
                :style="{ width: `${table.getTotalSize()}px` }"
                :aria-label="`${title} records`"
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
                            <input
                                v-if="column.id === '__select'"
                                type="checkbox"
                                aria-label="Select all rows on this page"
                                :checked="table.getIsAllPageRowsSelected()"
                                :indeterminate="
                                    table.getIsSomePageRowsSelected()
                                "
                                @change="table.toggleAllPageRowsSelected()"
                            />
                            <template v-else
                                ><div class="md-header-content">
                                    <button
                                        class="md-header-sort"
                                        :aria-label="`Sort by ${catalog.get(column.id)?.label}`"
                                        @click="
                                            sorted(column.id, $event.shiftKey)
                                        "
                                    >
                                        <span class="md-header-label">{{
                                            catalog.get(column.id)?.label
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
                                        class="md-drag-handle"
                                        draggable="true"
                                        :aria-label="`Move ${catalog.get(column.id)?.label} column; Alt and arrow keys to reorder`"
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
                                        class="md-header-menu"
                                        :aria-label="`Options for ${catalog.get(column.id)?.label}`"
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
                                    :aria-label="`Resize ${catalog.get(column.id)?.label} column`"
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
                            <input
                                v-if="column.id === '__select'"
                                type="checkbox"
                                :aria-label="`Select ${row.original.title ?? row.original.name ?? row.id}`"
                                :checked="row.getIsSelected()"
                                @change="row.toggleSelected()"
                            /><template v-else
                                ><Link
                                    v-if="
                                        link(row.original) &&
                                        ['title', 'lead', 'name'].includes(
                                            column.id,
                                        )
                                    "
                                    :href="link(row.original)"
                                    class="md-table-record"
                                    :title="
                                        format(
                                            row.original.cells[column.id],
                                            catalog.get(column.id)!,
                                        )
                                    "
                                    >{{
                                        format(
                                            row.original.cells[column.id],
                                            catalog.get(column.id)!,
                                        )
                                    }}</Link
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
                                    >{{
                                        format(
                                            row.original.cells[column.id],
                                            catalog.get(column.id)!,
                                        )
                                    }}</span
                                ></template
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
                <h3>No matching records</h3>
                <p>Try a different search or remove a filter.</p>
                <button
                    v-if="state.q || state.filters.length"
                    class="md-text-button"
                    @click="clearFilters"
                >
                    Clear search and filters
                </button>
            </div>
        </div>
        <footer class="md-table-footer">
            <span class="md-table-hint"
                >Drag columns to reorder · Shift + click to multi-sort</span
            ><label
                >Rows per page<select
                    v-model.number="state.per_page"
                    aria-label="Rows per page"
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
                >{{ payload.page.from ?? 0 }}–{{ payload.page.to ?? 0 }} of
                {{ payload.page.total.toLocaleString() }}</span
            ><button
                class="md-icon-button"
                aria-label="First page"
                :disabled="payload.page.current_page <= 1 || loading"
                @click="apply(1)"
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >first_page</span
                ></button
            ><button
                class="md-icon-button"
                aria-label="Previous page"
                :disabled="payload.page.current_page <= 1 || loading"
                @click="apply(payload.page.current_page - 1)"
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >chevron_left</span
                ></button
            ><button
                class="md-icon-button"
                aria-label="Next page"
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
                aria-label="Last page"
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
            aria-label="Loading records"
        />
    </section>
    <dialog
        ref="fieldsDialog"
        aria-label="Columns"
        class="md-table-dialog md-table-sheet"
    >
        <header>
            <div>
                <h2>Columns</h2>
                <p>
                    Choose any available field. Drag selected fields to reorder.
                </p>
            </div>
            <button
                class="md-icon-button"
                aria-label="Close columns"
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
                    placeholder="Find a field…"
                    aria-label="Find a column"
            /></label>
            <div class="md-dialog-actions-inline">
                <button
                    class="md-text-button"
                    @click="
                        order = payload.columns.map((c) => c.id);
                        persist();
                    "
                >
                    Show all fields</button
                ><button class="md-text-button" @click="reset">
                    Reset layout
                </button>
            </div>
            <h3>Visible columns · {{ order.length }}</h3>
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
                    ><span>{{ catalog.get(id)?.label }}</span
                    ><button
                        class="md-icon-button"
                        :aria-label="`Move ${catalog.get(id)?.label} left`"
                        :disabled="index === 0"
                        @click="move(id, -1)"
                    >
                        <span
                            class="material-symbols-outlined"
                            aria-hidden="true"
                            >arrow_upward</span
                        ></button
                    ><button
                        class="md-icon-button"
                        :aria-label="`Move ${catalog.get(id)?.label} right`"
                        :disabled="index === order.length - 1"
                        @click="move(id, 1)"
                    >
                        <span
                            class="material-symbols-outlined"
                            aria-hidden="true"
                            >arrow_downward</span
                        ></button
                    ><button
                        class="md-icon-button"
                        :aria-label="`Pin ${catalog.get(id)?.label}`"
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
                        :aria-label="`Show ${field.label} column`"
                        :disabled="
                            order.length === 1 && order.includes(field.id)
                        "
                        @change="toggle(field.id)"
                    /><span
                        class="material-symbols-outlined"
                        aria-hidden="true"
                        >{{ symbol(field) }}</span
                    ><span>{{ field.label }}</span
                    ><small>{{ field.type }}</small></label
                >
            </section>
            <p v-if="!filteredFields.length">No fields match your search.</p>
        </div>
        <footer>
            <span role="status">{{ saveStatus }}</span
            ><button class="crm-button" @click="fieldsDialog?.close()">
                Done
            </button>
        </footer>
    </dialog>
    <dialog
        ref="filterDialog"
        aria-label="Advanced filters"
        class="md-table-dialog md-filter-dialog"
    >
        <header>
            <div>
                <h2>Advanced filters</h2>
                <p>Filter the entire result set by any available field.</p>
            </div>
            <button
                class="md-icon-button"
                aria-label="Close filters"
                @click="filterDialog?.close()"
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >close</span
                >
            </button>
        </header>
        <div class="md-dialog-body">
            <label class="md-match-choice"
                >Match<select
                    v-model="state.match"
                    aria-label="Filter match mode"
                >
                    <option value="all">All conditions (AND)</option>
                    <option value="any">Any condition (OR)</option>
                </select></label
            >
            <div
                v-for="(f, index) in state.filters"
                :key="index"
                class="md-filter-row"
            >
                <select
                    v-model="f.id"
                    :aria-label="`Filter ${index + 1} field`"
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
                            {{ c.label }}
                        </option>
                    </optgroup></select
                ><select
                    v-model="f.op"
                    :aria-label="`Filter ${index + 1} operator`"
                    @change="f.value = f.op === 'in' ? [] : ''"
                >
                    <option
                        v-for="op in catalog.get(f.id)?.operators"
                        :key="op"
                        :value="op"
                    >
                        {{ opNames[op] }}
                    </option></select
                ><template v-if="!['empty', 'not_empty'].includes(f.op)"
                    ><select
                        v-if="
                            catalog.get(f.id)?.options.length ||
                            catalog.get(f.id)?.type === 'boolean'
                        "
                        v-model="f.value"
                        :multiple="f.op === 'in'"
                        :aria-label="`Filter ${index + 1} value`"
                    >
                        <template v-if="catalog.get(f.id)?.type === 'boolean'"
                            ><option value="true">Yes</option>
                            <option value="false">No</option></template
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
                        :aria-label="`Filter ${index + 1} value`"
                        placeholder="Value" /><input
                        v-if="f.op === 'between'"
                        v-model="f.value2"
                        :type="
                            ['date', 'datetime'].includes(
                                catalog.get(f.id)?.type ?? '',
                            )
                                ? 'date'
                                : 'number'
                        "
                        :aria-label="`Filter ${index + 1} range end`"
                        placeholder="To" /></template
                ><button
                    class="md-icon-button"
                    :aria-label="`Remove condition ${index + 1}`"
                    @click="state.filters.splice(index, 1)"
                >
                    <span class="material-symbols-outlined" aria-hidden="true"
                        >close</span
                    >
                </button>
            </div>
            <p v-if="!state.filters.length" class="crm-muted">
                No conditions yet. All accessible records are included.
            </p>
            <button
                class="md-text-button"
                :disabled="state.filters.length >= 20"
                @click="addFilter"
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >add</span
                >Add condition
            </button>
        </div>
        <footer>
            <button
                class="md-text-button"
                @click="
                    state.filters = [];
                    apply();
                    filterDialog?.close();
                "
            >
                Clear filters</button
            ><button
                class="crm-button"
                @click="
                    apply();
                    filterDialog?.close();
                "
            >
                Apply filters
            </button>
        </footer>
    </dialog>
    <dialog
        ref="sortDialog"
        aria-label="Sort records"
        class="md-table-dialog md-filter-dialog"
    >
        <header>
            <div>
                <h2>Sort records</h2>
                <p>Sort by up to five fields. Top rules take priority.</p>
            </div>
            <button
                class="md-icon-button"
                aria-label="Close sorting"
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
                ><select v-model="s.id" :aria-label="`Sort ${index + 1} field`">
                    <option
                        v-for="c in payload.columns"
                        :key="c.id"
                        :value="c.id"
                    >
                        {{ c.label }}
                    </option></select
                ><select
                    v-model="s.direction"
                    :aria-label="`Sort ${index + 1} direction`"
                >
                    <option value="asc">
                        {{ labelDirection(catalog.get(s.id), "asc") }}
                    </option>
                    <option value="desc">
                        {{ labelDirection(catalog.get(s.id), "desc") }}
                    </option></select
                ><button
                    class="md-icon-button"
                    :aria-label="`Remove sort ${index + 1}`"
                    @click="state.sorts.splice(index, 1)"
                >
                    <span class="material-symbols-outlined" aria-hidden="true"
                        >close</span
                    >
                </button>
            </div>
            <button
                class="md-text-button"
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
                >Add sort
            </button>
        </div>
        <footer>
            <span class="crm-muted">Empty values always appear last.</span
            ><button
                class="crm-button"
                @click="
                    apply();
                    sortDialog?.close();
                "
            >
                Apply sorting
            </button>
        </footer>
    </dialog>
    <dialog
        ref="viewDialog"
        aria-label="Save view"
        class="md-table-dialog md-small-dialog"
    >
        <header>
            <h2>Save this view</h2>
            <button
                class="md-icon-button"
                aria-label="Close save view"
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
                    Save these columns, search, sorting and filters for your
                    account.
                </p>
                <label
                    >View name<input
                        v-model="viewName"
                        required
                        maxlength="60"
                        placeholder="e.g. High-priority prospects"
                /></label>
            </div>
            <footer>
                <button class="crm-button" :disabled="views.length >= 20">
                    Save view
                </button>
            </footer>
        </form>
    </dialog>
    <dialog
        ref="columnDialog"
        aria-label="Column options"
        class="md-table-dialog md-small-dialog"
    >
        <header>
            <h2>{{ catalog.get(activeColumn)?.label }}</h2>
            <button
                class="md-icon-button"
                aria-label="Close column options"
                @click="columnDialog?.close()"
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >close</span
                >
            </button>
        </header>
        <div class="md-dialog-body md-column-actions">
            <button
                @click="
                    sorted(activeColumn, false, 'asc');
                    columnDialog?.close();
                "
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >arrow_upward</span
                >{{ labelDirection(catalog.get(activeColumn), "asc") }}</button
            ><button
                @click="
                    sorted(activeColumn, false, 'desc');
                    columnDialog?.close();
                "
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >arrow_downward</span
                >{{ labelDirection(catalog.get(activeColumn), "desc") }}</button
            ><button @click="pin(activeColumn)">
                <span class="material-symbols-outlined" aria-hidden="true"
                    >keep</span
                >{{
                    pinned.includes(activeColumn)
                        ? "Unpin column"
                        : "Pin to left"
                }}</button
            ><button @click="move(activeColumn, -1)">
                <span class="material-symbols-outlined" aria-hidden="true"
                    >arrow_back</span
                >Move left</button
            ><button @click="move(activeColumn, 1)">
                <span class="material-symbols-outlined" aria-hidden="true"
                    >arrow_forward</span
                >Move right</button
            ><button
                :disabled="order.length <= 1"
                @click="
                    toggle(activeColumn);
                    columnDialog?.close();
                "
            >
                <span class="material-symbols-outlined" aria-hidden="true"
                    >visibility_off</span
                >Hide column</button
            ><label
                >Column width<input
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
