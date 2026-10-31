import { t as tr } from "@/lib/i18n";
import type { EmployeeSummary } from "../../../crm-core/resources/js/components/EmployeeReferenceSelect.vue";
import type { HistoryEntry } from "../../../crm-core/resources/js/components/HistoryList.vue";
import { queryString } from "../../../crm-core/resources/js/components/table/queryString";
export type Section = "register" | "assignments" | "maintenance";
export interface Person {
    id: number;
    name: string;
}
export interface Identity extends Person {
    type: "contact" | "company";
    active?: boolean;
}
export interface Options {
    sections: Section[];
    moduleLabel: string;
    owners: Person[];
    maintenanceOwners: Person[];
    filterOwners: Person[];
    currentOwnerId: number;
    employees: EmployeeSummary[];
    customers: Identity[];
    suppliers: Identity[];
    statuses: Record<string, string>;
    maintenanceStatuses: Record<string, string>;
    today: string;
    canClassify: boolean;
    canAssignClassification: boolean;
    classificationOwners: Person[];
    contacts: { id: number; name: string; company_id: number | null }[];
    companies: Person[];
    canCreate: boolean;
    canAssign: boolean;
    canArchive: boolean;
    canExport: boolean;
    canViewDocuments: boolean;
    canManageDocuments: boolean;
    canViewCosts: boolean;
    canManageCosts: boolean;
    canManageEmployees: boolean;
    currency: string;
    currencyError?: string | null;
}
export interface AssetRecord {
    id: number;
    version: number;
    code: string;
    name: string;
    type: string;
    location: string;
    owner_id: number | null;
    owner: Person | null;
    notes: string | null;
    purchased_on: string | null;
    serial_number: string | null;
    status: string;
    archived_at: string | null;
}
export interface Assignment {
    id: number;
    version: number;
    asset_id: number;
    target_type: "employee" | "customer" | "location";
    employee_reference_id: number | null;
    employee: EmployeeSummary | null;
    employee_restricted: boolean;
    customer_relationship_id: number | null;
    customer: Identity | null;
    customer_restricted: boolean;
    location: string | null;
    assigned_on: string;
    expected_return_on: string | null;
    returned_on: string | null;
    return_condition: string | null;
    notes: string | null;
    actor: Person | null;
}
export interface Document {
    id: number;
    name: string;
    version: number;
    href: string;
    archived_at: string | null;
}
export interface MaintenanceRecord {
    id: number;
    version: number;
    asset_id: number;
    action: string;
    description: string | null;
    owner_id: number | null;
    owner: Person | null;
    supplier_relationship_id: number | null;
    supplier: Identity | null;
    supplier_restricted: boolean;
    due_on: string;
    status: string;
    completed_on: string | null;
    next_due_on: string | null;
    archived_at: string | null;
    asset: {
        id: number;
        code: string;
        name: string;
        version: number;
        status: string;
    };
    cost?: string | null;
    currency?: string | null;
}
export interface Completion {
    id: number;
    completed_on: string;
    next_due_on: string | null;
    actor: Person | null;
    created_at: string;
    cost?: string | null;
    currency?: string | null;
}
export interface CostSnapshot {
    id: number;
    amount: string;
    currency: string;
    actor: Person | null;
    created_at: string;
}
export interface Metric {
    id: string;
    label: string;
    value: number | string;
    href: string;
}
export type History = HistoryEntry;
export function exportUrl(
    section: Section,
    filters: Record<string, unknown>,
    state: object,
): string {
    return `/assets/export?${queryString({ ...filters, ...state, section })}`;
}
export function assignmentTarget(row: Assignment): string {
    if (row.target_type === "employee")
        return (
            (row.employee
                ? row.employee.name +
                  (row.employee.active ? "" : tr(" (inactive)"))
                : undefined) ??
            (row.employee_restricted
                ? tr("Employee reference restricted")
                : tr("Employee reference unavailable"))
        );
    if (row.target_type === "customer")
        return (
            row.customer?.name ??
            (row.customer_restricted
                ? tr("Customer reference restricted")
                : tr("Customer reference unavailable"))
        );
    return row.location ?? tr("Location unavailable");
}
export const historyLabels: Record<string, string> = {
    code: "Asset code",
    name: "Asset name",
    type: "Asset type",
    location: "Location",
    owner_id: "Responsible staff member",
    notes: "Notes",
    purchased_on: "Purchase date",
    serial_number: "Serial number",
    status: "Status",
    assigned_on: "Assignment date",
    expected_return_on: "Expected return date",
    returned_on: "Actual return date",
    return_condition: "Condition on return",
    employee_reference_id: "Employee",
    customer_relationship_id: "Customer",
    supplier_relationship_id: "Supplier",
    due_on: "Due date",
    completed_on: "Completion date",
    next_due_on: "Manual next due date",
    description: "Description",
    action: "Maintenance action",
    cost: "Recorded cost",
};
