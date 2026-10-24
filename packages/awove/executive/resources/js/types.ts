export interface Person {
    id: number;
    name: string;
}
export interface Options {
    owners: Person[];
    filterOwners: Person[];
    goals: Person[];
    taskStatuses: Record<string, string>;
    goalStatuses: Record<string, string>;
    canCreate: boolean;
    canAssign: boolean;
    canArchive: boolean;
    canExport: boolean;
    today: string;
}
export interface Goal {
    id: number;
    name: string;
    owner_id: number;
    target_on: string;
    success_criterion: string;
    unit: string;
    target_value: string;
    current_value: string;
    status: string;
    version: number;
    archived_at: string | null;
}
export interface Task {
    id: number;
    title: string;
    description: string | null;
    owner_id: number | null;
    priority: string;
    due_on: string;
    status: string;
    completed_on: string | null;
    version: number;
    archived_at: string | null;
    goal_id: number | null;
    goal: Person | null;
    goal_restricted: boolean;
}
export interface Routine {
    id: number;
    name: string;
    owner_id: number | null;
    frequency: string;
    next_due_on: string | null;
    checklist: string[];
    version: number;
    archived_at: string | null;
}
export interface History {
    id: number;
    event: string;
    changed_fields: string[];
    actor: Person | null;
    created_at: string;
}
export interface Completion {
    id: number;
    occurrence_due_on: string | null;
    completed_on: string;
    actor: Person | null;
    created_at: string;
    checklist_snapshot: { label: string; completed: boolean }[];
}
