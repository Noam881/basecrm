import { test, expect } from "./fixtures";
import type { Page } from "@playwright/test";
import { requestSignInLink } from "./auth";

const invalidMessage =
    "This sign-in link is invalid or has expired. Request a new link to continue.";

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

test("opening an email link signs in immediately and navigation stays usable", async ({
    page,
}, info) => {
    const signInUrl = await requestSignInLink(page, info.project.name);
    // Mail scanners and prefetch requests must not consume a link.
    for (let scan = 0; scan < 2; scan++) {
        const response = await page.request.get(signInUrl);
        expect(response.ok()).toBe(true);
    }
    const protectedPage = await page.request.get("/dashboard", {
        maxRedirects: 0,
    });
    expect(protectedPage.status()).toBe(302);

    await page.goto(signInUrl);
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(
        page.getByRole("heading", { name: "Overview", exact: true }),
    ).toBeVisible();
    await expect(page.locator('input[type="password"]')).toHaveCount(0);

    // A second click while already signed in keeps the working session.
    await page.goto(signInUrl);
    await expect(page).toHaveURL(/\/dashboard$/);
    for (const [name, path] of [
        ["Leads", "/leads"],
        ["Follow-ups", "/tasks"],
        ["Reminders", "/reminders"],
    ]) {
        const open = page.getByRole("button", {
            name: "Open navigation",
            exact: true,
        });
        if (await open.isVisible()) await open.click();
        await page
            .locator(".crm-sidebar")
            .getByRole("link", { name, exact: true })
            .click();
        await expect(page).toHaveURL(new RegExp(`${path}$`));
    }
    await expect(
        page.locator(".crm-sidebar").getByRole("link", {
            name: "Contacts & companies",
            exact: true,
            includeHidden: true,
        }),
    ).toHaveCount(0);
    await page.goto("/leads");
    await page
        .getByRole("link", { name: "Add new contact", exact: true })
        .click();
    await expect(page).toHaveURL(/\/people$/);
    await expect(
        page.getByRole("heading", {
            name: "Add new contact",
            exact: true,
            level: 1,
        }),
    ).toBeVisible();
    await expect(page.locator("table")).toHaveCount(0);
    await page.goto("/settings/security");
    await expect(
        page.getByRole("heading", { name: "Account sign-in", exact: true }),
    ).toBeVisible();
    await expect(page.locator('input[type="password"]')).toHaveCount(0);
    await signOut(page);
    await page.goto(signInUrl);
    await expect(page.getByRole("status")).toHaveText(invalidMessage);
    await page
        .getByRole("link", { name: "Request a new sign-in link" })
        .click();
    await expect(page).toHaveURL(/\/login$/);
});

test("an unknown link offers a fresh email sign-in", async ({ page }) => {
    await page.goto(`/magic-login/${"0".repeat(64)}`);
    await expect(page.getByRole("status")).toHaveText(invalidMessage);
    await expect(page.locator('input[type="password"]')).toHaveCount(0);
    await page
        .getByRole("link", { name: "Request a new sign-in link" })
        .click();
    await expect(page).toHaveURL(/\/login$/);
    await expect(
        page.getByLabel("Email address", { exact: true }),
    ).toBeVisible();
});
