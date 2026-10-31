import type { EmployeeSummary } from "../../../crm-core/resources/js/components/EmployeeReferenceSelect.vue";
import type { HistoryEntry } from "../../../crm-core/resources/js/components/HistoryList.vue";
export interface PayrollInput {
    key: string;
    label: string;
    type: "decimal" | "money" | "text" | "date";
    unit: string;
    scale: number;
    required: boolean;
}
export interface PayrollRecord {
    id: number;
    employee_reference_id: number | null;
    employee: EmployeeSummary | null;
    owner_id: number | null;
    period_start_on: string;
    period_end_on: string;
    status: string;
    currency: string;
    input_schema: PayrollInput[];
    inputs: Record<string, string | null>;
    notes: string | null;
    sent_on: string | null;
    archived_at: string | null;
    version: number;
}
export interface PayrollOptions {
    sections: { label: string; href: string }[];
    owners: { id: number; name: string }[];
    employees: EmployeeSummary[];
    statuses: Record<string, string>;
    canCreate: boolean;
    canAssign: boolean;
    canExport: boolean;
    canArchive: boolean;
    canManageEmployees: boolean;
    inputSchema: PayrollInput[];
    currency: string;
    currencyError: string | null;
    today: string;
}
export interface PayrollHistory extends HistoryEntry {
    details: {
        changed_fields?: string[];
        previous_status?: string;
        previous_sent_on?: string | null;
    };
}
