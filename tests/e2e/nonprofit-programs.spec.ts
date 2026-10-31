import { test, expect, type Page, type Locator } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { chmodSync, readFileSync, rmSync } from "node:fs";
import { signInLocalFixture } from "./local-reference-login";
const origin = "http://127.0.0.1:8104";
const proofDate = new Date().toISOString().slice(0, 10).replaceAll("-", "");
test.use({ actionTimeout: 8000 });
test.setTimeout(120000);
let f: {
    email: string;
    owner_id: number;
    contact_id: number;
    today: string;
    yesterday: string;
    next: string;
    manifest: string;
};
function fixture(mode: "setup" | "cleanup" | "remind", project: string) {
    return JSON.parse(
        execFileSync(
            "php",
            ["scripts/nonprofit-programs-browser-fixtures.php", mode, project],
            { encoding: "utf8" },
        ),
    );
}
async function open(page: Page, name: string): Promise<Locator> {
    const summary = page
        .locator("summary")
        .filter({ hasText: new RegExp(`^${name}$`) });
    const details = page.locator("details").filter({ has: summary });
    if (!(await details.evaluate((el) => (el as HTMLDetailsElement).open)))
        await summary.click();
    return details;
}
async function evidence(page: Page, project: string, name: string) {
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    const path = `/private/tmp/basecrm-${project}-programs-${name}-${proofDate}.png`;
    const body = await page.screenshot({ path, fullPage: true });
    chmodSync(path, 0o600);
    await test
        .info()
        .attach(`Programs ${name}`, { body, contentType: "image/png" });
}
test.beforeEach(async ({}, info) => {
    if (
        info.config.workers !== 1 ||
        !["desktop", "mobile"].includes(info.project.name)
    )
        throw new Error("Use one worker and a known synthetic project.");
    fixture("cleanup", info.project.name);
    f = fixture("setup", info.project.name);
});
test.afterEach(async ({}, info) => {
    fixture("cleanup", info.project.name);
});
test("Programs private roster sessions unique attendance summaries feedback and documents work independently", async ({
    page,
}, info) => {
    const errors: string[] = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await signInLocalFixture(page, f.email, origin);
    await page.goto(`${origin}/nonprofit/programs?section=locations`);
    let intake = await open(page, "Add location");
    await intake
        .getByLabel("Location name", { exact: true })
        .fill("Jerusalem center");
    await expect(
        intake.getByLabel("Responsible staff member", { exact: true }),
    ).toHaveValue(String(f.owner_id));
    await intake
        .getByRole("button", { name: "Create location", exact: true })
        .click();
    await expect(page).toHaveURL(/\/locations\/\d+$/);
    await page.reload();
    await expect(page.getByLabel("Location name", { exact: true })).toHaveValue(
        "Jerusalem center",
    );
    await page.goto(`${origin}/nonprofit/programs?section=groups`);
    intake = await open(page, "Add group");
    await intake
        .getByLabel("Group name", { exact: true })
        .fill("Learning group");
    await intake
        .getByLabel("Location", { exact: true })
        .selectOption({ label: "Jerusalem center" });
    await intake
        .getByRole("button", { name: "Create group", exact: true })
        .click();
    await expect(page).toHaveURL(/\/groups\/\d+$/);
    await page.goto(`${origin}/nonprofit/programs`);
    intake = await open(page, "Add program");
    await intake
        .getByLabel("Program name", { exact: true })
        .fill("Community workshops");
    await intake
        .getByLabel("Location", { exact: true })
        .selectOption({ label: "Jerusalem center" });
    await intake
        .getByLabel("Group", { exact: true })
        .selectOption({ label: "Learning group" });
    await intake.getByLabel("Start date", { exact: true }).fill(f.today);
    await intake.getByLabel("End date", { exact: true }).fill(f.next);
    await intake
        .getByLabel("Notes", { exact: true })
        .fill("Fictional community activity.");
    await intake
        .getByRole("button", { name: "Create program", exact: true })
        .click();
    await expect(page).toHaveURL(/\/programs\/\d+$/);
    const programUrl = page.url();
    await page
        .getByLabel("Program name", { exact: true })
        .fill("Community learning");
    await page
        .getByRole("button", { name: "Save program", exact: true })
        .click();
    await expect(
        page.getByRole("heading", { name: "Community learning", exact: true }),
    ).toBeVisible();
    await page.reload();
    await expect(page.getByLabel("Program name", { exact: true })).toHaveValue(
        "Community learning",
    );
    await page.goto(`${origin}/nonprofit/programs?section=participants`);
    intake = await open(page, "Add participant");
    await intake
        .getByLabel("Participant name", { exact: true })
        .fill("Yael Shalev");
    await intake.getByLabel("Participant ID", { exact: true }).fill("P-101");
    await intake
        .getByLabel("Current program", { exact: true })
        .selectOption({ label: "Community learning" });
    await intake
        .getByLabel("Location", { exact: true })
        .selectOption({ label: "Jerusalem center" });
    await intake
        .getByLabel("Group", { exact: true })
        .selectOption({ label: "Learning group" });
    await intake
        .getByLabel("Shared contact (optional)", { exact: true })
        .selectOption({ label: "Noam Levi" });
    await intake
        .getByLabel("Private email", { exact: true })
        .fill("yael.shalev@example.test");
    await intake
        .getByLabel("Private phone", { exact: true })
        .fill("+1 202-555-0111");
    await intake
        .getByRole("button", { name: "Create participant", exact: true })
        .click();
    await expect(page).toHaveURL(/\/participants\/\d+$/);
    await page.reload();
    await expect(page.getByLabel("Private phone", { exact: true })).toHaveValue(
        "+1 202-555-0111",
    );
    await expect(
        page.getByText(
            `nonprofit-programs-contact-${info.project.name}@alpha.example.test`,
            { exact: true },
        ),
    ).toBeVisible();
    await evidence(page, info.project.name, "participant");
    await page.goto(programUrl);
    intake = await open(page, "Add session");
    await intake
        .getByLabel("Session name", { exact: true })
        .fill("Creative workshop");
    await intake.getByLabel("Session date", { exact: true }).fill(f.yesterday);
    await intake
        .getByRole("button", { name: "Create session", exact: true })
        .click();
    await expect(page).toHaveURL(/\/sessions\/\d+$/);
    const sessionUrl = page.url();
    intake = await open(page, "Record participant attendance");
    await intake
        .getByLabel("Participant", { exact: true })
        .selectOption({ label: "Yael Shalev" });
    await intake
        .getByLabel("Attendance status", { exact: true })
        .selectOption({ label: "Present" });
    await intake
        .getByLabel("Attendance notes", { exact: true })
        .fill("Attended the fictional workshop.");
    await intake
        .getByRole("button", { name: "Record attendance", exact: true })
        .click();
    await expect(
        page.getByRole("link", { name: "Yael Shalev", exact: true }),
    ).toBeVisible();
    await page.reload();
    await page.getByRole("link", { name: "Yael Shalev", exact: true }).click();
    await expect(page).toHaveURL(/\/attendance\/\d+$/);
    await page
        .getByLabel("Attendance status", { exact: true })
        .selectOption({ label: "Excused" });
    await page
        .getByRole("button", { name: "Save attendance", exact: true })
        .click();
    await page.reload();
    await expect(
        page.getByLabel("Attendance status", { exact: true }),
    ).toHaveValue("excused");
    await page.goto(`${origin}/nonprofit/programs?section=attendance`);
    await page.getByLabel("From date", { exact: true }).fill(f.yesterday);
    await page.getByLabel("To date", { exact: true }).fill(f.today);
    await page
        .getByLabel("Program filter", { exact: true })
        .selectOption({ label: "Community learning" });
    await page
        .getByRole("button", { name: "Apply filters", exact: true })
        .click();
    await expect(
        page.getByRole("heading", { name: "Attendance summary", exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText("Community learning · Jerusalem center", {
            exact: true,
        }),
    ).toBeVisible();
    for (const label of ["Participant", "Session", "Attendance"]) {
        await expect(
            page.getByRole("columnheader").filter({ hasText: label }).first(),
        ).toBeVisible();
    }
    const downloadPromise = page.waitForEvent("download");
    await page.getByRole("link", { name: "Export CSV", exact: true }).click();
    const download = await downloadPromise;
    const saved = await download.path();
    if (!saved) throw new Error("Missing scoped CSV");
    const csv = readFileSync(saved, "utf8");
    expect(csv).toContain("Yael Shalev");
    expect(csv).toContain("excused");
    expect(csv).not.toContain("https://");
    rmSync(saved);
    await evidence(page, info.project.name, "attendance");
    await page.goto(programUrl);
    intake = await open(page, "Add feedback");
    await intake.getByLabel("Feedback date", { exact: true }).fill(f.today);
    await intake
        .getByLabel("Comments", { exact: true })
        .fill("The group asked for another workshop.");
    await intake
        .getByLabel("Next action", { exact: true })
        .fill("Call group coordinator");
    await intake.getByLabel("Follow-up date", { exact: true }).fill(f.today);
    await intake
        .getByRole("button", { name: "Create feedback", exact: true })
        .click();
    await expect(page).toHaveURL(/\/feedback\/\d+$/);
    const feedbackUrl = page.url();
    fixture("remind", info.project.name);
    await page.goto(`${origin}/reminders`);
    await expect(
        page.getByRole("link", {
            name: "Program follow-up: Call group coordinator",
            exact: true,
        }),
    ).toBeVisible();
    await page.goto(feedbackUrl);
    await page.getByLabel("Completed date", { exact: true }).fill(f.today);
    await page
        .getByRole("button", { name: "Save feedback", exact: true })
        .click();
    await page.reload();
    await expect(
        page.getByLabel("Completed date", { exact: true }),
    ).toHaveValue(f.today);
    await page.goto(`${origin}/reminders`);
    await expect(
        page.getByRole("link", {
            name: "Program follow-up: Call group coordinator",
            exact: true,
        }),
    ).toHaveCount(0);
    await page.goto(programUrl);
    intake = await open(page, "Add document link");
    await intake
        .getByLabel("Document name", { exact: true })
        .fill("Workshop resource");
    await intake
        .getByLabel("Document URL", { exact: true })
        .fill("https://example.test/program-resource.pdf");
    await intake
        .getByRole("button", { name: "Add document", exact: true })
        .click();
    await expect(
        page.getByRole("link", { name: "Workshop resource", exact: true }),
    ).toBeVisible();
    const href = await page
        .getByRole("link", { name: "Workshop resource", exact: true })
        .getAttribute("href");
    const doc = await page.request.get(`${origin}${href}`, { maxRedirects: 0 });
    expect(doc.status()).toBe(302);
    expect(doc.headers().location).toBe(
        "https://example.test/program-resource.pdf",
    );
    await page.reload();
    await expect(
        page.getByRole("heading", { name: "History", exact: true }),
    ).toBeVisible();
    await evidence(page, info.project.name, "program");
    expect(errors).toEqual([]);
});
