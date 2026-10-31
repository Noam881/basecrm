import type { HistoryEntry } from "../../../../../crm-core/resources/js/components/HistoryList.vue";
export type Person = { id: number; name: string };
export interface Options {
    sections: { label: string; href: string }[];
    statuses: Record<string, string>;
    workStatuses: Record<string, string>;
    currentOwnerId: number;
    owners: Person[];
    workOwners: Person[];
    today: string;
    currency: string;
    currencyError: string | null;
    canCreate: boolean;
    canAssign: boolean;
    canArchive: boolean;
    canExport: boolean;
    canViewBudget: boolean;
    canManageBudget: boolean;
    canViewDocuments: boolean;
    canManageDocuments: boolean;
}
export interface Project {
    id: number;
    name: string;
    objective: string;
    owner_id: number | null;
    owner: Person | null;
    starts_on: string;
    ends_on: string;
    status: string;
    version: number;
    archived_at: string | null;
    planned_budget: string | null;
    currency: string | null;
    budget_available: boolean;
    editable: boolean;
    active: boolean;
}
export interface WorkRecord {
    id: number;
    kind: string;
    title: string;
    description: string | null;
    owner_id: number | null;
    owner: Person | null;
    priority: string;
    status: string;
    due_on: string;
    completed_on: string | null;
    version: number;
    archived_at: string | null;
    editable: boolean;
    project: { id: number; name: string; version: number };
    record_url: string;
}
export interface Meeting {
    id: number;
    held_on: string;
    summary: string;
    decisions: string | null;
    version: number;
    follow_up: WorkRecord | null;
    follow_up_restricted: boolean;
}
export interface Resource {
    id: number;
    type: string;
    label: string;
    href: string;
}
export interface Payment {
    id: number;
    direction: string;
    label: string;
    amount: string;
    currency: string;
    paid_on: string;
    href: string;
}
export interface SourceOptions<T> {
    available: boolean;
    items: T[];
}
export interface Linked<T> {
    restricted?: boolean;
    id?: number;
    version?: number;
    target?: T;
}
export interface Budget {
    id: number;
    amount: string;
    currency: string;
    actor: Person | null;
    created_at: string;
}
export interface Document {
    id: number;
    name: string;
    version: number;
    archived_at: string | null;
    href: string;
}
export interface Actuals {
    planned_budget: string | null;
    currency: string;
    from: string | null;
    to: string | null;
    incoming: { available: boolean; total: string | null };
    outgoing: { available: boolean; total: string | null };
}
export type History = HistoryEntry;
