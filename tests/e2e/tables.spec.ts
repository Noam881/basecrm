import { test, expect, type Page } from "@playwright/test";
import { signIn } from "./auth";
async function openNavigation(page: Page) {
    const open = page.getByRole("button", {
        name: "Open navigation",
        exact: true,
    });
    if (await open.isVisible()) await open.click();
}
async function navigate(page: Page, name: string) {
    await openNavigation(page);
    await page
        .getByRole("navigation", { name: "Main navigation", exact: true })
        .getByRole("link", { name, exact: true })
        .click();
}
async function login(page: Page, installation: "alpha" | "beta") {
    await signIn(page, test.info().project.name, installation);
    await navigate(page, installation === "alpha" ? "Leads" : "Enquiries");
}
test("table columns reorder, resize, pin, persist and save views", async ({
    page,
}, info) => {
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await login(page, "alpha");
    await page.getByRole("button", { name: "Columns", exact: false }).click();
    let dialog = page.getByRole("dialog");
    await dialog.getByRole("button", { name: "Reset layout" }).click();
    await expect(
        dialog.getByRole("checkbox", { name: "Show Enquiry title column" }),
    ).not.toBeChecked();
    if (info.project.name === "desktop") {
        await dialog
            .locator("li")
            .filter({ hasText: "Company Name" })
            .dragTo(dialog.locator("li").filter({ hasText: "Contact Name" }));
    } else
        await dialog
            .getByRole("button", {
                name: "Move Contact Name right",
                exact: true,
            })
            .click();
    await dialog.getByLabel("Find a column").fill("title");
    await dialog
        .getByRole("checkbox", { name: "Show Enquiry title column" })
        .check();
    await dialog.getByLabel("Find a column").fill("email");
    await dialog
        .getByRole("checkbox", { name: "Show Contact Email column" })
        .check();
    await expect(dialog.getByRole("status")).toHaveText("Layout saved");
    await dialog.getByRole("button", { name: "Done", exact: true }).click();
    let records = page.getByRole("table", { name: "Leads records" });
    await expect(records.locator("th").nth(1)).toContainText("Company Name");
    const resize = records.getByRole("separator", {
        name: "Resize Contact Name column",
    });
    await resize.focus();
    await resize.press("ArrowRight");
    await records
        .getByRole("button", { name: "Options for Contact Name", exact: true })
        .click();
    dialog = page.getByRole("dialog");
    await dialog
        .getByRole("button", { name: "Pin to left", exact: true })
        .click();
    await dialog.getByRole("button", { name: "Close column options" }).click();
    await expect(
        records.getByRole("columnheader").filter({
            has: page.getByRole("button", {
                name: "Sort by Contact Name",
                exact: true,
            }),
        }),
    ).toHaveCSS("position", "sticky");
    await expect(page.locator(".md-table-save")).toHaveText("Layout saved");
    const name = `Review ${info.project.name} ${Date.now()}`;
    await page.getByRole("button", { name: "Save view", exact: true }).click();
    dialog = page.getByRole("dialog");
    await dialog.getByLabel("View name").fill(name);
    await dialog
        .getByRole("button", { name: "Save view", exact: true })
        .click();
    await expect(page.locator(".md-table-save")).toHaveText("Layout saved");
    await page.reload();
    records = page.getByRole("table", { name: "Leads records" });
    await expect(
        records.getByRole("columnheader").filter({ hasText: "Contact Email" }),
    ).toBeVisible();
    await expect(
        records.getByRole("columnheader").filter({ hasText: "Enquiry title" }),
    ).toBeVisible();
    await expect(
        records.getByRole("columnheader").filter({
            has: page.getByRole("button", {
                name: "Sort by Contact Name",
                exact: true,
            }),
        }),
    ).toHaveCSS("width", "220px");
    await page.getByLabel("Saved table views").selectOption({ label: name });
    await page
        .getByRole("button", { name: "Delete view", exact: true })
        .click();
    await expect(page.locator(".md-table-save")).toHaveText("Layout saved");
    await page.evaluate(() => scrollTo(0, 0));
    await page.screenshot({
        path: `docs/screenshots/${info.project.name}-advanced-leads-table.png`,
        fullPage: true,
    });
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBeTruthy();
    expect(errors).toEqual([]);
});
test("table search, multi-sort, advanced filters and module fields", async ({
    page,
}, info) => {
    await login(page, "alpha");
    const records = page.getByRole("table", { name: "Leads records" });
    await page
        .getByRole("textbox", { name: "Search leads" })
        .fill("Rimon Studio");
    await expect(records.locator("tbody tr")).toHaveCount(1);
    await expect(
        records.getByRole("link", { name: "Noa Levi", exact: true }),
    ).toBeVisible();
    await page
        .getByRole("button", { name: "Clear search", exact: true })
        .click();
    await page.getByRole("button", { name: "Filters", exact: false }).click();
    let dialog = page.getByRole("dialog");
    await dialog.getByRole("button", { name: "Add condition" }).click();
    await dialog.getByLabel("Filter 1 field").selectOption("source");
    await dialog.getByLabel("Filter 1 operator").selectOption("eq");
    await dialog.getByLabel("Filter 1 value").fill("Referral");
    await dialog
        .getByRole("button", { name: "Apply filters", exact: true })
        .click();
    await expect(records.locator("tbody tr")).toHaveCount(3);
    await records
        .getByRole("checkbox", { name: "Select all rows on this page" })
        .check();
    await expect(
        page.getByText("3 selected on this page", { exact: true }),
    ).toBeVisible();
    await page
        .context()
        .grantPermissions(["clipboard-read", "clipboard-write"]);
    await page.getByRole("button", { name: "Copy rows", exact: true }).click();
    await expect(
        page.getByText("Copied 3 rows", { exact: true }),
    ).toBeVisible();
    expect(await page.evaluate(() => navigator.clipboard.readText())).toContain(
        "Rimon Studio",
    );
    await page
        .getByRole("button", { name: "Clear selection", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Sort", exact: true })
        .or(page.getByRole("button", { name: /^sort\s+\d+$/i }))
        .first()
        .click();
    dialog = page.getByRole("dialog");
    await dialog.getByLabel("Sort 1 field").selectOption("priority");
    await dialog.getByLabel("Sort 1 direction").selectOption("desc");
    await dialog.getByRole("button", { name: "Add sort", exact: true }).click();
    await dialog.getByLabel("Sort 2 field").selectOption("title");
    await dialog.getByLabel("Sort 2 direction").selectOption("asc");
    await dialog
        .getByRole("button", { name: "Apply sorting", exact: true })
        .click();
    await expect(page.locator(".md-table-sort-summary")).toContainText(
        "Priority ↓",
    );
    await page
        .getByRole("button", { name: "Clear filters", exact: true })
        .click();
    await page.getByRole("button", { name: "Columns", exact: false }).click();
    dialog = page.getByRole("dialog");
    await dialog.getByLabel("Find a column").fill("password");
    await expect(
        dialog.getByText("No fields match your search."),
    ).toBeVisible();
    await dialog.getByRole("button", { name: "Close columns" }).click();
    await openNavigation(page);
    const sidebar = page.locator(".crm-sidebar");
    await sidebar
        .getByRole("link", { name: "Sign out", exact: true })
        .or(sidebar.getByRole("button", { name: "Sign out", exact: true }))
        .click();
    await login(page, "beta");
    await page.getByRole("button", { name: "Columns", exact: false }).click();
    dialog = page.getByRole("dialog");
    await dialog.getByRole("button", { name: "Reset layout" }).click();
    await dialog.getByLabel("Find a column").fill("service");
    await dialog
        .getByRole("checkbox", { name: "Show Service reference column" })
        .check();
    await dialog
        .getByRole("checkbox", { name: "Show Service type column" })
        .check();
    await expect(dialog.getByRole("status")).toHaveText("Layout saved");
    await page.screenshot({
        path: `docs/screenshots/${info.project.name}-table-field-chooser.png`,
        fullPage: false,
    });
    await dialog.getByRole("button", { name: "Done" }).click();
    await page
        .getByRole("textbox", { name: "Search enquiries" })
        .fill("DEMO-REF");
    await expect(
        page
            .getByRole("table", { name: "Enquiries records" })
            .getByText("DEMO-REF", { exact: true }),
    ).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBeTruthy();
});
