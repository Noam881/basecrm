export interface Identity {
    id: number;
    name: string;
}
export interface ServiceRequest {
    id: number;
    subject: string;
    description: string;
    owner_id: number | null;
    owner: Identity | null;
    contact_id: number | null;
    company_id: number | null;
    customer: (Identity & { type: string }) | null;
    status: string;
    priority: string;
    received_on: string;
    channel: string;
    next_action: string | null;
    due_on: string | null;
    resolution: string | null;
    resolved_on: string | null;
    document_url: string | null;
    document_link: string | null;
    version: number;
    archived_at: string | null;
    editable: boolean;
    overdue: boolean;
}
export interface Feedback {
    id: number;
    owner_id: number | null;
    owner: Identity | null;
    contact_id: number | null;
    company_id: number | null;
    request_id: number | null;
    customer: (Identity & { type: string }) | null;
    received_on: string;
    rating: number;
    comments: string;
    action_required: boolean;
    follow_up_on: string | null;
    followed_up_on: string | null;
    version: number;
    editable: boolean;
    archived_at: string | null;
}
export interface Options {
    statuses: Record<string, string>;
    channels: Record<string, string>;
    contacts: Identity[];
    companies: Identity[];
    owners: Identity[];
    relatedRequests: ServiceRequest[];
    canCreate: boolean;
    canAssign: boolean;
    canExport: boolean;
    canViewAll: boolean;
    today: string;
}
export interface Page<T> {
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}
