export type Value = string | number | boolean | null;
export interface TableColumn {
    id: string;
    label: string;
    type: string;
    group: string;
    options: { value: string; label: string }[];
    width: number;
    operators: string[];
}
export interface Filter {
    id: string;
    op: string;
    value?: string | string[];
    value2?: string;
}
export interface Sort {
    id: string;
    direction: "asc" | "desc";
}
export interface QueryState {
    q: string;
    sorts: Sort[];
    filters: Filter[];
    match: "all" | "any";
    per_page: number;
}
export interface SavedView {
    id: string;
    name: string;
    columns: string[];
    state: QueryState;
}
export interface Preferences {
    columns: string[];
    widths?: Record<string, number>;
    pinned?: string[];
    density?: "comfortable" | "compact";
    wrap?: boolean;
    views?: SavedView[];
}
export interface TableRow {
    id: number;
    title?: string;
    name?: string;
    lead_id?: number;
    status_id?: string;
    version?: number;
    editable?: boolean;
    cells: Record<string, Value>;
}
export interface TablePayload {
    id: string;
    columns: TableColumn[];
    defaultColumns: string[];
    state: QueryState;
    preferences: Preferences;
    page: {
        data: TableRow[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
        total: number;
        from: number | null;
        to: number | null;
    };
}
