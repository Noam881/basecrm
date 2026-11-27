import { type Page } from "@playwright/test";
import { readFile } from "node:fs/promises";
import { signIn } from "./auth";
import { expect, test } from "./fixtures";

const hebrewOrigin = process.env.CRM_HEBREW_ORIGIN ?? "http://127.0.0.1:8107";
// Both EN and HE previews intentionally use the guarded Alpha fixture database.
test.use({ installations: ["alpha"] });

function presentation(project: string) {
    const hebrew = project === "mobile";
    return {
        origin: hebrew ? hebrewOrigin : "http://127.0.0.1:8091",
        name: hebrew ? "שם" : "Name",
        email: hebrew ? "אימייל" : "Email",
        phone: hebrew ? "טלפון" : "Phone",
        recordType: hebrew ? "סוג הרשומה" : "Record type",
        createContact: hebrew ? "יצירת איש קשר" : "Create contact",
        createCompany: hebrew ? "יצירת חברה" : "Create company",
        duplicate: hebrew
            ? "שמירת רשומה נפרדת אם נמצאת כפילות"
            : "Keep a separate record if a duplicate is found",
        documents: hebrew ? "מסמכים" : "Documents",
        file: hebrew ? "קובץ מסמך" : "Document file",
        upload: hebrew ? "העלאת מסמך" : "Upload document",
        empty: hebrew ? "עדיין אין מסמכים." : "No documents yet.",
        success: hebrew ? "המסמך הועלה." : "Document uploaded.",
        notes: hebrew ? "הערות" : "Notes",
        addNoteField: hebrew ? "הוספת הערה" : "Add a note",
        addNote: hebrew ? "הוספת הערה" : "Add note",
        editContact: hebrew ? "עריכת פרטי איש הקשר" : "Edit contact details",
        save: hebrew ? "שמירת שינויים" : "Save changes",
        retry: hebrew ? "ניסיון נוסף" : "Try again",
        download: (filename: string) =>
            hebrew ? `הורדת ${filename}` : `Download ${filename}`,
    };
}

async function createProfile(
    page: Page,
    project: string,
    runId: string,
    type: "contacts" | "companies",
) {
    const labels = presentation(project);
    await page.goto(`${labels.origin}/people`);
    if (type === "companies") {
        await page.getByLabel(labels.recordType).selectOption("companies");
    }
    await page
        .getByLabel(labels.name, { exact: true })
        .fill(type === "contacts" ? "Shira Levi" : "Levi Solutions");
    await page
        .getByLabel(labels.email, { exact: true })
        .fill(
            `${type === "contacts" ? "contact" : "company"}-${runId}@example.test`,
        );
    await page
        .getByLabel(labels.phone, { exact: true })
        .fill("+1 202-555-0184");
    await page
        .getByRole("checkbox", { name: labels.duplicate, exact: true })
        .check();
    await page
        .getByRole("button", {
            name:
                type === "contacts"
                    ? labels.createContact
                    : labels.createCompany,
            exact: true,
        })
        .click();
    await expect(page).toHaveURL(new RegExp(`/people/${type}/\\d+$`));
    const profileUrl = page.url();
    return { labels, profileUrl, endpoint: `${profileUrl}/documents` };
}

