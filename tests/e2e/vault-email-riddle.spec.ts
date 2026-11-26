import { expect, test, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { mkdir } from "node:fs/promises";
import { findMailLink, inboxIds, signInVault } from "./vault-login";

const origin = "http://127.0.0.1:8120";
const hebrew = "http://127.0.0.1:8121";
interface Fixture {
    email: string;
    user_id: number;
    contact_id: number;
    question: string;
    answer: string;
}
let fixture: Fixture;
let project = "";
function command(mode: "setup" | "cleanup" | "expire", name: string) {
    return JSON.parse(
        execFileSync(
            "php",
            ["scripts/vault-browser-fixtures.php", mode, name, "email_riddle"],
            { encoding: "utf8", timeout: 30000 },
        ),
    );
}
async function status(page: Page) {
    const response = await page.request.get(
        new URL("/vault/api/auth/status", page.url()).href,
        { headers: { Accept: "application/json" } },
    );
    expect(response.status()).toBe(200);
    return response.json();
}
async function unlock(page: Page, language: "en" | "he", wrongAnswer = false) {
    const he = language === "he";
    await page
        .getByRole("button", {
            name: he ? "פתיחת הכספת" : "Unlock Vault",
            exact: true,
        })
        .click();
    for (const label of [
        "Vault password",
        "New Vault password",
        "Authenticator code",
        "Vault invitation",
        "Recovery code",
    ])
        await expect(page.getByLabel(label, { exact: true })).toHaveCount(0);
    await expect(
        page.getByLabel(he ? "סיסמת החידה של הכספת" : "Vault passphrase", {
            exact: true,
        }),
    ).toHaveCount(0);
    const before = await inboxIds(page);
    await page
        .getByRole("button", {
            name: he
                ? "שליחת אימייל אימות לכספת"
                : "Send Vault confirmation email",
            exact: true,
        })
        .click();
    expect((await status(page)).step).toBe("email");
    const link = await findMailLink(page, fixture.email, before, "vault");
    await page.request.get(link);
    await page.request.head(new URL("/vault/confirm", page.url()).href);
    expect((await status(page)).step).toBe("email");
    const confirmation = await page.context().newPage();
    await confirmation.goto(link);
    await expect(confirmation).not.toHaveURL(/#.*token=/);
    await confirmation
        .getByRole("button", {
            name: he ? "אישור בקשת הכספת הזאת" : "Confirm this Vault request",
            exact: true,
        })
        .click();
    await expect(
        confirmation.getByText(fixture.question, { exact: true }),
    ).toBeVisible();
    const answer = confirmation.getByLabel(
        he ? "סיסמת החידה של הכספת" : "Vault passphrase",
        { exact: true },
    );
    await expect(answer).toHaveAttribute("type", "password");
    await expect(
        confirmation.getByLabel(
            he ? "קוד מאפליקציית האימות" : "Authenticator code",
            { exact: true },
        ),
    ).toHaveCount(0);
    const submit = confirmation.getByRole("button", {
        name: he ? "פתיחה ל־300 שניות" : "Unlock for 300 seconds",
        exact: true,
    });
    if (wrongAnswer) {
        await answer.fill("A deliberately wrong fictional answer");
        const failed = confirmation.waitForResponse(
            (response) =>
                response.request().method() === "POST" &&
                response.url().endsWith("/vault/api/auth/factor"),
        );
        await submit.click();
        expect((await failed).status()).toBe(422);
        expect((await status(confirmation)).unlocked).toBe(false);
    }
    await answer.fill(fixture.answer);
    const completed = confirmation.waitForResponse(
        (response) =>
            response.request().method() === "POST" &&
            response.url().endsWith("/vault/api/auth/factor"),
    );
    await submit.click();
    const response = await completed;
    expect(response.status()).toBe(200);
    const window = await response.json();
    expect(Date.parse(window.expires_at) - Date.parse(window.issued_at)).toBe(
        300000,
    );
    await expect(
        confirmation.getByText(
            he
                ? "להמשך יש לחזור ללשונית הכספת המקורית. השרת קובע את זמן הפתיחה שנותר."
                : "Return to the original Vault tab to continue. The server controls the remaining unlock time.",
            { exact: true },
        ),
    ).toBeVisible();
    const notifications = await confirmation.evaluate(
        () =>
            (window as unknown as { vaultChannelMessages: unknown[] })
                .vaultChannelMessages,
    );
    expect(notifications).toContainEqual({ kind: "refresh" });
    for (const message of notifications)
        expect(Object.keys(message as object)).toEqual(["kind"]);
    await confirmation.close();
    await page.bringToFront();
    await expect(
        page.getByRole("button", {
            name: he ? "נעילת הכספת" : "Lock Vault",
            exact: true,
        }),
    ).toBeVisible({ timeout: 4000 });
    await expect(
        page.getByRole("heading", {
            name: he ? "פתיחת הכספת" : "Unlock Vault",
            exact: true,
        }),
    ).toHaveCount(0);
    return window;
}

test.beforeEach(async ({}, info) => {
    if (
        info.config.workers !== 1 ||
        !["desktop", "mobile"].includes(info.project.name)
    )
        throw new Error("Riddle fixtures require serial recognized projects.");
    project = info.project.name;
    command("cleanup", "desktop");
    command("cleanup", "mobile");
    fixture = command("setup", project);
});
test.afterEach(async () => {
    command("cleanup", project);
});

test("administrator Vault email and shared passphrase unlock, save and clear correctly in English and Hebrew RTL", async ({
    page,
    context,
}, info) => {
    test.setTimeout(150000);
    page.setDefaultTimeout(8000);
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await context.addInitScript(() => {
        const state = window as unknown as { vaultChannelMessages: unknown[] };
        state.vaultChannelMessages = [];
        const post = BroadcastChannel.prototype.postMessage;
        BroadcastChannel.prototype.postMessage = function (
            this: BroadcastChannel,
            message: unknown,
        ) {
            if (this.name === "basecrm-vault-lock")
                state.vaultChannelMessages.push(structuredClone(message));
            return post.call(this, message);
        };
    });
    await signInVault(page, fixture.email, origin);
    await page.goto(`${origin}/vault`);
    const initial = await status(page);
    expect(initial.auth_mode).toBe("email_riddle");
    expect(initial.approved).toBe(true);
    expect(initial.step).toBe("email_start");
    const firstWindow = await unlock(page, "en", true);
    await expect(
        page.getByText("Approve Vault enrollment", { exact: true }),
    ).toHaveCount(0);
    await page
        .getByRole("button", { name: "New Vault item", exact: true })
        .click();
    const form = page
        .locator('section[aria-labelledby="vault-item-title"] form')
        .first();
    await expect(form.locator('input[type="radio"]:checked')).toHaveCount(0);
    await form
        .getByLabel("Item title", { exact: true })
        .fill("Fictional portal access");
    await form
        .getByRole("combobox", { name: /^Customer/ })
        .selectOption(`contacts:${fixture.contact_id}`);
    await form
        .getByRole("radio", {
            name: "Level 2 — Customer permissions",
            exact: true,
        })
        .check();
    const labels = form.getByLabel("Field label", { exact: true });
    const values = form.getByLabel("Field value", { exact: true });
    await labels.nth(0).fill("Portal URL");
    await values.nth(0).fill("https://portal.example.test");
    await labels.nth(1).fill("Portal account");
    await values.nth(1).fill("yael@demo.example.test");
    await labels.nth(2).fill("Review secret");
    const marker = "FICTIONAL_RIDDLE_ITEM_PRIVATE_VALUE";
    await values.nth(2).fill(marker);
    await form
        .getByLabel("Secure notes", { exact: true })
        .fill("Fictional shared-access review only.");
    const saved = page.waitForResponse(
        (response) =>
            response.request().method() === "POST" &&
            response.url().endsWith("/vault/api/items"),
    );
    await form
        .getByRole("button", { name: "Save Vault item", exact: true })
        .click();
    const savedResponse = await saved;
    expect(savedResponse.status()).toBe(201);
    const created = (await savedResponse.json()).item;
    await expect(
        page.getByRole("link", {
            name: "Fictional portal access",
            exact: true,
        }),
    ).toBeVisible();
    expect((await status(page)).expires_at).toBe(firstWindow.expires_at);
    expect(await page.content()).not.toContain(marker);
    const persisted = await page.evaluate(() =>
        JSON.stringify({
            history: history.state,
            local: { ...localStorage },
            session: { ...sessionStorage },
        }),
    );
    expect(persisted).not.toContain(fixture.answer.trim());
    expect(persisted).not.toContain(marker);
    await page.goto(`${hebrew}/people/contacts/${fixture.contact_id}`);
    await expect(page.locator("html")).toHaveAttribute("lang", "he");
    await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
    await page
        .getByRole("link", { name: "Fictional portal access", exact: true })
        .click();
    const item = page.locator('section[aria-labelledby="vault-item-title"]');
    const field = item
        .locator("article")
        .filter({ has: page.getByText("Review secret", { exact: true }) });
    await expect(field.getByText("הערך מוסתר", { exact: true })).toBeVisible();
    await field.getByRole("button", { name: "הצגת הערך", exact: true }).click();
    await expect(field.locator("pre")).toHaveText(marker);
    const mirror = await context.newPage();
    await mirror.goto(page.url());
    await mirror
        .getByRole("link", { name: "Fictional portal access", exact: true })
        .click();
    const mirrorItem = mirror.locator(
        'section[aria-labelledby="vault-item-title"]',
    );
    await mirrorItem
        .locator("article")
        .filter({ has: mirror.getByText("Review secret", { exact: true }) })
        .getByRole("button", { name: "הצגת הערך", exact: true })
        .click();
    await expect(mirrorItem.locator("pre")).toHaveText(marker);
    await page.bringToFront();
    await page
        .getByRole("button", { name: "נעילת הכספת", exact: true })
        .click();
    await expect(item).toHaveCount(0);
    await expect(mirrorItem).toHaveCount(0, { timeout: 4000 });
    await mirror.close();
    await page.bringToFront();
    const lockNotifications = await page.evaluate(
        () =>
            (window as unknown as { vaultChannelMessages: unknown[] })
                .vaultChannelMessages,
    );
    expect(lockNotifications).toContainEqual({ kind: "locked" });
    for (const message of lockNotifications)
        expect(Object.keys(message as object)).toEqual(["kind"]);
    expect(await page.content()).not.toContain(marker);
    expect(
        await page.evaluate(async (id: string) => {
            const token = decodeURIComponent(
                document.cookie
                    .split("; ")
                    .find((value) => value.startsWith("XSRF-TOKEN="))
                    ?.split("=")[1] ?? "",
            );
            return (
                await fetch(`/vault/api/items/${id}/content`, {
                    method: "POST",
                    cache: "no-store",
                    headers: {
                        Accept: "application/json",
                        "X-XSRF-TOKEN": token,
                        "Content-Type": "application/json",
                    },
                    body: "{}",
                })
            ).status;
        }, created.id),
    ).toBe(423);
    await unlock(page, "he");
    await page
        .getByRole("link", { name: "Fictional portal access", exact: true })
        .click();
    await field.getByRole("button", { name: "הצגת הערך", exact: true }).click();
    await expect(field.locator("pre")).toHaveText(marker);
    const evidence =
        "storage/app/private/acceptance-evidence/vault-email-riddle-20261126";
    await mkdir(evidence, { recursive: true, mode: 0o700 });
    await field
        .getByRole("button", { name: "הסתרת הערך", exact: true })
        .click();
    await page.screenshot({
        path: `${evidence}/${info.project.name}-hebrew.png`,
        fullPage: true,
        animations: "disabled",
    });
    command("expire", project);
    await expect(item).toHaveCount(0, { timeout: 15000 });
    expect((await status(page)).unlocked).toBe(false);
    expect(await page.content()).not.toContain(marker);
    expect(await page.content()).not.toContain(fixture.answer);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    expect(errors).toEqual([]);
});
