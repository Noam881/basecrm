import { test, expect } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { readFileSync, rmSync } from "node:fs";
test.use({ actionTimeout: 7000 });
const origin = "http://127.0.0.1:8096";
import { signInLocalFixture } from "./local-reference-login";
let fixture: { email: string };
function prepare(mode: "setup" | "cleanup", project: string) {
    return JSON.parse(
        execFileSync(
            "php",
            ["scripts/employee-reference-browser-fixtures.php", mode, project],
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
            "Employee fixtures require one worker and known projects.",
        );
    prepare("cleanup", info.project.name);
    fixture = prepare("setup", info.project.name);
});
test.afterEach(async ({}, info) => prepare("cleanup", info.project.name));
test("employee references persist without HR Sales contacts or login creation", async ({
    page,
}, info) => {
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await signInLocalFixture(page, fixture.email, origin);
    expect((await page.request.get(`${origin}/leads`)).status()).toBe(404);
    await page.goto(`${origin}/people/employees`);
    await expect(
        page.getByRole("heading", {
            name: "Employee references",
            exact: true,
            level: 1,
        }),
    ).toBeVisible();
    await page.getByText("Add employee reference", { exact: true }).click();
    await page.getByLabel("Employee name", { exact: true }).fill("Shira Levi");
    await page
        .getByRole("button", { name: "Create employee reference", exact: true })
        .click();
    await expect(page).toHaveURL(/\/people\/employees\/\d+$/);
    const recordUrl = page.url();
    await page.reload();
    await expect(page.getByLabel("Employee name", { exact: true })).toHaveValue(
        "Shira Levi",
    );
    await expect(page.getByText("Not linked", { exact: true })).toHaveCount(2);
    await page
        .getByLabel("Employee name", { exact: true })
        .fill("Shira Ben Levi");
    await page
        .getByRole("button", { name: "Save employee reference", exact: true })
        .click();
    await expect(
        page.getByRole("heading", { name: "Shira Ben Levi", exact: true }),
    ).toBeVisible();
    await page
        .getByRole("button", { name: "Deactivate reference", exact: true })
        .click();
    await expect(page.locator(".crm-badge")).toHaveText("Inactive");
    await page.reload();
    await expect(page.getByLabel("Employee name", { exact: true })).toHaveValue(
        "Shira Ben Levi",
    );
    await expect(
        page.getByRole("button", { name: "Reactivate reference", exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole("heading", { name: "Reference history", exact: true }),
    ).toBeVisible();
    const image = await page.screenshot({
        path: `/private/tmp/basecrm-${info.project.name}-employee-reference-20261025.png`,
        fullPage: true,
    });
    await info.attach("Employee reference persists independently", {
        body: image,
        contentType: "image/png",
    });
    await page.goto(`${origin}/people/employees?active=0&q=Shira%20Ben%20Levi`);
    await expect(
        page
            .locator("tbody")
            .getByRole("link", { name: "Shira Ben Levi", exact: true }),
    ).toBeVisible();
    const [download] = await Promise.all([
        page.waitForEvent("download"),
        page.getByRole("link", { name: "Export CSV", exact: true }).click(),
    ]);
    const file = await download.path();
    if (!file) throw new Error("Expected an actual CSV download.");
    expect(readFileSync(file, "utf8")).toContain('"Shira Ben Levi",No');
    rmSync(file);
    await page.goto(recordUrl);
    await page
        .getByRole("button", { name: "Reactivate reference", exact: true })
        .click();
    await expect(page.locator(".crm-badge")).toHaveText("Active");
    await page.getByLabel("Employee name", { exact: true }).fill("Shira Levi");
    await page
        .getByRole("button", { name: "Save employee reference", exact: true })
        .click();
    await expect(
        page.getByRole("heading", { name: "Shira Levi", exact: true }),
    ).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    expect(errors).toEqual([]);
});
