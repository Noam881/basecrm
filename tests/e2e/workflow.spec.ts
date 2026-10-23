import { test, expect, type Page } from "@playwright/test";
import { readFileSync } from "node:fs";
import { execFileSync } from "node:child_process";
const fixture = JSON.parse(readFileSync(".local-fixtures.json", "utf8"));
async function navigate(page: Page, name: string) {
    const open = page.getByRole("button", {
        name: "Open navigation",
        exact: true,
    });
    if (await open.isVisible()) await open.click();
    await page
        .getByRole("navigation", { name: "Main navigation", exact: true })
        .getByRole("link", { name, exact: true })
        .click();
}
test("persisted workflow, reminders, dashboard and responsive layout", async ({
    page,
}, info) => {
    const errors: string[] = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto("/login");
    await expect(
        page.locator(".crm-auth-form").getByText("Alpha CRM", { exact: true }),
    ).toBeVisible();
    await page.getByLabel("Email address").fill(fixture.alpha.email);
    await page
        .getByLabel("Password", { exact: true })
        .fill(fixture.alpha.password);
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
    const suffix = `${info.project.name}-${Date.now()}`;
    await navigate(page, "Contacts & companies");
    await page.getByLabel("Record type").selectOption("companies");
    await page
        .getByLabel("Name", { exact: true })
        .fill(`Oren Solutions — ${suffix}`);
    await page
        .getByLabel("Email", { exact: true })
        .fill(`office.${suffix}@demo.example.test`);
    await page.getByLabel("Phone", { exact: true }).fill("+1 202-555-0188");
    await page
        .getByRole("checkbox", {
            name: "Keep a separate record if a duplicate is found",
        })
        .check();
    await page.getByRole("button", { name: "Create customer" }).click();
    await expect(
        page.getByText(`Oren Solutions — ${suffix}`, { exact: true }),
    ).toBeVisible();
    await page.getByLabel("Record type").selectOption("contacts");
    await page
        .getByLabel("Name", { exact: true })
        .fill(`Uri Ben David — ${suffix}`);
    await page
        .getByLabel("Email", { exact: true })
        .fill(`uri.${suffix}@demo.example.test`);
    await page.getByLabel("Phone", { exact: true }).fill("+1 202-555-0189");
    await page
        .getByRole("checkbox", {
            name: "Keep a separate record if a duplicate is found",
        })
        .check();
    await page
        .getByRole("combobox", { name: "Company", exact: true })
        .selectOption({ label: `Oren Solutions — ${suffix}` });
    await page.getByRole("button", { name: "Create customer" }).click();
    await expect(
        page.getByText(`Uri Ben David — ${suffix}`, { exact: true }),
    ).toBeVisible();
    await navigate(page, "Leads");
    await page
        .getByRole("button", { name: "+ Create lead", exact: true })
        .click();
    await page
        .getByLabel("Enquiry title (optional)", { exact: true })
        .fill(`Enquiry ${suffix}`);
    await page
        .getByRole("combobox", { name: "Contact", exact: true })
        .selectOption({ label: `Uri Ben David — ${suffix}` });
    await page
        .getByRole("combobox", { name: "Company", exact: true })
        .selectOption({ label: `Oren Solutions — ${suffix}` });
    await page
        .getByRole("button", { name: "Create lead", exact: true })
        .click();
    await expect(
        page.getByRole("heading", {
            name: `Uri Ben David — ${suffix}`,
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page.getByLabel("Enquiry title (optional)", { exact: true }),
    ).toHaveValue(`Enquiry ${suffix}`);
    await page
        .getByRole("combobox", { name: "Status", exact: true })
        .selectOption("contacted");
    await page.getByRole("button", { name: "Save changes" }).click();
    await expect(
        page.getByText("lead status changed", { exact: true }),
    ).toBeVisible();
    await page
        .getByLabel("Add a note")
        .fill("Confirmed the next step in a fictional conversation.");
    await page.getByRole("button", { name: "Add note", exact: true }).click();
    await expect(
        page.getByText("Confirmed the next step in a fictional conversation.", {
            exact: true,
        }),
    ).toBeVisible();
    await page.getByLabel("Follow-up title").fill(`Call ${suffix}`);
    function local(d: Date) {
        return new Date(d.getTime() - d.getTimezoneOffset() * 60000)
            .toISOString()
            .slice(0, 16);
    }
    await page
        .getByLabel("Due date & time")
        .fill(local(new Date(Date.now() + 3600000)));
    await page
        .getByLabel("Reminder time")
        .fill(local(new Date(Date.now() - 120000)));
    await page
        .getByRole("button", { name: "Schedule follow-up", exact: true })
        .click();
    await expect(
        page.getByText(`Call ${suffix}`, { exact: true }),
    ).toBeVisible();
    execFileSync("php", ["artisan", "crm:remind"]);
    execFileSync("php", [
        "artisan",
        "queue:work",
        "--stop-when-empty",
        "--tries=3",
    ]);
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({
        path: `docs/screenshots/${info.project.name}-lead.png`,
        fullPage: true,
    });
    await navigate(page, "Reminders");
    await expect(
        page.getByText(`Call ${suffix}`, { exact: true }),
    ).toBeVisible();
    const reminder = page
        .locator("article")
        .filter({ hasText: `Call ${suffix}` });
    await reminder.getByRole("button", { name: "Mark read" }).click();
    await expect(reminder.getByText("Read", { exact: true })).toBeVisible();
    await reminder.getByRole("link").click();
    await page.getByRole("button", { name: "Complete", exact: true }).click();
    await expect(
        page.getByText("task completed", { exact: true }),
    ).toBeVisible();
    await navigate(page, "Overview");
    await expect(
        page.getByRole("heading", { name: "Overview", exact: true }),
    ).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBeTruthy();
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({
        path: `docs/screenshots/${info.project.name}-dashboard.png`,
        fullPage: true,
    });
    expect(errors).toEqual([]);
});
test("Beta branding and extension use the shared lead profile", async ({
    page,
}, info) => {
    await page.goto("http://127.0.0.1:8092/login");
    await expect(
        page.locator(".crm-auth-form").getByText("Beta CRM", { exact: true }),
    ).toBeVisible();
    await page.getByLabel("Email address").fill(fixture.beta.email);
    await page
        .getByLabel("Password", { exact: true })
        .fill(fixture.beta.password);
    await page.getByRole("button", { name: "Log in", exact: true }).click();
    await expect(page).toHaveURL(/8092\/dashboard/);
    await navigate(page, "Enquiries");
    await page
        .getByRole("table", { name: "Enquiries records", exact: true })
        .getByRole("link", { name: "Noa Levi", exact: true })
        .click();
    await expect(
        page.getByRole("heading", { name: "Service details", exact: true }),
    ).toBeVisible();
    await expect(page.getByText("Loading service details…")).toHaveCount(0);
    await page.getByLabel("Service reference").fill("DEMO-REF");
    await page.getByLabel("Requested service").fill("Fictional maintenance");
    await page.getByRole("button", { name: "Save service details" }).click();
    await expect(page.getByText("Service details saved.")).toBeVisible();
    await page.reload();
    await expect(page.getByLabel("Service reference")).toHaveValue("DEMO-REF");
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({
        path: `docs/screenshots/${info.project.name}-beta-extension.png`,
        fullPage: true,
    });
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBeTruthy();
});
