import { execFileSync } from "node:child_process";
import { test, expect } from "@playwright/test";
import { signIn } from "./auth";

const manifest = "storage/app/private/finance-history-browser-en.json";
const fixture = (project: string, args: string[]) =>
    JSON.parse(
        execFileSync(
            "php",
            [
                "scripts/finance-history-browser-fixtures.php",
                "alpha",
                `--project=${project}`,
                ...args,
            ],
            { env: { ...process.env, CRM_MANIFEST: manifest } },
        ).toString(),
    );
function cleanup(project: string) {
    fixture(project, ["--cleanup"]);
    execFileSync("php", [
        "scripts/clean-browser-fixtures.php",
        "alpha",
        `--project=${project}`,
        "--apply",
    ]);
}
// Only dedicated Alpha accounts and exact Finance run namespaces; never retain email tokens in traces.
test.use({ trace: "off" });
test.beforeEach(({}, info) => cleanup(info.project.name));
test.afterEach(({}, info) => cleanup(info.project.name));

test("financial history retains exact values and attribution through table profile and reload in EN and HE", async ({
    page,
}, info) => {
    test.setTimeout(60000);
    const he = info.project.name === "mobile";
    const origin = he ? "http://127.0.0.1:8107" : "http://127.0.0.1:8091";
    const run = `${info.project.name}-${Date.now()}`;
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await signIn(page, info.project.name);
    await page.goto(`${origin}/people`);
    await page
        .getByLabel(he ? "שם" : "Name", { exact: true })
        .fill("Shira Levi");
    await page
        .getByLabel(he ? "אימייל" : "Email", { exact: true })
        .fill(`contact-${run}@example.test`);
    await page
        .getByRole("checkbox", {
            name: he
                ? "שמירת רשומה נפרדת אם נמצאת כפילות"
                : "Keep a separate record if a duplicate is found",
            exact: true,
        })
        .check();
    await page
        .getByRole("button", {
            name: he ? "יצירת איש קשר" : "Create contact",
            exact: true,
        })
        .click();
    await expect(page).toHaveURL(/\/people\/contacts\/\d+$/);
    const contact = new URL(page.url()).pathname.split("/").at(-1)!;
    const prepared = fixture(info.project.name, [
        `--run=${run}`,
        `--contact=${contact}`,
    ]);
    await page.goto(`${origin}/finance/history`);
    await page
        .getByRole("link", {
            name: "Carmel Studio historical income",
            exact: true,
        })
        .click();
    await expect(page).toHaveURL(
        new RegExp(`/finance/history/${prepared.record_id}$`),
    );
    await expect(
        page
            .getByRole("heading", {
                name: "Carmel Studio historical income",
                exact: true,
            })
            .first(),
    ).toBeVisible();
    await expect(
        page.getByText("9007199254740993.12345678901234567890", {
            exact: true,
        }),
    ).toHaveCount(1);
    await expect(page.getByText("Yael Cohen", { exact: true })).toBeVisible();
    await expect(
        page.getByText(
            he
                ? "פרטי הכותב המקורי אינם זמינים"
                : "Original author unavailable",
            { exact: false },
        ),
    ).toBeVisible();
    await expect(
        page.locator('time[datetime="2020-02-29T09:30:52.123456789+02:00"]'),
    ).toHaveCount(1);
    await expect(page.locator("html")).toHaveAttribute(
        "dir",
        he ? "rtl" : "ltr",
    );
    expect(
        await page.evaluate(
            () =>
                (window as Window & { __financeUnsafe?: boolean })
                    .__financeUnsafe,
        ),
    ).toBeUndefined();
    const full = page.getByRole("button", {
        name: he ? "קריאת הרשומה המלאה" : "Read full entry",
        exact: true,
    });
    await full.click();
    await expect(page.getByText(/Final reference line\./)).toBeVisible();
    await page.reload();
    await expect(
        page.getByText("9007199254740993.12345678901234567890", {
            exact: true,
        }),
    ).toHaveCount(1);
    await page.screenshot({ path: info.outputPath("finance-history.png") });
    await page.goto(`${origin}/people/contacts/${contact}`);
    await expect(
        page.getByRole("heading", {
            name: he ? "היסטוריה כספית" : "Financial history",
            exact: true,
        }),
    ).toBeVisible();
    await page
        .getByRole("link", {
            name: "Carmel Studio historical income",
            exact: true,
        })
        .click();
    await expect(page).toHaveURL(
        new RegExp(`/finance/history/${prepared.record_id}$`),
    );
    expect(errors).toEqual([]);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
});
