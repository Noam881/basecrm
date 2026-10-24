import { queryString } from "../../../crm-core/resources/js/components/table/queryString";
export interface Person {
    id: number;
    name: string;
}
export interface Classification {
    id: number;
    version: number;
    name: string;
    email: string | null;
    phone: string | null;
    type: "contact" | "company";
    profile_url: string;
    role: string;
    contact_id: number | null;
    company_id: number | null;
    responsible_id: number | null;
    responsible: Person | null;
    main_contact_id: number | null;
    main_contact: Person | null;
    has_hidden_main_contact: boolean;
    active: boolean;
    notes: string | null;
    archived_at: string | null;
}
export interface Options {
    owners: Person[];
    customers: Classification[];
    suppliers: Classification[];
    statuses: Record<string, string>;
    orderStatuses: Record<string, string>;
    canCreate: boolean;
    canAssign: boolean;
    canArchive: boolean;
    canExport: boolean;
    canViewAll: boolean;
    canClassify: boolean;
    canViewDirectory: boolean;
    currency: string;
    today: string;
}
export interface Work {
    id: number;
    version: number;
    title: string;
    description: string | null;
    owner_id: number | null;
    owner: Person | null;
    priority: string;
    status: string;
    due_on: string;
    completed_on: string | null;
    customer_relationship_id: number | null;
    customer: Classification | null;
    notes: string | null;
    document_url: string | null;
    document_link: string | null;
    editable: boolean;
    archived_at: string | null;
}
export interface Order {
    id: number;
    version: number;
    supplier_relationship_id: number | null;
    supplier: Classification | null;
    owner_id: number | null;
    owner: Person | null;
    reference: string;
    description: string;
    quantity: string | null;
    agreed_total: string;
    currency: string;
    ordered_on: string;
    expected_on: string | null;
    received_on: string | null;
    document_url: string | null;
    document_link: string | null;
    status: string;
    outstanding_items: string | null;
    received_quantity: string | null;
    outstanding_quantity: string | null;
    editable: boolean;
    archived_at: string | null;
    overdue: boolean;
}
export interface Routine {
    id: number;
    version: number;
    name: string;
    owner_id: number | null;
    owner: Person | null;
    frequency: string;
    next_due_on: string | null;
    checklist: string[];
    editable: boolean;
    archived_at: string | null;
}
export interface History {
    id: number;
    event: string;
    changed_fields?: string[];
    details?: Record<string, unknown> | null;
    actor: Person | null;
    created_at: string;
}
export interface Completion {
    id: number;
    occurrence_due_on: string;
    completed_on: string;
    checklist_snapshot: { label: string; completed: boolean }[];
    actor: Person | null;
}
export interface Receipt {
    id: number;
    description: string;
    quantity: string | null;
    complete: boolean;
    received_on: string;
    actor: Person | null;
}
export function exportUrl(
    endpoint: string,
    filters: Record<string, unknown>,
    state: unknown,
) {
    return (
        endpoint +
        "/export?" +
        queryString({
            ...filters,
            ...(state as Record<string, unknown>),
        })
    );
}

export function historicalOwner(
    owners: Person[],
    owner: Person | null | undefined,
): Person[] {
    return owner && !owners.some((person) => person.id === owner.id)
        ? [...owners, owner]
        : owners;
}
export function historicalClassification(
    options: Classification[],
    record: Classification | null | undefined,
): Classification[] {
    return record && !options.some((option) => option.id === record.id)
        ? [...options, record]
        : options;
}
