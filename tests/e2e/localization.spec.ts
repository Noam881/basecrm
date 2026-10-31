import { expect, test } from "./fixtures";
import { signIn } from "./auth";

test.use({ actionTimeout: 7000 });

const hebrewOrigin = process.env.CRM_HEBREW_ORIGIN ?? "http://127.0.0.1:8107";

// The documented Hebrew preview uses the existing isolated Alpha fixture database.
// Its UI profile changes only presentation; sign-in uses the reserved project account.
test("Hebrew green presentation preserves neutral controls, RTL tables and saved data", async ({
    page,
    runId,
}, info) => {
    await signIn(page, info.project.name);
    await page.goto(`${hebrewOrigin}/leads`);
    await expect(page.locator("html")).toHaveAttribute("lang", "he");
    await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
    await expect(page.locator("html")).toHaveAttribute(
        "data-crm-theme",
        "green",
    );
    await expect(
        page.getByRole("heading", { name: "לידים", exact: true, level: 1 }),
    ).toBeVisible();
    await expect(page.locator(".crm")).toHaveCSS(
        "background-color",
        "rgb(250, 250, 250)",
    );
    await expect(page.locator(".crm")).toHaveCSS(
        "font-family",
        /Noto Sans Hebrew/,
    );
    const table = page.locator(".md-table");
    await expect(table.getByRole("button", { name: /^עמודות/ })).toBeVisible();
    await expect(table.locator(".md-header-sort").first()).toHaveCSS(
        "text-align",
        "start",
    );
    await expect(table.locator("th.md-pinned").first()).toHaveCSS(
        "right",
        "0px",
    );
    // Create only cleanup-recognized records owned by the dedicated table account.
    await page.goto(`${hebrewOrigin}/people`);
    await page.getByLabel("שם", { exact: true }).fill("Uri Ben David");
    const email = `uri.${runId}@demo.example.test`;
    await page.getByLabel("אימייל", { exact: true }).fill(email);
    await page.getByLabel("טלפון", { exact: true }).fill("+1 202-555-0189");
    const note = "הערת דוגמה בעברית עם כתובת example.test";
    await page.getByLabel("הערות", { exact: true }).fill(note);
    await page
        .getByRole("checkbox", {
            name: "שמירת רשומה נפרדת אם נמצאת כפילות",
            exact: true,
        })
        .check();
    await page.locator("form .crm-form-action button").click();
    await expect(page).toHaveURL(/\/people\/contacts\/\d+$/);
    const contactId = new URL(page.url()).pathname.split("/").at(-1)!;
    await page.reload();
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(
        "Uri Ben David",
    );
    await expect(
        page.getByRole("link", { name: email, exact: true }),
    ).toHaveAttribute("href", `mailto:${email}`);
    await expect(page.getByText(note, { exact: true })).toBeVisible();
    await page.goto(`${hebrewOrigin}/leads`);
    await page.locator(".crm-heading .crm-button").click();
    await page
        .getByRole("combobox", { name: "איש קשר", exact: true })
        .selectOption(contactId);
    await page
        .getByLabel("כותרת הפנייה (רשות)", { exact: true })
        .fill("Service enquiry");
    const actorId = await page.evaluate(() => {
        const payload = document.querySelector(
            'script[data-page="app"][type="application/json"]',
        )?.textContent;
        return payload ? String(JSON.parse(payload).props.auth.user.id) : "";
    });
    await page
        .getByRole("combobox", { name: "אחראי/ת", exact: true })
        .selectOption(actorId);
    await page
        .getByRole("combobox", { name: "עדיפות", exact: true })
        .selectOption("high");
    await page.getByRole("button", { name: "יצירת ליד", exact: true }).click();
    await expect(page).toHaveURL(/\/leads\/\d+$/);
    const leadPath = new URL(page.url()).pathname;
    const leadDetails = page.locator("section").filter({
        has: page.getByRole("heading", { name: "פרטי הליד", exact: true }),
    });
    await page.reload();
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(
        "Uri Ben David",
    );
    await expect(
        leadDetails.getByRole("combobox", { name: "עדיפות", exact: true }),
    ).toHaveValue("high");
    await expect(leadDetails.getByLabel("מקור", { exact: true })).toHaveValue(
        "Direct",
    );
    await leadDetails
        .getByRole("combobox", { name: "עדיפות", exact: true })
        .selectOption("low");
    await leadDetails
        .getByRole("button", { name: "שמירת שינויים", exact: true })
        .click();
    await page.reload();
    await expect(
        leadDetails.getByRole("combobox", { name: "עדיפות", exact: true }),
    ).toHaveValue("low");
    await page.goto(`${hebrewOrigin}/leads`);
    await expect(page.locator(`a[href="${leadPath}"]`).first()).toHaveText(
        "Uri Ben David",
    );
    const createdLeadRow = page.locator(".md-table tbody tr").filter({
        has: page.locator(`a[href="${leadPath}"]`),
    });
    const displayedPhone = createdLeadRow.locator("bdi[dir='ltr']").filter({
        hasText: "+1 202-555-0189",
    });
    await expect(displayedPhone).toHaveText("+1 202-555-0189");
    await expect(displayedPhone).toHaveCSS("direction", "ltr");
    await expect(displayedPhone).toHaveCSS("unicode-bidi", "isolate");
    await page.screenshot({
        path: `test-results/${info.project.name}-hebrew-leads.png`,
        fullPage: true,
    });
    await page.goto("http://127.0.0.1:8091/leads");
    await expect(page.locator("html")).toHaveAttribute("dir", "ltr");
    await expect(page.locator("html")).toHaveAttribute(
        "data-crm-theme",
        "purple",
    );
    await expect(
        page.getByRole("heading", { name: "Leads", exact: true, level: 1 }),
    ).toBeVisible();
});

test("Hebrew sign-in and light/dark green accents use shared Material roles", async ({
    page,
}) => {
    await page.goto(`${hebrewOrigin}/login`);
    await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
    await expect(
        page.getByRole("heading", { name: "כניסה", exact: true, level: 1 }),
    ).toBeVisible();
    const email = page.locator("input[type=email]");
    await expect(email).toHaveCSS("direction", "ltr");
    const action = page.locator("button[type=submit]");
    await expect(action).toHaveCSS("background-color", "rgb(35, 101, 78)");
    await expect(action).toHaveCSS("color", "rgb(255, 255, 255)");
    await email.fill("noa.levi@example.test");
    await expect(email).toHaveValue("noa.levi@example.test");
    await email.focus();
    await expect(page.locator(".crm-floating-label")).toContainText(/[֐-׿]/);
    await page.evaluate(() => document.documentElement.classList.add("dark"));
    await expect(action).toHaveCSS("background-color", "rgb(141, 210, 177)");
    await expect(action).toHaveCSS("color", "rgb(9, 56, 37)");
    await page.evaluate(() =>
        document.documentElement.classList.remove("dark"),
    );
    await expect(action).toHaveCSS("background-color", "rgb(35, 101, 78)");
});
