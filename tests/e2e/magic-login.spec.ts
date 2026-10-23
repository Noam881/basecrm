import { test, expect } from "./fixtures";
import type { APIRequestContext, Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";

const fixture = JSON.parse(readFileSync(".local-fixtures.json", "utf8"));
const mailpit = "http://127.0.0.1:8028";
const genericStatus =
    "If this email belongs to an active account, a sign-in link is on its way. Check your inbox.";
const invalidMessage =
    "This sign-in link is invalid or has expired. Request a new link to continue.";

type MessageSummary = {
    ID: string;
    To: { Address: string }[];
    Subject: string;
    Created: string;
};

async function accountMessages(request: APIRequestContext, email: string) {
    const response = await request.get(`${mailpit}/api/v1/messages`);
    expect(response.ok(), "Local Mailpit should be available").toBe(true);
    const inbox = (await response.json()) as { messages: MessageSummary[] };
    return inbox.messages
        .filter(
            (message) =>
                message.To.some(
                    (recipient) =>
                        recipient.Address.toLowerCase() === email.toLowerCase(),
                ) && message.Subject.endsWith("sign-in link"),
        )
        .sort((a, b) => Date.parse(b.Created) - Date.parse(a.Created));
}

async function signOut(page: Page) {
    const open = page.getByRole("button", {
        name: "Open navigation",
        exact: true,
    });
    if (await open.isVisible()) await open.click();
    await page
        .locator(".crm-sidebar")
        .getByRole("button", { name: "Sign out", exact: true })
        .click();
    await expect(page).toHaveURL(/\/login$/);
}

test("email sign-in requires explicit confirmation and is single use", async ({
    page,
    request,
}, info) => {
    const credential = fixture[`tables_alpha_${info.project.name}`];
    expect(
        /@(?:[a-z0-9-]+\.)*example\.test$/i.test(credential.email),
        "Email sign-in tests require an isolated fictional account",
    ).toBe(true);
    const previousIds = new Set(
        (await accountMessages(request, credential.email)).map(({ ID }) => ID),
    );

    await page.goto("/login");
    await page
        .getByRole("link", { name: "Email me a sign-in link", exact: true })
        .click();
    await expect(
        page.getByRole("heading", { name: "Sign in with email", exact: true }),
    ).toBeVisible();
    await expect(page.getByText(/No password needed\./)).toBeVisible();
    await expect(page.locator('input[type="password"]')).toHaveCount(0);
    await page
        .getByLabel("Email address", { exact: true })
        .fill(credential.email);
    await page
        .getByRole("button", { name: "Email me a sign-in link", exact: true })
        .click();
    await expect(page.getByRole("status")).toHaveText(genericStatus);

    // Deliver only through the local installation's existing database queue.
    execFileSync(
        "php",
        [
            "artisan",
            "--env=alpha",
            "queue:work",
            "database",
            "--stop-when-empty",
            "--tries=1",
        ],
        { stdio: "ignore", timeout: 15000 },
    );
    let messageId: string | undefined;
    await expect
        .poll(
            async () => {
                messageId = (
                    await accountMessages(request, credential.email)
                ).find(({ ID }) => !previousIds.has(ID))?.ID;
                return Boolean(messageId);
            },
            {
                message:
                    "A new sign-in email should reach the fictional account",
            },
        )
        .toBe(true);
    const response = await request.get(
        `${mailpit}/api/v1/message/${messageId}`,
    );
    expect(response.ok()).toBe(true);
    const message = (await response.json()) as { HTML: string; Text: string };
    // Read only this fictional message; never log its body or signed link.
    const signInUrl = await page.evaluate(({ HTML, Text }) => {
        const document = new DOMParser().parseFromString(HTML, "text/html");
        return (
            Array.from(document.querySelectorAll("a[href]"))
                .map((link) => link.getAttribute("href") ?? "")
                .find((href) =>
                    /\/magic-login\/[A-Za-z0-9]{64}\?/.test(href),
                ) ??
            Text.match(/https?:\/\/[^\s<>"']+/g)?.find((href) =>
                /\/magic-login\/[A-Za-z0-9]{64}\?/.test(href),
            ) ??
            ""
        );
    }, message);
    const localLink =
        /^http:\/\/127\.0\.0\.1:8091\/magic-login\/[A-Za-z0-9]{64}\?/.test(
            signInUrl,
        );
    expect(
        localLink,
        "The fictional link should target the local Alpha app",
    ).toBe(true);

    for (let visit = 0; visit < 2; visit++) {
        await page.goto(signInUrl);
        await expect(
            page.getByRole("heading", { name: "Confirm sign-in", exact: true }),
        ).toBeVisible();
        await expect(
            page.getByText(credential.email, { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByRole("button", {
                name: "Continue to sign in",
                exact: true,
            }),
        ).toBeVisible();
        await expect(page.locator('input[type="password"]')).toHaveCount(0);
        const protectedPage = await page.request.get("/dashboard", {
            maxRedirects: 0,
        });
        expect(protectedPage.status()).toBe(302);
        expect(protectedPage.headers().location).toMatch(/\/login$/);
    }

    await page
        .getByRole("button", { name: "Continue to sign in", exact: true })
        .click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(
        page.getByRole("heading", { name: "Overview", exact: true }),
    ).toBeVisible();
    await signOut(page);

    await page.goto(signInUrl);
    await expect(page.getByRole("status")).toHaveText(invalidMessage);
    await expect(
        page.getByRole("button", { name: "Continue to sign in", exact: true }),
    ).toHaveCount(0);
    await page
        .getByRole("link", { name: "Request a new sign-in link" })
        .click();
    await expect(page).toHaveURL(/\/magic-login$/);
    await expect(page.getByLabel("Email address", { exact: true })).toHaveValue(
        "",
    );
});

test("an unknown sign-in link shows a generic invalid state", async ({
    page,
}) => {
    await page.goto(`/magic-login/${"0".repeat(64)}`);
    await expect(
        page.getByRole("heading", { name: "Confirm sign-in", exact: true }),
    ).toBeVisible();
    await expect(page.getByRole("status")).toHaveText(invalidMessage);
    await expect(
        page.getByRole("button", { name: "Continue to sign in", exact: true }),
    ).toHaveCount(0);
    await expect(page.getByText(/^Sign in as/)).toHaveCount(0);
    await expect(page.locator('input[type="password"]')).toHaveCount(0);
    await page
        .getByRole("link", { name: "Request a new sign-in link" })
        .click();
    await expect(
        page.getByRole("heading", { name: "Sign in with email", exact: true }),
    ).toBeVisible();
});
