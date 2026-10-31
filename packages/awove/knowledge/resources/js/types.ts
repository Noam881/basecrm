export type Section = "library" | "renewals" | "systems";
export interface Person {
    id: number;
    name: string;
}
export interface KnowledgeRecord {
    id: number;
    version: number;
    owner_id: number | null;
    owner: Person | null;
    archived_at: string | null;
    kind?: string;
    title?: string;
    category?: string | null;
    relevant_role?: string | null;
    purpose?: string | null;
    use_classification?: string | null;
    revision?: string | null;
    revised_on?: string | null;
    updated_on?: string | null;
    status?: string;
    name?: string;
    provider?: string;
    expires_on?: string;
    remind_on?: string | null;
    notes?: string | null;
    login_url?: string | null;
    login_href?: string | null;
    password_manager_reference?: string | null;
}
export interface Options {
    sections: Section[];
    owners: Person[];
    currentOwnerId: number;
    filterOwners: Person[];
    kinds: Record<string, string>;
    statuses: Record<string, string>;
    renewalStatuses: Record<string, string>;
    today: string;
    canCreate: boolean;
    canAssign: boolean;
    canArchive: boolean;
    canExport: boolean;
    canViewDocuments: boolean;
    canManageDocuments: boolean;
    canViewSystemReferences: boolean;
    canEditSystemReferences: boolean;
}
export interface InstructionOption {
    key: string;
    type: "work" | "routine";
    id: number;
    version: number;
    label: string;
}
export const sectionLabels: Record<Section, string> = {
    library: "Library",
    renewals: "Renewals",
    systems: "System references",
};
