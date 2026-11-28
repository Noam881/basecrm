import { localeTag } from "@/lib/i18n";
import {
    formatCalendarDate,
    formatTimestamp,
    formatLeadCreated,
} from "./dates";

export interface Person {
    id: number;
    name: string;
    email?: string | null;
    phone?: string | null;
    registration_number?: string | null;
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
export interface IdentityDocument {
    id: number;
    original_name: string;
    mime_type: string;
    size_bytes: number;
    created_at: string;
    uploaded_by: { id: number; name: string } | null;
    download_url: string;
    source?: {
        author: { name: string | null };
        source_created_at: string | null;
        source_updated_at: string | null;
        kind:
            | "file-column"
            | "item-assets-all"
            | "update"
            | "reply"
            | "generated-reference"
            | "generated-history"
            | "generated-document";
        item_id?: string;
        parent_item_id?: string | null;
        reference_revision?: number;
        update_id?: string;
        reply_id?: string | null;
        review_flags?: string[];
        source_id?: string;
        source_kind?: "workdoc" | "item-description";
        access_scope?: "profile" | "import-review";
        context: {
            kind: "update" | "reply";
            lead_id: number;
            activity_id: number;
            url: string;
        } | null;
    } | null;
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
export interface LegacyHistoryEntry {
    kind: "update" | "reply" | "source-created";
    text: string;
    author: { source_id: string | null; name: string | null };
    source_created_at: string | null;
    source_updated_at: string | null;
    parent_activity_id: number | null;
    revision: number;
    ingested_at: string;
    ingested_by: { id: number; name: string } | null;
    source_media_present: boolean;
    has_more_text: boolean;
    full_text_url: string | null;
}
export interface LegacyActivity {
    id: number;
    event: "lead.legacy_history";
    actor: null;
    created_at: string;
    legacy: LegacyHistoryEntry;
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
    entered_on: string | null;
    created_at: string;
    custom_fields: Record<string, string | number | boolean | null>;
    needs?: string | null;
    estimated_value?: string | null;
    closing_date?: string | null;
    final_value?: string | null;
    loss_reason?: string | null;
    business_sector?: string | null;
    product_sold?: string | null;
    contract_sent_on?: string | null;
    invoice_sent_on?: string | null;
    payment_received_on?: string | null;
    process_started_on?: string | null;
    contract_url?: string | null;
    privacy_policy_accepted?: boolean | null;
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
export interface LeadLifecycleFormData {
    business_sector: string;
    product_sold: string;
    contract_sent_on: string;
    invoice_sent_on: string;
    payment_received_on: string;
    process_started_on: string;
    contract_url: string;
    privacy_policy_accepted: boolean | null;
}
export const leadLifecycleFormData = (lead?: Lead): LeadLifecycleFormData => ({
    business_sector: lead?.business_sector ?? "",
    product_sold: lead?.product_sold ?? "",
    contract_sent_on: lead?.contract_sent_on?.slice(0, 10) ?? "",
    invoice_sent_on: lead?.invoice_sent_on?.slice(0, 10) ?? "",
    payment_received_on: lead?.payment_received_on?.slice(0, 10) ?? "",
    process_started_on: lead?.process_started_on?.slice(0, 10) ?? "",
    contract_url: lead?.contract_url ?? "",
    privacy_policy_accepted: lead?.privacy_policy_accepted ?? null,
});
export interface LeadSignedContract {
    id: number;
    original_name: string;
    version: number;
    created_at: string;
    created_by: { id: number; name: string } | null;
    download_url: string;
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
    formatTimestamp(value, localeTag(), zone);
export const dateOnly = (value: string) =>
    formatCalendarDate(value, localeTag());
export const leadCreated = (
    lead: { entered_on?: string | null; created_at: string },
    zone?: string,
) => formatLeadCreated(lead, localeTag(), zone);
