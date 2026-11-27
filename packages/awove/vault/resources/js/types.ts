import type { TablePayload } from "../../../crm-core/resources/js/components/table/types";
export type CustomerType = "contacts" | "companies";
export type FieldType =
    | "text"
    | "multiline"
    | "password"
    | "secret_multiline"
    | "url"
    | "email"
    | "date";
export interface Customer {
    type: CustomerType;
    id: number;
    name: string;
}
export interface Field {
    id: string;
    label: string;
    type: FieldType;
    value: string | null;
    order: number;
    hidden: boolean;
}
export interface Template {
    id: string;
    label: string;
    fields: Omit<Field, "value" | "order">[];
}
export interface Actions {
    create?: boolean;
    read?: boolean;
    update?: boolean;
    delete?: boolean;
    change_level?: boolean;
    transfer?: boolean;
    files_upload?: boolean;
    files_download?: boolean;
    files_delete?: boolean;
}
export interface Item {
    id: string;
    title: string;
    type: string;
    level: 1 | 2;
    version: number;
    customer_type: CustomerType;
    customer_id: number;
    customer_name: string;
    actions: Actions;
    updated_at?: string;
}
export interface FileEntry {
    id: string;
    name: string;
    size_bytes: number;
    source?: {
        original_name: string | null;
        author: { name: string | null };
        created_at: string | null;
    } | null;
}
export interface Content {
    item: Item;
    payload: { fields: Field[]; notes?: string | null };
    files: FileEntry[];
    source_export?: boolean;
}
export interface Options {
    customers: Customer[];
    types: Template[];
    actions: Actions;
}
export interface InitialData extends Options {
    table: TablePayload;
    monday_import?: boolean;
}
export interface AuthStatus {
    auth_mode?: "password_email_totp" | "email_riddle";
    enrolled: boolean;
    approved: boolean;
    unlocked: boolean;
    issued_at: string | null;
    expires_at: string | null;
    server_now: string;
    step: "password" | "email" | "totp" | "email_start" | "riddle";
    challenge_id: string | null;
    dev_question_mode: boolean;
    question: string | null;
    can_admin: boolean;
}
export interface Draft {
    customer_type: CustomerType;
    customer_id: number | null;
    title: string;
    type: string;
    level: 1 | 2 | null;
    payload: { fields: Field[]; notes: string };
}
export const fieldTypes: FieldType[] = [
    "text",
    "multiline",
    "password",
    "secret_multiline",
    "url",
    "email",
    "date",
];
export const fieldLabels: Record<FieldType, string> = {
    text: "Short text",
    multiline: "Multiline text",
    password: "Password or secret",
    secret_multiline: "Multiline secret",
    url: "URL",
    email: "Email address",
    date: "Date",
};
export function csrf(): string {
    return decodeURIComponent(
        document.cookie
            .split("; ")
            .find((c) => c.startsWith("XSRF-TOKEN="))
            ?.split("=")[1] ?? "",
    );
}
export function queryString(data: Record<string, unknown>): string {
    const result = new URLSearchParams();
    const add = (key: string, value: unknown) => {
        if (value === undefined || value === null) return;
        if (Array.isArray(value))
            value.forEach((part, i) => add(`${key}[${i}]`, part));
        else if (typeof value === "object")
            Object.entries(value).forEach(([name, part]) =>
                add(`${key}[${name}]`, part),
            );
        else result.append(key, String(value));
    };
    Object.entries(data).forEach(([key, value]) => add(key, value));
    return result.toString();
}
