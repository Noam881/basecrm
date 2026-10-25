import type { QueryState } from "../../../crm-core/resources/js/components/table/types";
import { queryString } from "../../../crm-core/resources/js/components/table/queryString";
export type LedgerCap = "collections" | "expenses";
export interface Person {
    id: number;
    name: string;
}
export interface Relationship extends Person {
    type: string;
    phone: string | null;
    email: string | null;
    profile_url: string;
    active: boolean;
}
export interface Section {
    label: string;
    href: string;
}
export interface Options {
    cap: LedgerCap;
    sections: Section[];
    owners: Person[];
    relationships: Relationship[];
    contacts: (Person & { company_id: number | null })[];
    companies: Person[];
    classificationOwners: Person[];
    canAssignClassification: boolean;
    canClassify: boolean;
    canCreate: boolean;
    canAssign: boolean;
    canExport: boolean;
    canRecordPayment: boolean;
    canCancel: boolean;
    canViewAll: boolean;
    today: string;
    currency: string;
    statuses: Record<string, string>;
}
export interface LedgerEntry {
    id: number;
    version: number;
    kind: string;
    reference: string;
    description: string;
    category: string | null;
    relationship_id: number | null;
    relationship: Relationship | null;
    owner_id: number | null;
    owner: Person | null;
    total: string;
    currency: string;
    paid_amount: string;
    remaining_amount: string;
    status: string;
    due_on: string;
    next_action: string | null;
    next_action_on: string | null;
    document_url: string | null;
    document_link: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    editable: boolean;
}
export interface Payment {
    id: number;
    amount: string;
    paid_on: string;
    reference: string | null;
    notes: string | null;
    actor: Person | null;
}
export interface History {
    id: number;
    event: string;
    version: number;
    details: Record<string, unknown>;
    changed_fields: string[];
    actor: Person | null;
    created_at: string;
}
export const exportUrl = (
    cap: LedgerCap,
    filters: Record<string, string>,
    state: QueryState,
) => "/finance/" + cap + "/export?" + queryString({ ...filters, ...state });
