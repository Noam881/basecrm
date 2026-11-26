import { expect, test, type Page, type Locator } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { chmod, mkdir, readFile, rm } from "node:fs/promises";
import { findMailLink, inboxIds, signInVault } from "./vault-login";
const origin = "http://127.0.0.1:8110";
const hebrew = "http://127.0.0.1:8111";
interface Fixture {
    email: string;
    user_id: number;
    contact_id: number;
    invitation: string;
    password: string;
}
let fixture: Fixture;
let project = "";
function command(
    mode: "setup" | "cleanup" | "code" | "expire",
    name: string,
    input?: unknown,
) {
    return JSON.parse(
        execFileSync(
            "php",
            ["scripts/vault-browser-fixtures.php", mode, name],
            {
                encoding: "utf8",
                input: input === undefined ? undefined : JSON.stringify(input),
                timeout: 30000,
            },
        ),
    );
}
async function otp(secret: string, afterEnrollment = false): Promise<string> {
    for (let attempt = 0; attempt < 3; attempt++) {
        const result = command("code", project, {
            secret,
            after_enrollment: afterEnrollment,
        });
        if (result.code) return result.code;
        if (
            !Number.isInteger(result.wait_seconds) ||
            result.wait_seconds < 1 ||
            result.wait_seconds > 31
        )
            throw new Error("Unbounded fixture TOTP wait refused.");
        await new Promise((resolve) =>
            setTimeout(resolve, result.wait_seconds * 1000),
        );
    }
    throw new Error("No unused fixture TOTP step became available.");
}
async function api(page: Page, path: string, method = "GET", body?: unknown) {
    if (method === "GET") {
        const response = await page.request.get(
            new URL(path, page.url()).href,
            { headers: { Accept: "application/json" }, timeout: 10000 },
        );
        return {
            status: response.status(),
            headers: response.headers(),
            body: await response.json(),
        };
    }
    return page.evaluate(
        async ({ path, method, body }) => {
            const token = decodeURIComponent(
                document.cookie
                    .split("; ")
                    .find((value) => value.startsWith("XSRF-TOKEN="))
                    ?.split("=")[1] ?? "",
            );
            const response = await fetch(path, {
                method,
                cache: "no-store",
                headers: {
                    Accept: "application/json",
                    "X-XSRF-TOKEN": token,
                    ...(body === undefined
                        ? {}
                        : { "Content-Type": "application/json" }),
                },
                ...(body === undefined ? {} : { body: JSON.stringify(body) }),
            });
            return {
                status: response.status,
                headers: Object.fromEntries(response.headers),
                body: await response.json(),
            };
        },
        { path, method, body },
    );
}
function editor(page: Page) {
    return page
        .locator('section[aria-labelledby="vault-item-title"] form')
        .first();
}
function itemView(page: Page) {
    return page.locator('section[aria-labelledby="vault-item-title"]');
}
function fieldView(page: Page, label: string) {
    return itemView(page)
        .locator("article")
        .filter({ has: page.getByText(label, { exact: true }) });
}
async function confirmMail(
    page: Page,
    email: string,
    before: Set<string>,
    code: string,
    recovery = false,
    language: "en" | "he" = "en",
    race = false,
) {
    const link = await findMailLink(page, email, before, "vault");
    const url = new URL(link);
    const scanning = await page.request.get(link);
    expect(scanning.ok()).toBe(true);
    await page.request.head(`${url.origin}/vault/confirm`);
    expect((await api(page, "/vault/api/auth/status")).body.step).toBe("email");
    const confirmation = await page.context().newPage();
    await confirmation.goto(link);
    await expect(confirmation).not.toHaveURL(/#.*token=/);
    expect((await api(page, "/vault/api/auth/status")).body.step).toBe("email");
    if (race) {
        await confirmation.close();
        await page.bringToFront();
        const fragment = new URLSearchParams(url.hash.slice(1));
        const xsrf = await page.evaluate(() =>
            decodeURIComponent(
                document.cookie
                    .split("; ")
                    .find((value) => value.startsWith("XSRF-TOKEN="))
                    ?.split("=")[1] ?? "",
            ),
        );
        const body = {
            challenge_id: fragment.get("challenge"),
            token: fragment.get("token"),
        };
        const responses = await Promise.all(
            [origin, hebrew].map((base) =>
                page.request.post(`${base}/vault/api/auth/email`, {
                    data: body,
                    headers: {
                        Accept: "application/json",
                        "X-XSRF-TOKEN": xsrf,
                    },
                }),
            ),
        );
        const statuses = responses.map((response) => response.status());
        expect(statuses.filter((status) => status === 200)).toHaveLength(1);
        expect(
            statuses.filter((status) => [403, 422].includes(status)),
        ).toHaveLength(1);
        await page
            .getByRole("button", {
                name: "Check email confirmation",
                exact: true,
            })
            .click();
        await page.getByLabel("Authenticator code", { exact: true }).fill(code);
        const factor = page.waitForResponse(
            (response) =>
                response.request().method() === "POST" &&
                response.url().endsWith("/vault/api/auth/factor"),
        );
        await page
            .getByRole("button", {
                name: "Unlock for 300 seconds",
                exact: true,
            })
            .click();
        expect((await factor).status()).toBe(200);
        await page.bringToFront();
        await expect
            .poll(
                async () =>
                    (await api(page, "/vault/api/auth/status")).body.unlocked,
            )
            .toBe(true);
        return;
    }
    await confirmation
        .getByRole("button", {
            name:
                language === "he"
                    ? "אישור בקשת הכספת הזאת"
                    : "Confirm this Vault request",
            exact: true,
        })
        .click();
    if (recovery) {
        await confirmation
            .getByRole("checkbox", {
                name:
                    language === "he"
                        ? "שימוש בקוד שחזור"
                        : "Use a recovery code",
                exact: true,
            })
            .check();
        await confirmation
            .getByLabel(language === "he" ? "קוד שחזור" : "Recovery code", {
                exact: true,
            })
            .fill(code);
    } else
        await confirmation
            .getByLabel(
                language === "he"
                    ? "קוד מאפליקציית האימות"
                    : "Authenticator code",
                { exact: true },
            )
            .fill(code);
    await confirmation
        .getByRole("button", {
            name:
                language === "he"
                    ? "פתיחה ל־300 שניות"
                    : "Unlock for 300 seconds",
            exact: true,
        })
        .click();
    await expect(
        confirmation.getByText(
            language === "he"
                ? "להמשך יש לחזור ללשונית הכספת המקורית. השרת קובע את זמן הפתיחה שנותר."
                : "Return to the original Vault tab to continue. The server controls the remaining unlock time.",
            { exact: true },
        ),
    ).toBeVisible();
    await confirmation.close();
    await page.bringToFront();
}

test.use({ actionTimeout: 8000 });
test.beforeEach(async ({}, info) => {
    if (
        info.config.workers !== 1 ||
        !["desktop", "mobile"].includes(info.project.name)
    )
        throw new Error("Vault fixtures require serial recognized projects.");
    project = info.project.name;
    command("cleanup", "desktop");
    command("cleanup", "mobile");
    fixture = command("setup", project);
});
test.afterEach(async () => {
    command("cleanup", project);
});

test("Vault authentication, encrypted customer items and automatic clearing work in English and Hebrew RTL", async ({
    page,
    context,
}, info) => {
    test.setTimeout(240000);
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await context.grantPermissions(["clipboard-read", "clipboard-write"], {
        origin,
    });
    await context.grantPermissions(["clipboard-read", "clipboard-write"], {
        origin: hebrew,
    });
    await signInVault(page, fixture.email);
    await page.goto(`${origin}/vault`);
    await expect(
        page.getByRole("heading", { name: "Vault", exact: true, level: 1 }),
    ).toBeVisible();
    await page
        .getByRole("button", { name: "New Vault item", exact: true })
        .click();
    await page
        .getByLabel("Vault invitation", { exact: true })
        .fill(fixture.invitation);
    await page
        .getByLabel("New Vault password", { exact: true })
        .fill(fixture.password);
    await page
        .getByLabel("Confirm Vault password", { exact: true })
        .fill(fixture.password);
    const setupResponse = page.waitForResponse(
        (response) =>
            response.request().method() === "POST" &&
            response.url().endsWith("/vault/api/auth/enroll"),
    );
    await page
        .getByRole("button", { name: "Set up authenticator", exact: true })
        .click();
    const setup = await (await setupResponse).json();
    await page
        .getByLabel("Authenticator code", { exact: true })
        .fill(await otp(setup.secret));
    const confirmed = page.waitForResponse(
        (response) =>
            response.request().method() === "POST" &&
            response.url().endsWith("/vault/api/auth/enroll/confirm"),
    );
    await page
        .getByRole("button", { name: "Confirm authenticator", exact: true })
        .click();
    const enrolled = await (await confirmed).json();
    expect(enrolled.recovery_codes).toHaveLength(10);
    await page
        .getByRole("button", {
            name: "I saved the recovery codes",
            exact: true,
        })
        .click();
    const before = await inboxIds(page);
    await page
        .getByLabel("Vault password", { exact: true })
        .fill(fixture.password);
    await page
        .getByRole("button", {
            name: "Send Vault confirmation email",
            exact: true,
        })
        .click();
    await confirmMail(
        page,
        fixture.email,
        before,
        await otp(setup.secret, true),
        false,
        "en",
        true,
    );
    await expect(
        page.getByRole("heading", { name: "New Vault item", exact: true }),
    ).toBeVisible();
    const firstWindow = (await api(page, "/vault/api/auth/status")).body;
    expect(
        Date.parse(firstWindow.expires_at) - Date.parse(firstWindow.issued_at),
    ).toBe(300000);
    const form = editor(page);
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
    await labels.nth(0).fill("Portal URL");
    await labels.nth(1).fill("Portal account");
    await labels.nth(2).fill("Portal password");
    const values = form.getByLabel("Field value", { exact: true });
    await values.nth(0).fill("https://portal.example.test/login");
    await values.nth(1).fill("yael@demo.example.test");
    await values.nth(2).fill("Fictional-Only-Password-2048");
    await form
        .getByRole("button", { name: "Add custom field", exact: true })
        .click();
    const custom = form.getByRole("group", { name: "Field 4", exact: true });
    await custom
        .getByLabel("Field label", { exact: true })
        .fill("Recovery notes");
    await custom
        .getByRole("combobox", { name: /^Field type/ })
        .selectOption("secret_multiline");
    const marker = "  FICTIONAL_PRIVATE_MATERIAL\nשורת בדיקה עם רווחים  \n";
    await custom.getByLabel("Field value", { exact: true }).fill(marker);
    await form
        .getByLabel("Secure notes", { exact: true })
        .fill("  Fictional follow-up note\nפרטי דוגמה בלבד  ");
    const saved = page.waitForResponse(
        (response) =>
            response.request().method() === "POST" &&
            response.url().endsWith("/vault/api/items"),
    );
    await form
        .getByRole("button", { name: "Save Vault item", exact: true })
        .click();
    const item = (await (await saved).json()).item;
    await expect(
        page.getByRole("link", {
            name: "Fictional portal access",
            exact: true,
        }),
    ).toBeVisible();
    expect(await page.content()).not.toContain("FICTIONAL_PRIVATE_MATERIAL");
    expect(
        await page.evaluate(() =>
            JSON.stringify({
                history: history.state,
                local: { ...localStorage },
                session: { ...sessionStorage },
            }),
        ),
    ).not.toContain("FICTIONAL_PRIVATE_MATERIAL");
    await page
        .getByRole("link", { name: "Fictional portal access", exact: true })
        .click();
    const secretField = fieldView(page, "Recovery notes");
    await expect(
        secretField.getByText("Value hidden", { exact: true }),
    ).toBeVisible();
    await secretField
        .getByRole("button", { name: "Show value", exact: true })
        .click();
    expect(await secretField.locator("pre").textContent()).toBe(marker);
    await secretField
        .getByRole("button", { name: "Copy value", exact: true })
        .click();
    await expect(page.getByText("Copied.", { exact: true })).toBeVisible();
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(
        marker,
    );
    const fileBytes = Buffer.from(
        "  FICTIONAL_ENCRYPTED_ATTACHMENT\nמסמך פיקטיבי בלבד  ",
    );
    await itemView(page)
        .getByLabel("Add encrypted attachment", { exact: true })
        .setInputFiles({
            name: "fictional-private.txt",
            mimeType: "text/plain",
            buffer: fileBytes,
        });
    await itemView(page)
        .getByRole("button", {
            name: "Upload encrypted attachment",
            exact: true,
        })
        .click();
    const file = itemView(page)
        .locator("article")
        .filter({
            has: page.getByText("fictional-private.txt", { exact: true }),
        });
    await expect(file).toBeVisible();
    const downloaded = page.waitForEvent("download");
    await file
        .getByRole("button", { name: "Download attachment", exact: true })
        .click();
    const output = info.outputPath("fictional-download.txt");
    await (await downloaded).saveAs(output);
    expect(await readFile(output)).toEqual(fileBytes);
    await rm(output);
    expect((await api(page, "/vault/api/auth/status")).body.expires_at).toBe(
        firstWindow.expires_at,
    );
    await secretField
        .getByRole("button", { name: "Hide value", exact: true })
        .click();
    const evidence = "storage/app/private/acceptance-evidence/vault-20261126";
    await mkdir(evidence, { recursive: true, mode: 0o700 });
    const englishImage = `${evidence}/${project}-english.png`;
    await page.screenshot({ path: englishImage, fullPage: true });
    await chmod(englishImage, 0o600);
    await page.goto(`${hebrew}/people/contacts/${fixture.contact_id}`);
    await expect(page.locator("html")).toHaveAttribute("lang", "he");
    await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
    await expect(
        page.getByRole("link", {
            name: "Fictional portal access",
            exact: true,
        }),
    ).toHaveCount(1);
    await page
        .getByRole("button", { name: "פריט חדש בכספת", exact: true })
        .click();
    const hebrewForm = editor(page);
    await expect(
        hebrewForm.getByRole("combobox", { name: /^לקוח/ }),
    ).toBeDisabled();
    await expect(
        hebrewForm.getByRole("combobox", { name: /^לקוח/ }),
    ).toHaveValue(`contacts:${fixture.contact_id}`);
    await hebrewForm
        .getByRole("button", { name: "ביטול", exact: true })
        .click();
    await page
        .getByRole("link", { name: "Fictional portal access", exact: true })
        .click();
    await itemView(page)
        .getByRole("button", { name: "עריכת פריט בכספת", exact: true })
        .click();
    await editor(page)
        .getByLabel("כותרת הפריט", { exact: true })
        .fill("גישה פיקטיבית לפורטל");
    const editedCustom = editor(page).getByRole("group", {
        name: "שדה 4",
        exact: true,
    });
    await editedCustom
        .getByRole("button", { name: "עריכת הערך המוסתר", exact: true })
        .click();
    await expect(
        editedCustom.getByLabel("ערך השדה", { exact: true }),
    ).toHaveValue(marker);
    await editor(page)
        .getByRole("button", { name: "שמירת הפריט בכספת", exact: true })
        .click();
    await expect(
        page.getByRole("link", { name: "גישה פיקטיבית לפורטל", exact: true }),
    ).toBeVisible();
    await page
        .getByRole("link", { name: "גישה פיקטיבית לפורטל", exact: true })
        .click();
    await expect(
        fieldView(page, "Recovery notes").getByText("הערך מוסתר", {
            exact: true,
        }),
    ).toBeVisible();
    const hebrewImage = `${evidence}/${project}-hebrew.png`;
    await page.screenshot({ path: hebrewImage, fullPage: true });
    await chmod(hebrewImage, 0o600);
    const mirror = await context.newPage();
    await mirror.goto(`${hebrew}/vault`);
    await mirror
        .getByRole("link", { name: "גישה פיקטיבית לפורטל", exact: true })
        .click();
    await fieldView(mirror, "Recovery notes")
        .getByRole("button", { name: "הצגת הערך", exact: true })
        .click();
    expect(
        await fieldView(mirror, "Recovery notes").locator("pre").textContent(),
    ).toBe(marker);
    await page.bringToFront();
    let release: (() => void) | undefined;
    let held: (() => void) | undefined;
    const inFlight = new Promise<void>((resolve) => {
        held = resolve;
    });
    const responseRelease = new Promise<void>((resolve) => {
        release = resolve;
    });
    await page.route(`**/vault/api/items/${item.id}/content`, async (route) => {
        const result = await route.fetch();
        held?.();
        await responseRelease;
        try {
            await route.fulfill({ response: result });
        } catch {
            /* The lock may already have aborted this request. */
        }
    });
    await fieldView(page, "Recovery notes")
        .getByRole("button", { name: "הצגת הערך", exact: true })
        .click();
    await inFlight;
    await page
        .getByRole("button", { name: "נעילת הכספת", exact: true })
        .click();
    release?.();
    await page.unroute(`**/vault/api/items/${item.id}/content`);
    await expect(page.locator("[data-vault-sensitive]")).toHaveCount(0);
    await expect(mirror.locator("[data-vault-sensitive]")).toHaveCount(0);
    expect(await page.content()).not.toContain("FICTIONAL_PRIVATE_MATERIAL");
    expect(await mirror.content()).not.toContain("FICTIONAL_PRIVATE_MATERIAL");
    expect(
        (await api(page, `/vault/api/items/${item.id}/content`, "POST", {}))
            .status,
    ).toBe(423);
    await mirror.close();
    await page
        .getByRole("link", { name: "גישה פיקטיבית לפורטל", exact: true })
        .click();
    const nextBefore = await inboxIds(page);
    await page
        .getByLabel("סיסמת הכספת", { exact: true })
        .fill(fixture.password);
    await page
        .getByRole("button", { name: "שליחת אימייל אימות לכספת", exact: true })
        .click();
    await confirmMail(
        page,
        fixture.email,
        nextBefore,
        enrolled.recovery_codes[0],
        true,
        "he",
    );
    await fieldView(page, "Recovery notes")
        .getByRole("button", { name: "הצגת הערך", exact: true })
        .click();
    expect(
        await fieldView(page, "Recovery notes").locator("pre").textContent(),
    ).toBe(marker);
    command("expire", project);
    await expect(itemView(page)).toHaveCount(0, { timeout: 15000 });
    expect(
        (await api(page, `/vault/api/items/${item.id}/content`, "POST", {}))
            .status,
    ).toBe(423);
    await page.reload();
    expect(await page.content()).not.toContain("FICTIONAL_PRIVATE_MATERIAL");
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    expect(errors).toEqual([]);
});