test("shared contact and company documents upload, survive reload and download original bytes in EN and HE", async ({
    page,
    runId,
}, info) => {
    test.setTimeout(90000);
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await signIn(page, info.project.name);
    for (const type of ["contacts", "companies"] as const) {
        const { labels, profileUrl } = await createProfile(
            page,
            info.project.name,
            runId,
            type,
        );
        await expect(page.locator("html")).toHaveAttribute(
            "dir",
            info.project.name === "mobile" ? "rtl" : "ltr",
        );
        const documents = page.getByRole("region", {
            name: labels.documents,
            exact: true,
        });
        const input = documents.getByLabel(labels.file, { exact: true });
        const upload = documents.getByRole("button", {
            name: labels.upload,
            exact: true,
        });
        await expect(
            documents.getByText(labels.empty, { exact: true }),
        ).toBeVisible();
        await expect(upload).toBeDisabled();
        await input.setInputFiles({
            name: "Empty.pdf",
            mimeType: "application/pdf",
            buffer: Buffer.alloc(0),
        });
        await expect(documents.getByRole("alert")).toBeVisible();
        await expect(upload).toBeDisabled();
        await input.setInputFiles({
            name: "Invalid.pdf",
            mimeType: "application/pdf",
            buffer: Buffer.from("<?php echo 'Fictional invalid document';"),
        });
        await upload.click();
        await expect(documents.getByRole("alert")).toBeVisible();
        await expect(page).toHaveURL(profileUrl);
        await expect(documents.locator("article")).toHaveCount(0);
        const filename = "Shira Levi & מסמכי הצעה.pdf";
        const bytes = Buffer.from(
            "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n% Fictional customer document\n%%EOF",
        );
        await input.setInputFiles({
            name: filename,
            mimeType: "application/pdf",
            buffer: bytes,
        });
        const fieldBox = await input.boundingBox();
        const actionBox = await upload.boundingBox();
        expect(fieldBox).not.toBeNull();
        expect(actionBox).not.toBeNull();
        expect(
            actionBox!.y - (fieldBox!.y + fieldBox!.height),
        ).toBeGreaterThanOrEqual(24);
        await upload.click();
        await expect(
            documents.getByText(labels.success, { exact: true }),
        ).toBeVisible();
        await expect(documents.locator("article")).toHaveCount(1);
        await expect(input).toHaveValue("");
        await page.reload();
        await expect(documents.locator("article")).toHaveCount(1);
        await expect(documents.locator("article bdi").first()).toHaveText(
            filename,
        );
        const downloadPromise = page.waitForEvent("download");
        await documents
            .getByRole("link", { name: labels.download(filename), exact: true })
            .click();
        const download = await downloadPromise;
        expect(download.suggestedFilename()).toBe(filename);
        expect(await download.failure()).toBeNull();
        expect(await readFile((await download.path())!)).toEqual(bytes);
        const note = "Reviewed the fictional customer document.";
        const notes = page.getByRole("region", {
            name: labels.notes,
            exact: true,
        });
        await notes.getByLabel(labels.addNoteField, { exact: true }).fill(note);
        await notes
            .getByRole("button", { name: labels.addNote, exact: true })
            .click();
        await expect(notes.getByText(note, { exact: true })).toBeVisible();
        if (type === "contacts") {
            await page
                .getByRole("button", { name: labels.editContact, exact: true })
                .click();
        }
        await page
            .getByLabel(labels.phone, { exact: true })
            .fill("+1 202-555-0185");
        await page
            .getByRole("checkbox", { name: labels.duplicate, exact: true })
            .check();
        await page
            .getByRole("button", { name: labels.save, exact: true })
            .click();
        await page.reload();
        await expect(notes.getByText(note, { exact: true })).toBeVisible();
        if (type === "contacts") {
            await expect(
                page.getByRole("link", {
                    name: "+1 202-555-0185",
                    exact: true,
                }),
            ).toHaveAttribute("href", "tel:+1 202-555-0185");
        } else
            await expect(
                page.getByLabel(labels.phone, { exact: true }),
            ).toHaveValue("+1 202-555-0185");
        await expect(documents.locator("article")).toHaveCount(1);
        await expect(documents.getByRole("alert")).toHaveCount(0);
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
        await documents.screenshot({
            path: info.outputPath(`${type}-documents.png`),
        });
    }
    expect(errors).toEqual([]);
});

test("document loading failures can retry and server read-only permission hides uploading", async ({
    page,
    runId,
}, info) => {
    await signIn(page, info.project.name);
    const { labels, profileUrl, endpoint } = await createProfile(
        page,
        info.project.name,
        runId,
        "contacts",
    );
    await page.route(endpoint, async (route) => {
        if (route.request().method() === "GET") {
            await route.fulfill({
                status: 503,
                contentType: "application/json",
                body: "{}",
            });
        } else await route.continue();
    });
    await page.reload();
    const documents = page.getByRole("region", {
        name: labels.documents,
        exact: true,
    });
    await expect(documents.getByRole("alert")).toBeVisible();
    await expect(documents.locator("input[type=file]")).toHaveCount(0);
    await page.unroute(endpoint);
    await documents
        .getByRole("button", { name: labels.retry, exact: true })
        .click();
    await expect(
        documents.getByLabel(labels.file, { exact: true }),
    ).toBeVisible();
    await page.route(endpoint, async (route) => {
        const response = await route.fetch();
        const body = await response.json();
        await route.fulfill({ response, json: { ...body, can_upload: false } });
    });
    await page.goto(profileUrl);
    await expect(
        documents.getByText(labels.empty, { exact: true }),
    ).toBeVisible();
    await expect(
        documents.getByRole("button", { name: labels.upload, exact: true }),
    ).toHaveCount(0);
    await expect(documents.locator("input[type=file]")).toHaveCount(0);
});
