import { execFileSync } from "node:child_process";
import { signIn } from "./auth";
import { expect, test } from "./fixtures";

// Keep temporary fixture email sign-in URLs out of retained network traces.
test.use({ installations: ["alpha"], trace: "off" });
const hebrewOrigin = process.env.CRM_HEBREW_ORIGIN ?? "http://127.0.0.1:8107";

test("legacy lead history retains attribution, replies and safe text after reload in EN and HE", async ({
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
        history: he ? "היסטוריה מהמערכת הקודמת" : "Legacy history",
        update: he ? "עדכון מהמערכת הקודמת" : "Legacy update",
        reply: he ? "תגובה מהמערכת הקודמת" : "Legacy reply",
        sourceCreated: he
            ? "יצירת הרשומה במערכת המקור"
            : "Source record creation",
        unknownAuthor: he
            ? "פרטי הכותב המקורי אינם זמינים"
            : "Original author unavailable",
        unknownTime: he
            ? "המועד המקורי אינו זמין"
            : "Original time unavailable",
        importedBy: he ? "יובא על ידי" : "Imported by",
        nativeCreated: he ? "הליד נוצר" : "lead created",
        revision: he ? "גרסה 2 מהמערכת הקודמת" : "Legacy revision 2",
        readFull: he ? "קריאת הטקסט המלא" : "Read full text",
        retry: he ? "ניסיון נוסף" : "Try again",
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
    const prepared = JSON.parse(
        execFileSync(
            "php",
            [
                "scripts/legacy-history-browser-fixtures.php",
                "alpha",
                `--run=${runId}`,
                `--lead=${id}`,
            ],
            { encoding: "utf8" },
        ),
    ) as {
        update_id: number;
        reply_id: number;
        source_created_id: number;
        revision_id: number;
    };
    const listEndpoint = `${origin}${leadPath}/legacy-history`;
    await page.route(listEndpoint, (route) =>
        route.fulfill({
            status: 503,
            contentType: "application/json",
            body: "{}",
        }),
    );
    await page.goto(`${origin}${leadPath}`);
    const history = page.getByRole("region", {
        name: labels.history,
        exact: true,
    });
    await expect(history.getByRole("alert")).toBeVisible();
    await page.unroute(listEndpoint);
    await history
        .getByRole("button", { name: labels.retry, exact: true })
        .click();
    const update = history
        .locator(`#activity-${prepared.update_id}`)
        .getByRole("group", { name: labels.update, exact: true });
    const reply = history
        .locator(`#activity-${prepared.reply_id}`)
        .getByRole("group", { name: labels.reply, exact: true });
    const originalTime = "2020-02-29T09:30:52.123456+02:00";
    for (const reload of [false, true]) {
        if (reload) await page.reload();
        await expect(page.locator("html")).toHaveAttribute(
            "dir",
            he ? "rtl" : "ltr",
        );
        await expect(update).toBeVisible();
        await expect(update.locator(".crm-preserved-text")).toHaveText(
            /Reviewed the fictional customer file\.\s+A second line remains separate\./,
        );
        expect(
            await update.locator(".crm-preserved-text").textContent(),
        ).toMatch(
            /Reviewed the fictional customer file\.\n+A second line remains separate\./,
        );
        await expect(update.locator(".crm-preserved-text")).toHaveCSS(
            "white-space",
            "pre-wrap",
        );
        await expect(
            update.locator(`time[datetime="${originalTime}"]`),
        ).toHaveAttribute("title", originalTime);
        await expect(
            update.getByText("Yael Cohen", { exact: true }),
        ).toBeVisible();
        await expect(
            update.getByText(labels.importedBy, { exact: false }),
        ).toBeVisible();
        await expect(
            reply.getByText(labels.unknownAuthor, { exact: false }),
        ).toBeVisible();
        await expect(
            reply.getByText(labels.unknownTime, { exact: false }),
        ).toBeVisible();
        await expect(reply.getByRole("link")).toHaveAttribute(
            "href",
            `#activity-${prepared.update_id}`,
        );
        const revision = history.locator(`#activity-${prepared.revision_id}`);
        await expect(
            revision.getByText(labels.revision, { exact: true }),
        ).toBeVisible();
        await expect(
            revision.getByText("Final fictional history line.", {
                exact: false,
            }),
        ).toHaveCount(0);
        await revision
            .getByRole("button", { name: labels.readFull, exact: true })
            .click();
        await expect(
            revision.getByText("Final fictional history line.", {
                exact: false,
            }),
        ).toBeVisible();
        await expect(
            revision.getByRole("button", {
                name: labels.readFull,
                exact: true,
            }),
        ).toHaveCount(0);
        await expect(
            history
                .locator(`#activity-${prepared.source_created_id}`)
                .getByRole("group", {
                    name: labels.sourceCreated,
                    exact: true,
                }),
        ).toBeVisible();
        // Existing native activity keeps its ordinary label and actor presentation.
        await expect(
            page.getByText(labels.nativeCreated, { exact: true }),
        ).toBeVisible();
        await expect(history.locator("img, iframe, object, embed")).toHaveCount(
            0,
        );
        expect(
            await page.evaluate(
                () =>
                    (window as Window & { __legacyExecuted?: boolean })
                        .__legacyExecuted,
            ),
        ).toBeUndefined();
        expect(await page.content()).not.toContain("__legacyExecuted");
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
    }
    await update.screenshot({
        path: info.outputPath("legacy-lead-history.png"),
    });
    expect(errors).toEqual([]);
});
