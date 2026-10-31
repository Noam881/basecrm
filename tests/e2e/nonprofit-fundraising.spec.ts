import { test, expect, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { chmodSync, readFileSync, rmSync } from "node:fs";
import { signInLocalFixture } from "./local-reference-login";
const origin = "http://127.0.0.1:8105";
test.use({ actionTimeout: 7000 });
test.setTimeout(90000);
let fixture: {
    email: string;
    owner_id: number;
    contact_id: number;
    payment_id: number;
    today: string;
    yesterday: string;
    next: string;
};
function prepare(mode: "setup" | "cleanup", project: string) {
    return JSON.parse(
        execFileSync(
            "php",
            [
                "scripts/nonprofit-fundraising-browser-fixtures.php",
                mode,
                project,
            ],
            { encoding: "utf8" },
        ),
    );
}
async function open(page: Page, label: string) {
    const summary = page
        .locator("summary")
        .filter({ hasText: new RegExp(`^${label}$`) });
    const details = page.locator("details").filter({ has: summary });
    if (!(await details.evaluate((el) => (el as HTMLDetailsElement).open)))
        await summary.click();
    return details;
}
async function proof(page: Page, project: string, label: string) {
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    const path = `/private/tmp/basecrm-nonprofit-fundraising-${project}-${label}.png`;
    const image = await page.screenshot({ path, fullPage: true });
    chmodSync(path, 0o600);
    await test.info().attach(label, { body: image, contentType: "image/png" });
}
test.beforeEach(async ({}, info) => {
    if (
        info.config.workers !== 1 ||
        !["desktop", "mobile"].includes(info.project.name)
    )
        throw Error("Reserved single-worker fixture only.");
    prepare("cleanup", info.project.name);
    fixture = prepare("setup", info.project.name);
});
test.afterEach(async ({}, info) => {
    prepare("cleanup", info.project.name);
});
test("Nonprofit Fundraising persists shared donors pledges actual receipt links manual followups and filtered CSV", async ({
    page,
}, info) => {
    const errors: string[] = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await signInLocalFixture(page, fixture.email, origin);
    await page.goto(`${origin}/nonprofit/fundraising`);
    await expect(
        page.getByRole("heading", {
            name: "Fundraising",
            level: 1,
            exact: true,
        }),
    ).toBeVisible();
    const classify = await open(
        page,
        "Classify an existing contact or company",
    );
    await classify
        .getByRole("combobox", { name: "Contact", exact: true })
        .selectOption(String(fixture.contact_id));
    await classify
        .getByRole("combobox", { name: "Classification", exact: true })
        .selectOption("donor");
    await classify
        .getByRole("button", { name: "Add donor classification", exact: true })
        .click();
    const intake = await open(page, "Add fundraising context");
    const relation = intake.getByLabel("Shared donor, partner or prospect", {
        exact: true,
    });
    await expect(
        relation.locator("option").filter({ hasText: "Yael Shalev · donor" }),
    ).toHaveCount(1);
    await relation.selectOption({ label: "Yael Shalev · donor" });
    await expect(
        intake.getByLabel("Responsible person", { exact: true }),
    ).toHaveValue(String(fixture.owner_id));
    await intake
        .getByLabel("Next action", { exact: true })
        .fill("Call Yael about learning materials");
    await intake
        .getByLabel("Next action date", { exact: true })
        .fill(fixture.yesterday);
    await intake
        .getByLabel("Fundraising notes", { exact: true })
        .fill("Fictional supporter interested in education.");
    await intake
        .getByRole("button", { name: "Add fundraising context", exact: true })
        .click();
    await expect(page).toHaveURL(
        /\/nonprofit\/fundraising\/constituents\/\d+$/,
    );
    const contextUrl = page.url();
    await page.reload();
    await expect(page.getByLabel("Next action", { exact: true })).toHaveValue(
        "Call Yael about learning materials",
    );
    const pledge = await open(page, "Add pledge");
    await pledge
        .getByLabel("Pledge reference (optional)", { exact: true })
        .fill("Autumn education pledge");
    await pledge.getByLabel("Pledged amount", { exact: true }).fill("100.50");
    await pledge
        .getByLabel("Expected donation date", { exact: true })
        .fill(fixture.next);
    await pledge
        .getByLabel("Donation restrictions", { exact: true })
        .fill("Books and learning materials only.");
    await pledge
        .getByRole("button", { name: "Add pledge", exact: true })
        .click();
    await expect(page).toHaveURL(/\/nonprofit\/fundraising\/pledges\/\d+$/);
    await page.reload();
    await expect(
        page.getByLabel("Pledged amount", { exact: true }),
    ).toHaveValue("100.50");
    await page
        .getByLabel("Existing Finance receipt", { exact: true })
        .selectOption(String(fixture.payment_id));
    await page
        .getByRole("button", { name: "Link existing receipt", exact: true })
        .click();
    await expect(
        page.getByText("25.25 USD linked actual receipts.", { exact: false }),
    ).toBeVisible();
    await page.reload();
    await expect(
        page.getByRole("link", { name: /Existing Finance contribution/ }),
    ).toBeVisible();
    await page.getByLabel("Pledged amount", { exact: true }).fill("125.75");
    await page
        .getByRole("button", { name: "Save pledge", exact: true })
        .click();
    await page.reload();
    await expect(
        page.getByLabel("Pledged amount", { exact: true }),
    ).toHaveValue("125.75");
    await proof(page, info.project.name, "pledge");
    await page.goto(contextUrl);
    await page
        .getByLabel("Next action date", { exact: true })
        .fill(fixture.next);
    await page
        .getByRole("button", { name: "Save fundraising details", exact: true })
        .click();
    await page.reload();
    await expect(
        page.getByLabel("Next action date", { exact: true }),
    ).toHaveValue(fixture.next);
    await proof(page, info.project.name, "context");
    await page.goto(`${origin}/nonprofit/fundraising?tab=pledges&q=Autumn`);
    await expect(
        page.getByRole("link", {
            name: "Autumn education pledge",
            exact: true,
        }),
    ).toBeVisible();
    const [download] = await Promise.all([
        page.waitForEvent("download"),
        page.getByRole("link", { name: "Export CSV", exact: true }).click(),
    ]);
    const path = await download.path();
    expect(path).toBeTruthy();
    if (path) {
        const csv = readFileSync(path, "utf8");
        expect(csv).toContain("125.75");
        expect(csv).toContain("Books and learning materials only.");
        expect(csv).not.toContain("payment_id");
        rmSync(path, { force: true });
    }
    await proof(page, info.project.name, "overview");
    expect(errors).toEqual([]);
});
