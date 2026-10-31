export interface Person {
    id: number;
    name: string;
}
export interface Relationship extends Person {
    role: string;
    type: string;
    active: boolean;
}
export interface Constituent {
    id: number;
    version: number;
    relationship_id: number;
    relationship: Relationship;
    owner_id: number | null;
    owner: Person | null;
    next_action: string | null;
    next_action_on: string | null;
    notes: string | null;
    archived_at: string | null;
}
export interface Pledge {
    id: number;
    version: number;
    constituent_id: number;
    reference: string | null;
    amount: string;
    currency: string;
    expected_on: string;
    restrictions: string | null;
    archived_at: string | null;
    constituent: Constituent;
}
export interface Receipt {
    id?: number;
    version?: number;
    finance_record_id: number;
    finance_version: number;
    payment_id: number;
    label: string;
    paid_on: string;
    amount: string;
    currency: string;
    href: string;
}
export interface Actuals {
    available: boolean;
    message: string | null;
    amount?: string | null;
    currency?: string;
    receipts?: Receipt[];
}
export interface Options {
    sections: { label: string; href: string }[];
    owners: Person[];
    currentOwnerId: number;
    relationships: Relationship[];
    roles: string[];
    today: string;
    currency: string;
    currencyError: string | null;
    canCreate: boolean;
    canAssign: boolean;
    canArchive: boolean;
    canExport: boolean;
    canViewPledges: boolean;
    canManagePledges: boolean;
    canViewReceipts: boolean;
    canManageReceipts: boolean;
    canClassify: boolean;
    contacts: { id: number; name: string; company_id: number | null }[];
    companies: Person[];
    classificationOwners: Person[];
    canAssignClassification: boolean;
}
export const fields: Record<string, string> = {
    relationship_id: "Shared classification",
    owner_id: "Responsible person",
    next_action: "Next action",
    next_action_on: "Next action date",
    notes: "Notes",
    amount: "Pledged amount",
    currency: "Retained currency",
    expected_on: "Expected date",
    reference: "Reference",
    restrictions: "Donation restrictions",
    receipts: "Finance receipt link",
    archived_at: "Archived",
};
export const events: Record<string, string> = {
    "constituent.created": "Fundraising context created",
    "constituent.updated": "Fundraising context updated",
    "constituent.archived": "Fundraising context archived",
    "pledge.created": "Pledge created",
    "pledge.updated": "Pledge updated",
    "pledge.archived": "Pledge archived",
    "receipt.linked": "Existing Finance receipt linked",
    "receipt.unlinked": "Finance receipt link archived",
};
