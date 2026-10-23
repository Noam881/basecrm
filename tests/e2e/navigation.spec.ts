import { test, expect } from "@playwright/test";
import { signIn } from "./auth";

test("navigation keeps a clean canvas with a persistent rail or accessible mobile drawer", async ({
    page,
}, info) => {
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await signIn(page, info.project.name);
    await expect(
        page.getByRole("heading", { name: "Overview", exact: true }),
    ).toBeVisible();
    await expect(
        page.locator(".crm-topbar, .crm-eyebrow, .crm-caption"),
    ).toHaveCount(0);
    const sidebar = page.locator(".crm-sidebar");
    await expect(
        sidebar.getByRole("link", {
            name: "Contacts & companies",
            exact: true,
            includeHidden: true,
        }),
    ).toHaveCount(0);
    const open = page.getByRole("button", {
        name: "Open navigation",
        exact: true,
    });
    if (info.project.name === "desktop") {
        await sidebar
            .getByRole("button", { name: "Collapse sidebar", exact: true })
            .click();
        await expect(
            sidebar.getByRole("button", {
                name: "Expand sidebar",
                exact: true,
            }),
        ).toHaveAttribute("aria-expanded", "false");
        await expect(sidebar).toHaveCSS("width", "80px");
        await page.reload();
        await expect(
            sidebar.getByRole("button", {
                name: "Expand sidebar",
                exact: true,
            }),
        ).toBeVisible();
        await sidebar
            .getByRole("navigation", { name: "Main navigation" })
            .getByRole("link", { name: "Leads", exact: true })
            .click();
        await expect(
            page.getByRole("heading", { name: "Leads", exact: true, level: 1 }),
        ).toBeVisible();
        await expect(sidebar).toHaveCSS("width", "80px");
        await page.screenshot({
            path: "docs/screenshots/desktop-collapsed-navigation.png",
            fullPage: false,
        });
        await sidebar
            .getByRole("button", { name: "Expand sidebar", exact: true })
            .click();
        await expect
            .poll(async () => (await sidebar.boundingBox())?.width ?? 0)
            .toBeGreaterThan(200);
        await sidebar.getByRole("link", { name: /^Profile: / }).click();
        await expect(
            page.getByRole("heading", {
                name: "Profile",
                exact: true,
                level: 1,
            }),
        ).toBeVisible();
    } else {
        await expect(open).toBeVisible();
        await expect(sidebar).toBeHidden();
        await open.click();
        await expect(
            page.getByRole("dialog", { name: "Navigation", exact: true }),
        ).toBeVisible();
        expect(
            await page.evaluate(
                () => !!document.activeElement?.closest("dialog"),
            ),
        ).toBeTruthy();
        await page.keyboard.press("Escape");
        await expect(sidebar).toBeHidden();
        await expect(open).toBeFocused();
        await open.click();
        await sidebar
            .getByRole("navigation", { name: "Main navigation" })
            .getByRole("link", { name: "Leads", exact: true })
            .click();
        await expect(
            page.getByRole("heading", { name: "Leads", exact: true, level: 1 }),
        ).toBeVisible();
        await expect(sidebar).toBeHidden();
        await page.screenshot({
            path: "docs/screenshots/mobile-navigation-canvas.png",
            fullPage: false,
        });
        await open.click();
        await sidebar
            .getByRole("navigation", { name: "Main navigation" })
            .getByRole("link", { name: "Leads", exact: true })
            .click();
        await expect(sidebar).toBeHidden();
        await open.click();
        await expect(
            sidebar.getByRole("link", { name: /^Profile: / }),
        ).toBeVisible();
        await expect(sidebar).toHaveCSS(
            "transform",
            "matrix(1, 0, 0, 1, 0, 0)",
        );
        await page.screenshot({
            path: "docs/screenshots/mobile-navigation-drawer.png",
            fullPage: false,
        });
        await sidebar
            .getByRole("button", { name: "Close navigation", exact: true })
            .click();
        await expect(sidebar).toBeHidden();
    }
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
    const cancel = page.getByRole("link", { name: "Cancel", exact: true });
    await expect(cancel).toHaveAttribute("href", "/leads");
    await cancel.click();
    await expect(page).toHaveURL(/\/leads$/);
    await page.emulateMedia({ reducedMotion: "reduce" });
    await expect(sidebar).toHaveCSS("transition-duration", "0s");
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBeTruthy();
    expect(errors).toEqual([]);
});
