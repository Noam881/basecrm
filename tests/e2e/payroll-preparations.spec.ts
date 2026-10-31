import { test, expect } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { readFileSync, rmSync } from "node:fs";
import { signInLocalFixture } from "./local-reference-login";
test.use({ actionTimeout: 7000 });
const origin = "http://127.0.0.1:8100";
let fixture: { email: string; employee_id: number; today: string };
function prepare(mode: "setup" | "cleanup", project: string) {
    return JSON.parse(
        execFileSync(
            "php",
            ["scripts/payroll-browser-fixtures.php", mode, project],
            { encoding: "utf8" },
        ),
    );
}
test.beforeEach(async ({}, info) => {
    if (
        info.config.workers !== 1 ||
        !["desktop", "mobile"].includes(info.project.name)
    )
        throw new Error(
            "Payroll fixtures require one worker and known projects.",
        );
    prepare("cleanup", info.project.name);
    fixture = prepare("setup", info.project.name);
});
test.afterEach(async ({}, info) => prepare("cleanup", info.project.name));
test("manual final-period payroll inputs documents statuses and CSV persist without HR or recruitment", async ({
    page,
}, info) => {
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await signInLocalFixture(page, fixture.email, origin);
    expect((await page.request.get(`${origin}/leads`)).status()).toBe(404);
    expect(
        (await page.request.get(`${origin}/finance/collections`)).status(),
    ).toBe(403);
    await page.goto(`${origin}/finance/payroll`);
    await page.getByText("Add payroll preparation", { exact: true }).click();
    await page
        .getByLabel("Employee", { exact: true })
        .selectOption(String(fixture.employee_id));
    await page.getByLabel("Period start", { exact: true }).fill("2026-10-01");
    await page.getByLabel("Period end", { exact: true }).fill("2026-10-31");
    await page
        .getByLabel("Reported hours (hours)", { exact: true })
        .fill("165.1234");
    await page.getByLabel(/^Agreed expenses/).fill("1234.56");
    await page
        .getByLabel("Review note", { exact: true })
        .fill("Reviewed agreed final-period inputs.");
    await page
        .getByRole("button", { name: "Create preparation", exact: true })
        .click();
    await expect(page).toHaveURL(/\/finance\/payroll\/\d+$/);
    const url = page.url();
    await page.reload();
    await expect(
        page.getByLabel("Reported hours (hours)", { exact: true }),
    ).toHaveValue("165.1234");
    await expect(page.getByLabel(/^Agreed expenses/)).toHaveValue("1234.56");
    await expect(
        page.getByLabel("Employee", { exact: true }).locator("option:checked"),
    ).toHaveText("Yael Shalev (inactive)");
    await page
        .getByRole("combobox", { name: "Status", exact: true })
        .selectOption("ready-for-review");
    await page
        .getByRole("button", { name: "Save preparation", exact: true })
        .click();
    await expect(page.locator(".crm-badge")).toHaveText("Ready for review");
    await page
        .getByRole("combobox", { name: "Status", exact: true })
        .selectOption("sent-to-payroll");
    await page
        .getByLabel("Manually sent date", { exact: true })
        .fill(fixture.today);
    await page
        .getByRole("button", { name: "Save preparation", exact: true })
        .click();
    await expect(page.locator(".crm-badge")).toHaveText("Sent to payroll");
    await page
        .getByLabel("Document label", { exact: true })
        .fill("Reviewed input summary");
    await page
        .getByLabel("Document link", { exact: true })
        .fill("https://documents.example.test/payroll-review");
    await page
        .getByRole("button", { name: "Add supporting document", exact: true })
        .click();
    await expect(
        page.getByRole("link", { name: "Reviewed input summary", exact: true }),
    ).toBeVisible();
    await page.reload();
    await expect(
        page.getByLabel("Manually sent date", { exact: true }),
    ).toHaveValue(fixture.today);
    await expect(
        page.getByLabel("Reported hours (hours)", { exact: true }),
    ).toHaveValue("165.1234");
    const image = await page.screenshot({
        path: `/private/tmp/basecrm-${info.project.name}-payroll-preparation-20261025.png`,
        fullPage: true,
    });
    await info.attach("Manual payroll persists without HR", {
        body: image,
        contentType: "image/png",
    });
    await page.goto(`${origin}/finance/payroll?status=sent-to-payroll&q=Yael`);
    const [download] = await Promise.all([
        page.waitForEvent("download"),
        page.getByRole("link", { name: "Export CSV", exact: true }).click(),
    ]);
    const file = await download.path();
    if (!file) throw new Error("Expected an actual payroll CSV download.");
    const csv = readFileSync(file, "utf8");
    expect(csv).toContain("Yael Shalev");
    expect(csv).toContain("165.1234");
    expect(csv).toContain("1234.56");
    expect(csv).toContain("Review note");
    rmSync(file);
    await page.goto(url);
    await page
        .getByRole("button", { name: "Archive preparation", exact: true })
        .click();
    await expect(
        page.getByText(
            "Archived · Retained for this employee and pay period.",
            { exact: true },
        ),
    ).toBeVisible();
    await expect(
        page.getByRole("link", { name: "Reviewed input summary", exact: true }),
    ).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    expect(errors).toEqual([]);
});
