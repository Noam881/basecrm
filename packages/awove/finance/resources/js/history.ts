export type HistoryRecord = {
    id: number;
    label: string | null;
    kind: string;
    placement: string;
    owner: { id: number; name: string } | null;
    identities: {
        type: string;
        id: number;
        name: string;
        profile_url: string;
    }[];
    created_at: string;
};
export type HistoryFile = {
    id: string;
    entry_id: number;
    name: string;
    mime_type: string;
    size_bytes: number;
    imported_at: string;
    download_url: string;
    source: {
        kind: "file-column" | "item-assets-all" | "update" | "reply";
        item_id: string;
        asset_id: string;
        update_id: string | null;
        reply_id: string | null;
        author: { name: string | null };
        created_at: string | null;
        created_precision: "date" | "timestamp" | null;
        updated_at: string | null;
        updated_precision: "date" | "timestamp" | null;
    };
};
export type HistoryEntry = {
    id: number;
    entry_kind: "facts" | "update" | "reply";
    source_revision: number;
    label: string | null;
    facts: {
        key: string;
        label: string;
        value_kind: string;
        json_type: string | null;
        availability: "present" | "missing" | "unavailable";
        unit: string | null;
        currency: string | null;
        display: string | null;
    }[];
    text: string;
    author: { source_id: string | null; name: string | null };
    source_created_at: string | null;
    source_updated_at: string | null;
    parent_entry_id: number | null;
    ingested_by: { id: number; name: string } | null;
    ingested_at: string;
    source_media_present: boolean;
    has_more_text: boolean;
    full_text_url: string | null;
};
export const historyKinds: Record<string, string> = {
    collection: "Collection reference",
    income: "Income reference",
    bonus: "Bonus reference",
    "monthly-hours": "Monthly hours reference",
    "ancillary-quote": "Ancillary quote reference",
    reference: "Other financial reference",
};
export const historyPlacements: Record<string, string> = {
    linked: "Linked identities",
    internal: "Internal office record",
    unresolved: "Association unresolved",
};

export async function readHistory<T>(
    url: string,
    signal: AbortSignal,
): Promise<T> {
    const response = await fetch(url, {
        headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
        },
        credentials: "same-origin",
        cache: "no-store",
        signal,
    });
    if (!response.ok) throw new Error("History unavailable");
    return response.json() as Promise<T>;
}
