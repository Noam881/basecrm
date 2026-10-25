export interface Person {
    id: number;
    name: string;
}
export interface Employee extends Person {
    active: boolean;
}
export type Section = "employees" | "vacancies" | "candidates" | "cases";
export interface Options {
    sections: Section[];
    owners: Person[];
    filterOwners: Person[];
    employees: Employee[];
    retainedEmployees: Employee[];
    vacancies: { id: number; role_name: string }[];
    stages: Record<string, string>;
    employmentStatuses: Record<string, string>;
    vacancyStatuses: Record<string, string>;
    checklistStatuses: Record<string, string>;
    categories: Record<string, string>;
    today: string;
    canCreate: boolean;
    canAssign: boolean;
    canArchive: boolean;
    canExport: boolean;
    canViewDocuments: boolean;
    canManageDocuments: boolean;
    canViewFinalPay: boolean;
    canEditFinalPay: boolean;
    canManageEmployees: boolean;
}
export interface HrRecord {
    id: number;
    version: number;
    owner_id: number;
    owner: Person | null;
    archived_at: string | null;
    employee_reference_id?: number | null;
    employee?: Employee | null;
    employee_restricted?: boolean;
    manager_employee_reference_id?: number | null;
    manager?: Employee | null;
    manager_restricted?: boolean;
    vacancy_id?: number | null;
    vacancy?: { id: number; role_name: string } | null;
    vacancy_restricted?: boolean;
    name?: string;
    role_name?: string;
    email?: string | null;
    phone?: string | null;
    role_description?: string | null;
    responsibilities?: string;
    start_on?: string;
    employment_status?: string;
    description?: string;
    opened_on?: string;
    status?: string;
    stage?: string;
    notes?: string | null;
    next_action_on?: string | null;
    kind?: string;
    due_on?: string;
    final_work_on?: string | null;
    final_pay_info?: string | null;
    completed_on?: string | null;
}
export interface History {
    id: number;
    event: string;
    changed_fields: string[];
    actor: Person | null;
    created_at: string;
}
export interface ChecklistItem {
    id: number;
    version: number;
    title: string;
    description: string | null;
    owner_id: number | null;
    priority: string;
    status: string;
    due_on: string;
    completed_on: string | null;
    archived_at: string | null;
    category: string;
    required: boolean;
    href?: string;
    editable?: boolean;
}
export const sectionLabels: Record<Section, string> = {
    employees: "Employees",
    vacancies: "Vacancies",
    candidates: "Candidates",
    cases: "Onboarding and offboarding",
};
