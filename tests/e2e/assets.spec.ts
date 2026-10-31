import { test, expect, type Page, type Locator } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { chmodSync, readFileSync, rmSync } from "node:fs";
import { signInLocalFixture } from "./local-reference-login";
const origin = "http://127.0.0.1:8103";
const proofDate = new Date().toISOString().slice(0, 10).replaceAll("-", "");
test.use({ actionTimeout: 7000 });
test.setTimeout(90000);
let fixture: {
    email: string;
    owner_id: number;
    employee_id: number;
    contact_id: number;
    company_id: number;
    today: string;
    yesterday: string;
    next: string;
    manifest: string;
};
function prepare(mode: "setup" | "cleanup", project: string) {
    return JSON.parse(
        execFileSync(
            "php",
            ["scripts/assets-browser-fixtures.php", mode, project],
            { encoding: "utf8" },
        ),
    );
}
async function open(page: Page, label: string): Promise<Locator> {
    const summary = page
        .locator("summary")
        .filter({ hasText: new RegExp(`^${label}$`) });
    const details = page.locator("details").filter({ has: summary });
    if (!(await details.evaluate((el) => (el as HTMLDetailsElement).open)))
        await summary.click();
    return details;
}
async function recordReturn(page: Page, condition: string) {
    const form = await open(page, "Record asset return");
    await form
        .getByLabel("Actual return date", { exact: true })
        .fill(fixture.today);
    await form
        .getByLabel("Condition on return", { exact: true })
        .fill(condition);
    await form
        .getByRole("button", { name: "Record return", exact: true })
        .click();
    await expect(page.getByText(condition, { exact: true })).toBeVisible();
    await page.reload();
}
async function evidence(page: Page, project: string, label: string) {
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    const path = `/private/tmp/basecrm-${project}-assets-${label}-${proofDate}.png`;
    const screenshot = await page.screenshot({
        path,
        fullPage: true,
    });
    chmodSync(path, 0o600);
    await test.info().attach(`Assets ${label}`, {
        body: screenshot,
        contentType: "image/png",
    });
}
test.beforeEach(async ({}, info) => {
    if (
        info.config.workers !== 1 ||
        !["desktop", "mobile"].includes(info.project.name)
    )
        throw new Error(
            "Assets fixtures require one worker and known projects.",
        );
    prepare("cleanup", info.project.name);
    fixture = prepare("setup", info.project.name);
});
test.afterEach(async ({}, info) => {
    prepare("cleanup", info.project.name);
});
test("Assets register assignments returns maintenance exact costs documents and filtered CSV work without HR Operations Finance or Sales", async ({
    page,
}, info) => {
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await signInLocalFixture(page, fixture.email, origin);
    for (const [path, status] of [
        ["/leads", 404],
        ["/hr", 404],
        ["/operations", 403],
    ] as const)
        expect(
            (await page.request.get(`${origin}${path}`)).status(),
            `Disabled module route ${path}`,
        ).toBe(status);
    await page.goto(`${origin}/assets?section=register`);
    const intake = await open(page, "Add asset");
    await intake.getByLabel("Asset code", { exact: true }).fill("EQ-101");
    await intake
        .getByLabel("Asset name", { exact: true })
        .fill("Office projector");
    await intake.getByLabel("Asset type", { exact: true }).fill("Equipment");
    await intake
        .getByLabel("Location", { exact: true })
        .fill("Tel Aviv office");
    await intake
        .getByLabel("Purchase date", { exact: true })
        .fill("2026-02-01");
    await intake
        .getByLabel("Serial number", { exact: true })
        .fill("PROJECTOR-101");
    await intake
        .getByLabel("Notes", { exact: true })
        .fill("Fictional meeting equipment.");
    await intake
        .getByRole("button", { name: "Create asset", exact: true })
        .click();
    await expect(page).toHaveURL(/\/assets\/register\/\d+$/);
    const url = page.url();
    await page.reload();
    await expect(page.getByLabel("Asset code", { exact: true })).toHaveValue(
        "EQ-101",
    );
    await page
        .getByLabel("Asset name", { exact: true })
        .fill("Meeting projector");
    await page.getByRole("button", { name: "Save asset", exact: true }).click();
    await expect(
        page.getByRole("heading", {
            name: "Meeting projector",
            exact: true,
            level: 1,
        }),
    ).toBeVisible();
    await page.reload();
    await expect(page.getByLabel("Serial number", { exact: true })).toHaveValue(
        "PROJECTOR-101",
    );

    let classify = await open(page, "Classify an existing contact or company");
    await classify
        .getByRole("combobox", { name: "Contact", exact: true })
        .selectOption(String(fixture.contact_id));
    await classify
        .getByRole("combobox", { name: "Classification", exact: true })
        .selectOption("customer");
    await classify
        .getByRole("button", {
            name: "Add customer classification",
            exact: true,
        })
        .click();
    await expect(
        classify
            .getByRole("combobox", { name: "Contact", exact: true })
            .locator("option:checked"),
    ).toHaveText("Choose a contact");
    await page.reload();
    classify = await open(page, "Classify an existing contact or company");
    await classify
        .getByRole("combobox", { name: "Identity type", exact: true })
        .selectOption("company");
    await classify
        .getByRole("combobox", { name: "Company", exact: true })
        .selectOption(String(fixture.company_id));
    await classify
        .getByRole("combobox", { name: "Classification", exact: true })
        .selectOption("supplier");
    await classify
        .getByRole("button", {
            name: "Add supplier classification",
            exact: true,
        })
        .click();
    await expect(
        classify
            .getByRole("combobox", { name: "Company", exact: true })
            .locator("option:checked"),
    ).toHaveText("Choose a company");
    await page.reload();

    let assignment = await open(page, "Assign this asset");
    await assignment
        .getByLabel("Employee", { exact: true })
        .selectOption(String(fixture.employee_id));
    await assignment
        .getByLabel("Assignment date", { exact: true })
        .fill(fixture.yesterday);
    await assignment
        .getByLabel("Expected return date", { exact: true })
        .fill(fixture.yesterday);
    await assignment
        .getByRole("button", { name: "Assign asset", exact: true })
        .click();
    await expect(
        page.getByRole("heading", { name: "Yael Shalev", exact: true }),
    ).toBeVisible();
    await page.reload();
    await expect(
        page.getByText("Open assignment", { exact: true }),
    ).toBeVisible();
    const plan = await open(page, "Plan maintenance action");
    await plan
        .getByLabel("Maintenance action", { exact: true })
        .fill("Check projector lamp");
    await plan.getByLabel("Due date", { exact: true }).fill(fixture.today);
    await plan
        .getByRole("combobox", { name: "Maintenance status", exact: true })
        .selectOption("in-progress");
    await plan
        .getByRole("combobox", { name: "Supplier", exact: true })
        .selectOption({ label: "Carmel Supply" });
    await plan
        .getByLabel("Maintenance notes", { exact: true })
        .fill("Inspect the fictional equipment.");
    await plan
        .getByRole("button", { name: "Plan maintenance", exact: true })
        .click();
    await expect(page).toHaveURL(/\/assets\/maintenance\/\d+$/);
    const maintenanceUrl = page.url();
    await page.goto(url);
    await expect(
        page.getByRole("link", { name: "Check projector lamp", exact: true }),
    ).toBeVisible();
    await page.reload();
    await expect(
        page.getByRole("combobox", { name: "Status", exact: true }),
    ).toHaveValue("under-maintenance");
    const env = { ...process.env, CRM_MANIFEST: fixture.manifest };
    execFileSync("php", ["artisan", "--env=alpha", "crm:remind"], {
        env,
        stdio: "ignore",
        timeout: 15000,
    });
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
        { env, stdio: "ignore", timeout: 15000 },
    );
    await page.goto(`${origin}/reminders`);
    await expect(
        page.getByRole("link", { name: /Check projector lamp/ }),
    ).toBeVisible();
    await page.goto(url);
    const schedule = await open(page, "Edit expected return");
    await schedule
        .getByLabel("Expected return date", { exact: true })
        .fill(fixture.next);
    await schedule
        .getByLabel("Assignment notes", { exact: true })
        .fill("Return date explicitly rescheduled.");
    await schedule
        .getByRole("button", { name: "Save assignment details", exact: true })
        .click();
    await expect(
        page.getByText("Return date explicitly rescheduled.", { exact: true }),
    ).toBeVisible();
    await page.reload();
    const savedSchedule = await open(page, "Edit expected return");
    await expect(
        savedSchedule.getByLabel("Expected return date", { exact: true }),
    ).toHaveValue(fixture.next);
    await recordReturn(page, "Good condition after review");
    await expect(
        page.getByRole("combobox", { name: "Status", exact: true }),
    ).toHaveValue("under-maintenance");
    await page
        .getByRole("link", { name: "Check projector lamp", exact: true })
        .click();
    await expect(page).toHaveURL(/\/assets\/maintenance\/\d+$/);
    const completion = page.locator("form").filter({
        has: page.getByRole("button", {
            name: "Complete maintenance",
            exact: true,
        }),
    });
    await completion
        .getByLabel("Completion date", { exact: true })
        .fill(fixture.today);
    await completion
        .getByRole("combobox", {
            name: "Asset state after completion",
            exact: true,
        })
        .selectOption("available");
    await completion.getByLabel(/^Recorded cost/).fill("175.35");
    await completion
        .getByLabel("Manual next due date", { exact: true })
        .fill(fixture.next);
    await completion
        .getByRole("button", { name: "Complete maintenance", exact: true })
        .click();
    await expect(
        page.getByRole("heading", { name: "Completion records", exact: true }),
    ).toBeVisible();
    await page.reload();
    const originalCompletion = page.locator("section").filter({
        has: page.getByRole("heading", {
            name: "Completion records",
            exact: true,
        }),
    });
    await expect(originalCompletion.getByText(/^175\.35 /)).toBeVisible();
    const costs = page.locator("section").filter({
        has: page.getByRole("heading", {
            name: "Maintenance cost",
            exact: true,
        }),
    });
    await costs.getByLabel(/^Recorded cost/).fill("185.35");
    await costs
        .getByRole("button", { name: "Correct recorded cost", exact: true })
        .click();
    await expect(
        costs.locator("dd").filter({ hasText: /^185\.35 / }),
    ).toBeVisible();
    await page.reload();
    await expect(originalCompletion.getByText(/^175\.35 /)).toBeVisible();
    await expect(
        costs.locator("dd").filter({ hasText: /^185\.35 / }),
    ).toBeVisible();
    await expect(
        page.getByLabel("Manual next due date", { exact: true }),
    ).toHaveValue(fixture.next);
    await evidence(page, info.project.name, "maintenance");
    await page.goto(url);
    assignment = await open(page, "Assign this asset");
    await assignment
        .getByRole("combobox", { name: "Assignment target", exact: true })
        .selectOption("customer");
    await assignment
        .getByRole("combobox", { name: "Customer", exact: true })
        .selectOption({ label: "Noam Levi" });
    await assignment
        .getByRole("button", { name: "Assign asset", exact: true })
        .click();
    await expect(
        page.getByRole("heading", { name: "Noam Levi", exact: true }),
    ).toBeVisible();
    await recordReturn(page, "Returned intact by Noam");
    assignment = await open(page, "Assign this asset");
    await assignment
        .getByRole("combobox", { name: "Assignment target", exact: true })
        .selectOption("location");
    await assignment
        .getByLabel("Assignment location", { exact: true })
        .fill("Jerusalem office");
    await assignment
        .getByRole("button", { name: "Assign asset", exact: true })
        .click();
    await expect(
        page.getByRole("heading", { name: "Jerusalem office", exact: true }),
    ).toBeVisible();
    await recordReturn(page, "Location equipment checked");
    const documents = await open(page, "Add document link");
    await documents
        .getByLabel("Document name", { exact: true })
        .fill("Equipment guide");
    await documents
        .getByLabel("Document URL", { exact: true })
        .fill("https://documents.example.test/equipment-guide");
    await documents
        .getByRole("button", { name: "Add document", exact: true })
        .click();
    const documentLink = page.getByRole("link", {
        name: "Equipment guide",
        exact: true,
    });
    await expect(documentLink).toBeVisible();
    const href = await documentLink.getAttribute("href");
    if (!href?.startsWith("/assets/documents/"))
        throw new Error("Expected a scoped local document reference.");
    const response = await page.request.get(`${origin}${href}`, {
        maxRedirects: 0,
    });
    expect(response.status()).toBe(302);
    expect(response.headers().location).toBe(
        "https://documents.example.test/equipment-guide",
    );
    await page.reload();
    await expect(page.getByText("Returned", { exact: true })).toHaveCount(3);
    await evidence(page, info.project.name, "register");
    await page.goto(`${origin}/assets?section=maintenance&q=Check%20projector`);
    const [download] = await Promise.all([
        page.waitForEvent("download"),
        page.getByRole("link", { name: "Export CSV", exact: true }).click(),
    ]);
    const file = await download.path();
    if (!file)
        throw new Error("Expected an actual filtered Assets CSV download.");
    const csv = readFileSync(file, "utf8");
    expect(csv).toContain("Check projector lamp");
    expect(csv).toContain("185.35");
    expect(csv).toContain("Carmel Supply");
    rmSync(file);
    await page.goto(maintenanceUrl);
    await expect(
        page.getByRole("heading", { name: "Completion records", exact: true }),
    ).toBeVisible();
    expect(errors).toEqual([]);
});
