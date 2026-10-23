export interface Person {
    id: number;
    name: string;
    email?: string | null;
    phone?: string | null;
    company_id?: number | null;
}
export interface Stage {
    id: string;
    name: string;
    kind: string;
    position: number;
}
export interface Task {
    id: number;
    title: string;
    lead_id: number;
    lead?: { id: number; title: string };
    assignee?: Person;
    assignee_id: number;
    due_at: string;
    remind_at: string;
    status: string;
    priority: string;
    version: number;
}
export interface Lead {
    id: number;
    title: string;
    contact_id: number | null;
    company_id: number | null;
    owner_id: number | null;
    contact?: Person;
    company?: Person;
    owner?: Person;
    status: Stage;
    status_id: string;
    priority: string;
    source: string;
    version: number;
    archived_at: string | null;
    custom_fields: Record<string, string | number | boolean | null>;
    next_action_at?: string;
    notes?: { id: number; body: string; author: Person; created_at: string }[];
    tasks?: Task[];
    activity?: {
        id: number;
        event: string;
        details: unknown;
        actor: Person;
        created_at: string;
    }[];
}
export interface Page<T> {
    data: T[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    total: number;
}
export interface Field {
    type: "text" | "number" | "date" | "select" | "boolean";
    label: string;
    options?: string[];
}
export const date = (value: string, zone?: string) =>
    new Intl.DateTimeFormat("en", {
        dateStyle: "medium",
        timeStyle: "short",
        timeZone: zone,
    }).format(new Date(value));
