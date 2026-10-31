import type { TablePayload } from "../../../../../crm-core/resources/js/components/table/types";
import { queryString } from "../../../../../crm-core/resources/js/components/table/queryString";
export type Kind =
    | "programs"
    | "participants"
    | "locations"
    | "groups"
    | "sessions"
    | "attendance"
    | "feedback";
export type Person = { id: number; name: string };
export type Related = Person & {
    active?: boolean;
    archived?: boolean;
    participant_code?: string | null;
};
export type ProgramRecord = {
    id: number;
    version: number;
    editable: boolean;
    archived_at: string | null;
    name?: string;
    participant_code?: string;
    email?: string | null;
    phone?: string | null;
    notes?: string | null;
    active?: boolean;
    owner_id?: number | null;
    owner?: Person | null;
    program_id?: number | null;
    program?: Related | null;
    program_restricted?: boolean;
    location_id?: number | null;
    location?: Related | null;
    location_restricted?: boolean;
    group_id?: number | null;
    group?: Related | null;
    group_restricted?: boolean;
    contact_id?: number | null;
    contact?: (Person & { email: string | null; phone: string | null }) | null;
    contact_restricted?: boolean;
    starts_on?: string | null;
    ends_on?: string | null;
    session_on?: string;
    session_id?: number;
    session?: Related;
    participant_id?: number;
    participant?: Related;
    status?: string;
    feedback_on?: string;
    comments?: string;
    next_action?: string | null;
    follow_up_owner_id?: number | null;
    follow_up_owner?: Person | null;
    due_on?: string | null;
    completed_on?: string | null;
    record_url?: string;
};
export type Options = {
    sections: { label: string; href: string }[];
    owners: Person[];
    participantOwners: Person[];
    followUpOwners: Person[];
    currentOwnerId: number;
    today: string;
    statuses: Record<string, string>;
    canViewPrograms: boolean;
    canViewParticipants: boolean;
    canAssign: boolean;
    canAssignParticipants: boolean;
    canCreate: boolean;
    canCreateParticipants: boolean;
    canManageAttendance: boolean;
    canViewDocuments: boolean;
    canManageDocuments: boolean;
    programs: Related[];
    locations: Related[];
    groups: Related[];
    participants: Related[];
    contacts: Person[];
};
export const titles: Record<Kind, string> = {
    programs: "Programs",
    participants: "Participants",
    locations: "Locations",
    groups: "Groups",
    sessions: "Sessions",
    attendance: "Attendance",
    feedback: "Feedback",
};
export const singular: Record<Kind, string> = {
    programs: "program",
    participants: "participant",
    locations: "location",
    groups: "group",
    sessions: "session",
    attendance: "attendance",
    feedback: "feedback",
};
export function exportUrl(
    section: Kind,
    filters: Record<string, string>,
    state: TablePayload["state"],
) {
    return (
        "/nonprofit/programs/export?" +
        queryString({ ...filters, section, ...state })
    );
}
