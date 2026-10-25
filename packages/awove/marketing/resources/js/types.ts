import type { HistoryEntry } from "../../../crm-core/resources/js/components/HistoryList.vue";
export interface Options {
    kind: "campaigns" | "content" | "reports";
    owners: { id: number; name: string }[];
    statuses: Record<string, string>;
    canCreate: boolean;
    canAssign: boolean;
    canExport: boolean;
    canArchive: boolean;
    currency: string;
    currencyConsistent: boolean;
    canCampaigns: boolean;
    canContent: boolean;
    channels: string[];
    campaignOptions: { id: number; name: string }[];
}
export interface MarketingRecord {
    id: number;
    version: number;
    owner_id: number | null;
    name?: string;
    objective?: string;
    title?: string;
    channel: string;
    format?: string;
    starts_on?: string;
    ends_on?: string;
    planned_budget?: string;
    currency?: string;
    materials_url?: string | null;
    landing_url?: string | null;
    status: string;
    planned_on?: string;
    draft?: string | null;
    asset_url?: string | null;
    publication_url?: string | null;
    published_on?: string | null;
    archived_at: string | null;
}
export interface Report {
    id: number;
    version: number;
    campaign_id: number;
    starts_on: string;
    ends_on: string;
    actual_spend: string;
    currency: string;
    reported_leads: number;
    notes: string | null;
    cost_per_lead: string | null;
    history: HistoryEntry[];
}
export interface Totals {
    actual_spend: string;
    reported_leads: string;
    cost_per_lead: string | null;
}
