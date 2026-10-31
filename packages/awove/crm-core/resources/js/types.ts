import { localeTag } from "@/lib/i18n";

export interface Person {
    id: number;
    name: string;
    email?: string | null;
    phone?: string | null;
    company_id?: number | null;
    company?: Person | null;
    secondary_phone?: string | null;
    secondary_phone_label?: string | null;
    preferred_contact_method?: string | null;
    preferred_contact_times?: string[];
    preferred_contact_days?: string[];
    address?: string | null;
    city?: string | null;
    country?: string | null;
    source?: string | null;
    notes?: ContactNote[];
}
export interface ContactNote {
    id: number;
    body: string;
    author: { id: number; name: string } | null;
    created_at: string;
}
export interface ContactOption {
    value: string;
    label: string;
}
export interface ContactOptions {
    methods: ContactOption[];
    phone_labels: ContactOption[];
    times: ContactOption[];
    days: ContactOption[];
    sources: ContactOption[];
}
export interface ContactFormData {
    name: string;
    email: string;
    phone: string;
    company_id: number | null;
    secondary_phone: string;
    secondary_phone_label: string;
    preferred_contact_method: string;
    preferred_contact_times: string[];
    preferred_contact_days: string[];
    address: string;
    city: string;
    country: string;
    source: string;
    notes?: string;
}
export const contactFormData = (person?: Person): ContactFormData => ({
    name: person?.name ?? "",
    email: person?.email ?? "",
    phone: person?.phone ?? "",
    company_id: person?.company_id ?? null,
    secondary_phone: person?.secondary_phone ?? "",
    secondary_phone_label: person?.secondary_phone_label ?? "",
    preferred_contact_method: person?.preferred_contact_method ?? "",
    preferred_contact_times: [...(person?.preferred_contact_times ?? [])],
    preferred_contact_days: [...(person?.preferred_contact_days ?? [])],
    address: person?.address ?? "",
    city: person?.city ?? "",
    country: person?.country ?? "",
    source: person?.source ?? "",
});
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
    created_at: string;
    custom_fields: Record<string, string | number | boolean | null>;
    needs?: string | null;
    estimated_value?: string | null;
    closing_date?: string | null;
    final_value?: string | null;
    loss_reason?: string | null;
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
    new Intl.DateTimeFormat(localeTag(), {
        dateStyle: "medium",
        timeStyle: "short",
        timeZone: zone,
    }).format(new Date(value));
