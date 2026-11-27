import { computed, onBeforeUnmount, onMounted, readonly, ref } from "vue";
import { t } from "@/lib/i18n";
import { csrf, type AuthStatus } from "./types";
const status = ref<AuthStatus | null>(null);
const remaining = ref(0);
const ready = ref(false);
const cleaners = new Set<() => void>();
const controllers = new Set<AbortController>();
let generation = 0;
// Poll ordering is independent of sensitive-content lifetime. A routine status
// refresh must never cancel an in-flight item read or clear an edited form.
let statusRevision = 0;
let deadline = 0;
let blockedExpiry: string | null = null;
let interval: ReturnType<typeof setInterval> | undefined;
let expiryTimer: ReturnType<typeof setTimeout> | undefined;
let channel: BroadcastChannel | null = null;
let mounts = 0;
let polling: ReturnType<typeof setInterval> | undefined;
export class VaultRequestError extends Error {
    constructor(
        message: string,
        public code: number,
        public errors: Record<string, string[]> = {},
    ) {
        super(message);
    }
}
function clearDom() {
    if (typeof document === "undefined") return;
    document
        .querySelectorAll<HTMLElement>("[data-vault-sensitive]")
        .forEach((node) => {
            if (
                node instanceof HTMLInputElement ||
                node instanceof HTMLTextAreaElement
            )
                node.value = "";
            else node.textContent = "";
        });
}
export function clearSensitive(lock = false) {
    generation++;
    statusRevision++;
    for (const controller of controllers) controller.abort();
    controllers.clear();
    for (const clear of cleaners) clear();
    clearDom();
    if (lock) {
        blockedExpiry = status.value?.expires_at ?? blockedExpiry;
        if (status.value) status.value = { ...status.value, unlocked: false };
        remaining.value = 0;
        deadline = 0;
        if (expiryTimer) clearTimeout(expiryTimer);
    }
}
export function securityGeneration() {
    return generation;
}
export function currentGeneration(value: number) {
    return value === generation && isUnlocked();
}
export function isUnlocked() {
    return (
        ready.value && !!status.value?.unlocked && deadline > performance.now()
    );
}
function tick() {
    remaining.value = Math.max(
        0,
        Math.ceil((deadline - performance.now()) / 1000),
    );
    if (status.value?.unlocked && deadline <= performance.now()) {
        clearSensitive(true);
        channel?.postMessage({ kind: "locked" });
    }
}
export function adoptStatus(next: AuthStatus, started = performance.now()) {
    // Explicit authentication results supersede every poll already in flight.
    statusRevision++;
    applyStatus(next, started);
    channel?.postMessage({ kind: "refresh" });
}
function applyStatus(next: AuthStatus, started: number) {
    const left = Math.max(
        0,
        Date.parse(next.expires_at ?? "") -
            Date.parse(next.server_now) -
            (performance.now() - started),
    );
    const permitted =
        next.unlocked && next.expires_at !== blockedExpiry && left > 0;
    const proposed = performance.now() + (Number.isFinite(left) ? left : 0);
    const sameWindow =
        status.value?.expires_at === next.expires_at && status.value?.unlocked;
    if (!permitted && status.value?.unlocked) clearSensitive(true);
    deadline = permitted
        ? sameWindow
            ? Math.min(deadline, proposed)
            : proposed
        : 0;
    status.value = { ...next, unlocked: permitted };
    ready.value = true;
    if (expiryTimer) clearTimeout(expiryTimer);
    if (permitted)
        expiryTimer = setTimeout(
            () => {
                clearSensitive(true);
                channel?.postMessage({ kind: "locked" });
            },
            Math.max(0, deadline - performance.now()),
        );
    tick();
}
export async function request<T>(
    url: string,
    method = "GET",
    body?: unknown,
    sensitive = false,
): Promise<T> {
    if (sensitive && !isUnlocked())
        throw new VaultRequestError(
            t("Unlock the Vault before continuing."),
            423,
        );
    const captured = generation;
    const authMutation = method !== "GET" && url.startsWith("/vault/api/auth/");
    // Enrollment also returns setup data without calling adoptStatus(). Keep
    // pre-mutation polls from restoring an earlier authentication step.
    if (authMutation) statusRevision++;
    const controller = new AbortController();
    if (sensitive) controllers.add(controller);
    try {
        const multipart = body instanceof FormData;
        const response = await fetch(url, {
            method,
            cache: "no-store",
            credentials: "same-origin",
            signal: controller.signal,
            headers: {
                Accept: "application/json",
                "X-XSRF-TOKEN": csrf(),
                ...(multipart || body === undefined
                    ? {}
                    : { "Content-Type": "application/json" }),
            },
            ...(body === undefined
                ? {}
                : { body: multipart ? body : JSON.stringify(body) }),
        });
        if ([401, 403, 404, 419, 423, 503].includes(response.status))
            clearSensitive(true);
        if (!response.ok) {
            const result = await response.json().catch(() => ({}));
            throw new VaultRequestError(
                result.message ??
                    t("The Vault request could not be completed."),
                response.status,
                result.errors ?? {},
            );
        }
        const result = await response.json();
        if (sensitive && !currentGeneration(captured))
            throw new VaultRequestError(
                t("The Vault is locked. Sensitive content was cleared."),
                423,
            );
        if (authMutation) statusRevision++;
        return result as T;
    } finally {
        controllers.delete(controller);
    }
}
export async function refreshStatus() {
    const captured = ++statusRevision;
    const started = performance.now();
    const result = await request<AuthStatus>("/vault/api/auth/status");
    if (captured === statusRevision) applyStatus(result, started);
    return result;
}
export async function lockVault() {
    clearSensitive(true);
    channel?.postMessage({ kind: "locked" });
    const result = await request<AuthStatus>(
        "/vault/api/auth/lock",
        "POST",
        {},
    );
    adoptStatus(result);
}
export async function downloadFile(
    id: string,
    valid: () => boolean = () => true,
): Promise<void> {
    return downloadEndpoint(
        `/vault/api/files/${encodeURIComponent(id)}/download`,
        valid,
    );
}
export async function downloadWorkdoc(
    id: string,
    valid: () => boolean = () => true,
): Promise<void> {
    return downloadEndpoint(
        `/vault/api/imports/monday/workdocs/${encodeURIComponent(id)}/source`,
        valid,
    );
}
export async function downloadSource(
    id: string,
    valid: () => boolean = () => true,
): Promise<void> {
    return downloadEndpoint(
        `/vault/api/imports/monday/items/${encodeURIComponent(id)}/source`,
        valid,
    );
}
async function downloadEndpoint(
    endpoint: string,
    valid: () => boolean,
): Promise<void> {
    if (!isUnlocked())
        throw new VaultRequestError(
            t("Unlock the Vault before continuing."),
            423,
        );
    const captured = generation;
    const controller = new AbortController();
    controllers.add(controller);
    let url: string | null = null;
    try {
        const response = await fetch(endpoint, {
            method: "POST",
            cache: "no-store",
            credentials: "same-origin",
            signal: controller.signal,
            headers: {
                "X-XSRF-TOKEN": csrf(),
                Accept: "application/octet-stream",
            },
        });
        if ([401, 403, 404, 419, 423, 503].includes(response.status))
            clearSensitive(true);
        if (!response.ok)
            throw new VaultRequestError(
                t("The attachment could not be downloaded."),
                response.status,
            );
        const blob = await response.blob();
        if (!currentGeneration(captured) || !valid())
            throw new VaultRequestError(
                t("The Vault is locked. Sensitive content was cleared."),
                423,
            );
        const disposition = response.headers.get("content-disposition") ?? "";
        const encoded = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
        const ordinary = disposition.match(/filename="([^"]+)"/)?.[1];
        let name = "document";
        try {
            name = encoded ? decodeURIComponent(encoded) : (ordinary ?? name);
        } catch {
            /* Use a neutral download name. */
        }
        url = URL.createObjectURL(blob);
        const anchor = document.createElement("a");
        anchor.href = url;
        anchor.download = name;
        anchor.click();
        anchor.remove();
    } finally {
        controllers.delete(controller);
        if (url) URL.revokeObjectURL(url);
    }
}
function pageHide() {
    clearSensitive();
}
function pageShow() {
    clearSensitive();
    ready.value = false;
    void refreshStatus().catch(() => clearSensitive(true));
}
function visibility() {
    if (document.visibilityState === "visible") {
        tick();
        void refreshStatus().catch(() => clearSensitive(true));
    }
}
export function useVaultSecurity(cleaner: () => void) {
    onMounted(() => {
        cleaners.add(cleaner);
        mounts++;
        if (mounts === 1) {
            interval = setInterval(tick, 250);
            polling = setInterval(() => {
                if (document.visibilityState === "visible")
                    void refreshStatus().catch(() => clearSensitive(true));
            }, 10000);
            window.addEventListener("pagehide", pageHide);
            window.addEventListener("pageshow", pageShow);
            window.addEventListener("beforeunload", pageHide);
            document.addEventListener("visibilitychange", visibility);
            if (typeof BroadcastChannel !== "undefined") {
                channel = new BroadcastChannel("basecrm-vault-lock");
                channel.onmessage = (event) => {
                    if (event.data?.kind === "locked") clearSensitive(true);
                    else if (event.data?.kind === "refresh")
                        void refreshStatus().catch(() => clearSensitive(true));
                };
            }
        }
        void refreshStatus().catch(() => {
            clearSensitive(true);
            ready.value = true;
        });
    });
    onBeforeUnmount(() => {
        cleaner();
        cleaners.delete(cleaner);
        mounts--;
        if (mounts === 0) {
            clearSensitive();
            ready.value = false;
            if (interval) clearInterval(interval);
            if (polling) clearInterval(polling);
            if (expiryTimer) clearTimeout(expiryTimer);
            channel?.close();
            channel = null;
            window.removeEventListener("pagehide", pageHide);
            window.removeEventListener("pageshow", pageShow);
            window.removeEventListener("beforeunload", pageHide);
            document.removeEventListener("visibilitychange", visibility);
        }
    });
    return {
        status: readonly(status),
        remaining: readonly(remaining),
        ready: readonly(ready),
        unlocked: computed(
            () =>
                ready.value && !!status.value?.unlocked && remaining.value > 0,
        ),
        refreshStatus,
        lockVault,
    };
}
