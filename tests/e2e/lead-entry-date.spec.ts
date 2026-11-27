import { execFileSync } from "node:child_process";
import { signIn } from "./auth";
import { test, expect } from "./fixtures";

// Do not retain network traces containing the temporary fixture email sign-in URL.
test.use({ installations: ["alpha"], trace: "off" });
const hebrewOrigin = process.env.CRM_HEBREW_ORIGIN ?? "http://127.0.0.1:8107";

test("Created preserves date precision and exact fallback across profile, table and reload in EN and HE", async ({
    page,
    runId,
}, info) => {
    test.setTimeout(60000);
    const he = info.project.name === "mobile";
    const origin = he ? hebrewOrigin : "http://127.0.0.1:8091";
    const labels = {
        name: he ? "שם" : "Name",
        email: he ? "אימייל" : "Email",
        duplicate: he
            ? "שמירת רשומה נפרדת אם נמצאת כפילות"
            : "Keep a separate record if a duplicate is found",
        createContact: he ? "יצירת איש קשר" : "Create contact",
        addLead: he ? "+ יצירת ליד" : "+ Create lead",
        contact: he ? "איש קשר" : "Contact",
        createLead: he ? "יצירת ליד" : "Create lead",
        details: he ? "פרטי הליד" : "Lead details",
        created: he ? "נוצר בתאריך" : "Created",
        audit: he ? "מועד יצירת הרשומה" : "Record creation timestamp",
    };
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await signIn(page, info.project.name);
    await page.goto(`${origin}/people`);
    await page.getByLabel(labels.name, { exact: true }).fill("Shira Levi");
    await page
        .getByLabel(labels.email, { exact: true })
        .fill(`contact-${runId}@example.test`);
    await page
        .getByRole("checkbox", { name: labels.duplicate, exact: true })
        .check();
    await page
        .getByRole("button", { name: labels.createContact, exact: true })
        .click();
    await expect(page).toHaveURL(/\/people\/contacts\/\d+$/);
    const contactId = new URL(page.url()).pathname.split("/").at(-1)!;
    await page.goto(`${origin}/leads`);
    await page
        .getByRole("button", { name: labels.addLead, exact: true })
        .click();
    await page
        .getByRole("combobox", { name: labels.contact, exact: true })
        .selectOption(contactId);
    await page
        .getByRole("button", { name: labels.createLead, exact: true })
        .click();
    await expect(page).toHaveURL(/\/leads\/\d+$/);
    const leadPath = new URL(page.url()).pathname;
    const id = leadPath.split("/").at(-1)!;
    const card = page.locator("section.crm-card").filter({
        has: page.getByRole("heading", {
            name: labels.details,
            exact: true,
        }),
    });
    const locale = he ? "he-IL" : "en-US";
    const timestamp = "2020-02-29T09:30:52.000000Z";
    const expectedDay = new Intl.DateTimeFormat(locale, {
        dateStyle: "medium",
        timeZone: "UTC",
    }).format(new Date("2011-12-30T00:00:00Z"));
    const expectedTimestamp = new Intl.DateTimeFormat(locale, {
        dateStyle: "medium",
        timeStyle: "short",
        timeZone: "Asia/Jerusalem",
    }).format(new Date(timestamp));
    for (const mode of ["dated", "fallback"] as const) {
        execFileSync("php", [
            "scripts/lead-entry-date-browser-fixtures.php",
            "alpha",
            `--run=${runId}`,
            `--lead=${id}`,
            `--mode=${mode}`,
        ]);
        await page.goto(`${origin}${leadPath}`);
        await expect(page.locator("html")).toHaveAttribute(
            "dir",
            he ? "rtl" : "ltr",
        );
        const created = card
            .locator("p")
            .filter({ hasText: labels.created })
            .locator("time");
        await expect(created).toHaveText(
            mode === "dated" ? expectedDay : expectedTimestamp,
        );
        await expect(created).toHaveAttribute(
            "datetime",
            mode === "dated" ? "2011-12-30" : timestamp,
        );
        const audit = card.locator("p").filter({ hasText: labels.audit });
        if (mode === "dated")
            await expect(audit.locator("time")).toHaveAttribute(
                "datetime",
                timestamp,
            );
        else await expect(audit).toHaveCount(0);
        await page.reload();
        await expect(created).toHaveText(
            mode === "dated" ? expectedDay : expectedTimestamp,
        );
        await card.screenshot({
            path: info.outputPath(`${mode}-lead-date.png`),
        });
        await page.goto(
            `${origin}/leads?q=${encodeURIComponent(`contact-${runId}@example.test`)}`,
        );
        const columnsLabel = he ? "עמודות" : "Columns";
        await page
            .getByRole("button", { name: columnsLabel, exact: false })
            .click();
        const columns = page.getByRole("dialog", {
            name: columnsLabel,
            exact: true,
        });
        await columns
            .getByRole("checkbox", {
                name: he
                    ? `הצגת העמודה ${labels.created}`
                    : `Show ${labels.created} column`,
                exact: true,
            })
            .check();
        await columns
            .getByRole("button", { name: he ? "בוצע" : "Done", exact: true })
            .click();
        const row = page.locator("tbody tr").filter({
            has: page.getByRole("link", {
                name: "Shira Levi",
                exact: true,
            }),
        });
        await expect(row).toHaveCount(1);
        await expect(
            row.getByText(mode === "dated" ? expectedDay : expectedTimestamp, {
                exact: true,
            }),
        ).toHaveCount(1);
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
    }
    expect(errors).toEqual([]);
});
