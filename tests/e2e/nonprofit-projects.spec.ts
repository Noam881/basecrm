import { test, expect, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { chmodSync, readFileSync, rmSync } from "node:fs";
import { signInLocalFixture } from "./local-reference-login";
const origin = "http://127.0.0.1:8106";
const stamp = new Date().toISOString().slice(0, 10).replaceAll("-", "");
test.use({ actionTimeout: 7000 });
test.setTimeout(120000);
let fixture: {
    email: string;
    owner_id: number;
    today: string;
    yesterday: string;
    next: string;
    manifest: string;
    currency: string;
};
function prepare(
    mode: "setup" | "cleanup" | "connect" | "disconnect",
    project: string,
) {
    return JSON.parse(
        execFileSync(
            "php",
            ["scripts/nonprofit-projects-browser-fixtures.php", mode, project],
            { encoding: "utf8" },
        ),
    );
}
async function open(page: Page, label: string) {
    const summary = page
        .locator("summary")
        .filter({ hasText: new RegExp(`^${label}$`) });
    const details = page.locator("details").filter({ has: summary });
    if (!(await details.evaluate((node) => (node as HTMLDetailsElement).open)))
        await summary.click();
    return details;
}
async function evidence(page: Page, project: string, label: string) {
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    const path = `/private/tmp/basecrm-${project}-nonprofit-projects-${label}-${stamp}.png`;
    const screenshot = await page.screenshot({ path, fullPage: true });
    chmodSync(path, 0o600);
    await test.info().attach(`Projects ${label}`, {
        body: screenshot,
        contentType: "image/png",
    });
}
async function downloadCsv(page: Page) {
    const [download] = await Promise.all([
        page.waitForEvent("download"),
        page.getByRole("link", { name: "Export CSV", exact: true }).click(),
    ]);
    const path = await download.path();
    if (!path) throw new Error("Expected an actual CSV download.");
    const csv = readFileSync(path, "utf8");
    rmSync(path);
    return csv;
}
test.beforeEach(async ({}, info) => {
    if (
        info.config.workers !== 1 ||
        !["desktop", "mobile"].includes(info.project.name)
    )
        throw new Error(
            "Projects fixtures require one worker and known projects.",
        );
    prepare("cleanup", info.project.name);
    fixture = prepare("setup", info.project.name);
});
test.afterEach(async ({}, info) => prepare("cleanup", info.project.name));
test("Projects standalone plans shared work meetings budgets and optional scoped actual payments resources persist", async ({
    page,
}, info) => {
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await signInLocalFixture(page, fixture.email, origin);
    expect(
        (await page.request.get(`${origin}/nonprofit/programs`)).status(),
    ).toBe(404);
    expect(
        (await page.request.get(`${origin}/nonprofit/fundraising`)).status(),
    ).toBe(404);
    await page.goto(`${origin}/nonprofit/projects`);
    const intake = await open(page, "Add project");
    await intake
        .getByLabel("Project name", { exact: true })
        .fill("Community learning plan");
    await intake
        .getByLabel("Objective", { exact: true })
        .fill("Offer a fictional neighborhood learning program.");
    await intake.getByLabel("Start date", { exact: true }).fill(fixture.today);
    await intake.getByLabel("End date", { exact: true }).fill(fixture.next);
    await intake.getByLabel(/^Planned budget/).fill("200.00");
    await intake
        .getByRole("button", { name: "Create project", exact: true })
        .click();
    await expect(page).toHaveURL(/\/nonprofit\/projects\/\d+$/);
    const projectUrl = page.url();
    await page.reload();
    await expect(page.getByLabel("Objective", { exact: true })).toHaveValue(
        "Offer a fictional neighborhood learning program.",
    );
    const finance = page.locator("section").filter({
        has: page.getByRole("heading", {
            name: "Budget and actual payments",
            exact: true,
        }),
    });
    await expect(finance.getByText("Unavailable", { exact: true })).toHaveCount(
        2,
    );
    await page
        .getByLabel("Project name", { exact: true })
        .fill("Updated community plan");
    await page.getByLabel(/^Planned budget/).fill("300.00");
    await page
        .getByRole("button", { name: "Save project", exact: true })
        .click();
    await expect(
        page.getByRole("heading", {
            name: "Updated community plan",
            exact: true,
            level: 1,
        }),
    ).toBeVisible();
    await page.reload();
    const task = await open(page, "Add project task");
    await task
        .getByLabel("Task title", { exact: true })
        .fill("Prepare learning materials");
    await task.getByLabel("Due date", { exact: true }).fill(fixture.yesterday);
    await task
        .getByRole("button", { name: "Create task", exact: true })
        .click();
    await expect(page).toHaveURL(/\/nonprofit\/project-tasks\/\d+$/);
    const taskUrl = page.url();
    await page.reload();
    await page
        .getByLabel("Task title", { exact: true })
        .fill("Updated learning task");
    await page.getByRole("button", { name: "Save work", exact: true }).click();
    await expect(
        page.getByRole("heading", {
            name: "Updated learning task",
            exact: true,
            level: 1,
        }),
    ).toBeVisible();
    await page.goto(projectUrl);
    const milestone = await open(page, "Add milestone");
    await milestone
        .getByLabel("Milestone title", { exact: true })
        .fill("Publish annual plan");
    await milestone.getByLabel("Due date", { exact: true }).fill(fixture.next);
    await milestone
        .getByRole("button", { name: "Create milestone", exact: true })
        .click();
    await expect(page).toHaveURL(/\/nonprofit\/project-milestones\/\d+$/);
    const milestoneUrl = page.url();
    await page.goto(projectUrl);
    const meeting = await open(page, "Record a meeting");
    await meeting
        .getByLabel("Meeting date", { exact: true })
        .fill(fixture.today);
    await meeting
        .getByLabel("Meeting summary", { exact: true })
        .fill("Reviewed the fictional program.");
    await meeting
        .getByLabel("Decisions", { exact: true })
        .fill("Prepare materials before the next meeting.");
    await meeting
        .getByRole("checkbox", { name: "Create a follow-up task", exact: true })
        .check();
    await meeting
        .getByLabel("Follow-up title", { exact: true })
        .fill("Send learning outline");
    await meeting.getByLabel("Due date", { exact: true }).fill(fixture.next);
    await meeting
        .getByRole("button", { name: "Record meeting", exact: true })
        .click();
    await expect(
        page.getByText("Reviewed the fictional program.", { exact: true }),
    ).toBeVisible();
    await page.reload();
    const meetings = page.locator("section").filter({
        has: page.getByRole("heading", {
            name: "Meetings and decisions",
            exact: true,
        }),
    });
    await expect(
        meetings.getByRole("link", {
            name: "Send learning outline",
            exact: true,
        }),
    ).toBeVisible();
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
        page.getByRole("link", { name: /Updated learning task/ }),
    ).toBeVisible();
    await page.goto(taskUrl);
    await page
        .getByRole("combobox", { name: "Status", exact: true })
        .selectOption("done");
    await page.getByLabel(/^Completion date/).fill(fixture.today);
    await page.getByRole("button", { name: "Save work", exact: true }).click();
    await expect(page.locator(".crm-heading .crm-badge")).toHaveText(
        "Completed",
    );
    await page.reload();
    await expect(page.getByLabel(/^Completion date/)).toHaveValue(
        fixture.today,
    );
    await evidence(page, info.project.name, "work");
    await page.goto(`${origin}/reminders`);
    await expect(
        page.getByRole("link", { name: /Updated learning task/ }),
    ).toHaveCount(0);
    const sources = prepare("connect", info.project.name) as {
        asset_id: number;
        order_id: number;
        incoming_id: number;
        outgoing_id: number;
    };
    await page.goto(projectUrl);
    let resources = await open(page, "Link existing equipment or purchase");
    await resources
        .getByRole("combobox", { name: "Resource type", exact: true })
        .selectOption("asset");
    await resources
        .getByRole("combobox", { name: "Existing resource", exact: true })
        .selectOption(String(sources.asset_id));
    await resources
        .getByRole("button", { name: "Link resource", exact: true })
        .click();
    await expect(
        page.getByRole("link", { name: /Community projector/ }),
    ).toBeVisible();
    await page.reload();
    resources = await open(page, "Link existing equipment or purchase");
    await resources
        .getByRole("combobox", { name: "Resource type", exact: true })
        .selectOption("operations-order");
    await resources
        .getByRole("combobox", { name: "Existing resource", exact: true })
        .selectOption(String(sources.order_id));
    await resources
        .getByRole("button", { name: "Link resource", exact: true })
        .click();
    await expect(
        page.getByRole("link", { name: /PLAN-ORDER-101/ }),
    ).toBeVisible();
    await page.reload();
    let payment = await open(page, "Associate an existing Finance payment");
    await payment
        .getByRole("combobox", { name: "Payment direction", exact: true })
        .selectOption("incoming");
    await payment
        .getByRole("combobox", { name: "Recorded payment", exact: true })
        .selectOption(String(sources.incoming_id));
    await payment
        .getByRole("button", { name: "Associate payment", exact: true })
        .click();
    await expect(
        finance.getByText(`100.10 ${fixture.currency}`, { exact: true }),
    ).toBeVisible();
    await page.reload();
    payment = await open(page, "Associate an existing Finance payment");
    await payment
        .getByRole("combobox", { name: "Payment direction", exact: true })
        .selectOption("outgoing");
    await payment
        .getByRole("combobox", { name: "Recorded payment", exact: true })
        .selectOption(String(sources.outgoing_id));
    await payment
        .getByRole("button", { name: "Associate payment", exact: true })
        .click();
    await expect(
        finance.getByText(`40.02 ${fixture.currency}`, { exact: true }),
    ).toBeVisible();
    prepare("disconnect", info.project.name);
    await page.reload();
    await expect(finance.getByText("Unavailable", { exact: true })).toHaveCount(
        2,
    );
    await expect(
        page.getByRole("link", { name: /Community projector|PLAN-ORDER-101/ }),
    ).toHaveCount(0);
    prepare("connect", info.project.name);
    await page.reload();
    await expect(
        finance.getByText(`100.10 ${fixture.currency}`, { exact: true }),
    ).toBeVisible();
    await expect(
        finance.getByText(`40.02 ${fixture.currency}`, { exact: true }),
    ).toBeVisible();
    const docs = await open(page, "Add document link");
    await docs
        .getByLabel("Document name", { exact: true })
        .fill("Annual plan document");
    await docs
        .getByLabel("Document URL", { exact: true })
        .fill("https://documents.example.test/annual-plan");
    await docs
        .getByRole("button", { name: "Add document", exact: true })
        .click();
    const doc = page.getByRole("link", {
        name: "Annual plan document",
        exact: true,
    });
    await expect(doc).toBeVisible();
    const href = await doc.getAttribute("href");
    if (!href?.startsWith("/nonprofit/project-documents/"))
        throw new Error("Expected a scoped local project document reference.");
    const redirect = await page.request.get(`${origin}${href}`, {
        maxRedirects: 0,
    });
    expect(redirect.status()).toBe(302);
    expect(redirect.headers().location).toBe(
        "https://documents.example.test/annual-plan",
    );
    await page.reload();
    await evidence(page, info.project.name, "record");
    await page.goto(`${origin}/nonprofit/projects?q=Updated%20community`);
    await expect(
        page.getByRole("link", { name: "Updated community plan", exact: true }),
    ).toBeVisible();
    const csv = await downloadCsv(page);
    expect(csv).toContain("Updated community plan");
    expect(csv).toContain("300.00");
    await page.goto(
        `${origin}/nonprofit/projects?section=project-milestones&q=Publish`,
    );
    await expect(
        page.getByRole("link", { name: "Publish annual plan", exact: true }),
    ).toBeVisible();
    expect(await downloadCsv(page)).toContain("Publish annual plan");
    await page.goto(
        `${origin}/nonprofit/projects?section=project-tasks&q=Updated`,
    );
    await expect(
        page.getByRole("link", { name: "Updated learning task", exact: true }),
    ).toBeVisible();
    await page.goto(projectUrl);
    await page
        .getByRole("combobox", { name: "Project status", exact: true })
        .selectOption("completed");
    await page
        .getByRole("button", { name: "Save project", exact: true })
        .click();
    await expect(
        page.getByText(
            "Related work, meetings and links are retained read only. Reopen an unarchived project to resume work.",
            { exact: true },
        ),
    ).toBeVisible();
    await page.goto(milestoneUrl);
    await expect(
        page.getByRole("button", { name: "Save work", exact: true }),
    ).toHaveCount(0);
    await expect(
        page.getByText(
            "This work is read only. The project and work item must both be active and editable to make changes.",
            { exact: true },
        ),
    ).toBeVisible();
    expect(errors).toEqual([]);
});
