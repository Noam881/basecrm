import { type Locator, type Page } from "@playwright/test";
import { mkdir, readFile } from "node:fs/promises";
import { test, expect } from "./fixtures";
import { signIn } from "./auth";

test.use({ actionTimeout: 8000 });

const evidenceDirectory =
    "storage/app/private/acceptance-evidence/lead-fields-20261126";

const hebrewOrigin = process.env.CRM_HEBREW_ORIGIN ?? "http://127.0.0.1:8107";
const fields = [
    [
        "business_sector",
        "Business sector",
        "תחום העסק",
        "Business consulting",
        "Operations consulting",
    ],
    [
        "product_sold",
        "Product sold",
        "מה נמכר?",
        "Business planning package",
        "Operations planning package",
    ],
    [
        "contract_sent_on",
        "Contract sent date",
        "תאריך שליחת החוזה",
        "2026-11-10",
        "2026-11-11",
    ],
    [
        "invoice_sent_on",
        "Invoice sent date",
        "תאריך שליחת החשבונית",
        "2026-11-12",
        "2026-11-13",
    ],
    [
        "payment_received_on",
        "Payment received date",
        "תאריך קבלת התשלום",
        "2026-11-14",
        "2026-11-15",
    ],
    [
        "process_started_on",
        "Process start date",
        "תאריך התנעת התהליך",
        "2026-11-16",
        "2026-11-17",
    ],
    [
        "contract_url",
        "Contract link",
        "לינק לחוזה",
        "https://documents.example.test/contract",
        "https://documents.example.test/revised-contract",
    ],
] as const;
const extraColumns = [
    "Company registration number",
    "Privacy policy accepted",
    "Proposal link",
    "Proposal sent date",
    "Signed contract file",
    "Signed contract versions",
];

function leadDetails(page: Page, language: "en" | "he" = "en") {
    return page.locator("section.crm-card").filter({
        has: page.getByRole("heading", {
            name: language === "he" ? "פרטי הליד" : "Lead details",
            exact: true,
        }),
    });
}

async function values(form: Locator, language: "en" | "he", edited = false) {
    for (const [, en, he, initial, changed] of fields) {
        await expect(
            form.getByLabel(language === "he" ? he : en, { exact: true }),
        ).toHaveValue(edited ? changed : initial);
    }
}

async function tableCell(table: Locator, row: Locator, label: string) {
    const headings = await table.locator("thead th").allTextContents();
    const index = headings.findIndex((heading) => heading.includes(label));
    expect(
        index,
        `${label} should be an available visible column`,
    ).toBeGreaterThan(-1);
    return row.locator("td").nth(index);
}

test("lead baseline fields, company registration and private signed contracts save, edit, clear and reload in English and Hebrew", async ({
    page,
    runId,
    browser,
}, info) => {
    test.setTimeout(150000);
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    const email = `contact-${runId}@example.test`;
    const name = "Shira Levi";
    const company = "Levi Solutions";
    await signIn(page, info.project.name);
    const actorId = await page.evaluate(() => {
        const payload = document.querySelector(
            'script[data-page="app"][type="application/json"]',
        )?.textContent;
        return payload ? String(JSON.parse(payload).props.auth.user.id) : "";
    });
    expect(actorId).toMatch(/^\d+$/);

    await page.goto("/people");
    await page
        .getByRole("combobox", { name: "Record type", exact: true })
        .selectOption("companies");
    await page.getByLabel("Name", { exact: true }).fill(company);
    await page
        .getByLabel("Email", { exact: true })
        .fill(`company-${runId}@example.test`);
    await page
        .getByLabel("Company registration number", { exact: true })
        .fill("001234567");
    await page
        .getByRole("checkbox", {
            name: "Keep a separate record if a duplicate is found",
            exact: true,
        })
        .check();
    await page
        .getByRole("button", { name: "Create company", exact: true })
        .click();
    await expect(page).toHaveURL(/\/people\/companies\/\d+$/);
    const companyPath = new URL(page.url()).pathname;
    const companyId = companyPath.split("/").at(-1)!;
    await page.reload();
    const registration = page.getByLabel("Company registration number", {
        exact: true,
    });
    await expect(registration).toHaveValue("001234567");
    await registration.fill("000987654");
    await page
        .getByRole("button", { name: "Save changes", exact: true })
        .click();
    await expect(
        page.getByRole("button", { name: "Edit company details", exact: true }),
    ).toBeVisible();
    await page.reload();
    await expect(registration).toHaveValue("000987654");

    await page.goto("/people");
    await page.getByLabel("Name", { exact: true }).fill(name);
    await page.getByLabel("Email", { exact: true }).fill(email);
    await page.getByLabel("Phone", { exact: true }).fill("+1 202-555-0184");
    await page
        .getByRole("combobox", { name: "Company", exact: true })
        .selectOption(companyId);
    await page
        .getByRole("checkbox", {
            name: "Keep a separate record if a duplicate is found",
            exact: true,
        })
        .check();
    await page
        .getByRole("button", { name: "Create contact", exact: true })
        .click();
    await expect(page).toHaveURL(/\/people\/contacts\/\d+$/);
    const contactId = new URL(page.url()).pathname.split("/").at(-1)!;

    await page.goto("/leads");
    await page
        .getByRole("button", { name: "+ Create lead", exact: true })
        .click();
    await page
        .getByRole("combobox", { name: "Contact", exact: true })
        .selectOption(contactId);
    await page
        .getByRole("combobox", { name: "Company", exact: true })
        .selectOption(companyId);
    await page
        .getByRole("combobox", { name: "Owner", exact: true })
        .selectOption(actorId);
    for (const [, label, , initial] of fields)
        await page.getByLabel(label, { exact: true }).fill(initial);
    await page
        .getByRole("combobox", { name: "Privacy policy accepted", exact: true })
        .selectOption({ label: "No" });
    await page
        .getByRole("button", { name: "Create lead", exact: true })
        .click();
    await expect(page).toHaveURL(/\/leads\/\d+$/);
    const leadPath = new URL(page.url()).pathname;
    const details = leadDetails(page);
    await values(details, "en");
    await expect(
        details.getByRole("combobox", {
            name: "Privacy policy accepted",
            exact: true,
        }),
    ).toHaveValue("false");
    const createdAt = await details.locator("time").getAttribute("datetime");
    expect(Number.isNaN(Date.parse(createdAt ?? ""))).toBe(false);
    await page.reload();
    await values(details, "en");
    await expect(
        details.getByRole("combobox", {
            name: "Privacy policy accepted",
            exact: true,
        }),
    ).toHaveValue("false");

    // The existing quotation remains the canonical proposal link and sent date.
    const quotations = page.getByRole("region", {
        name: "Quotations",
        exact: true,
    });
    await quotations
        .getByLabel("Quotation number", { exact: true })
        .fill("Levi business plan");
    await quotations
        .getByLabel("Amount (USD)", { exact: true })
        .fill("2500.00");
    await quotations
        .getByRole("combobox", { name: "Quotation status", exact: true })
        .selectOption("sent");
    await quotations
        .getByLabel("Sent date", { exact: true })
        .fill("2026-11-09");
    await quotations
        .getByLabel("Document link", { exact: true })
        .fill("https://documents.example.test/proposal");
    await quotations
        .getByRole("button", { name: "Add quotation", exact: true })
        .click();
    await expect(quotations.locator("article")).toHaveCount(1);

    const contracts = page.getByRole("region", {
        name: "Signed contracts",
        exact: true,
    });
    const fileInput = contracts.getByLabel("Signed contract file", {
        exact: true,
    });
    const upload = contracts.getByRole("button", {
        name: "Upload signed contract",
        exact: true,
    });
    await expect(
        contracts.getByText("No signed contracts yet.", { exact: true }),
    ).toBeVisible();
    await fileInput.setInputFiles({
        name: "Invalid.pdf",
        mimeType: "application/pdf",
        buffer: Buffer.from("<?php echo 'Invalid fictional file';"),
    });
    await upload.click();
    await expect(contracts.getByRole("alert")).toBeVisible();
    await expect(contracts.locator("article")).toHaveCount(0);
    const originals = [
        [
            "Shira Levi signed contract.pdf",
            Buffer.from(
                "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n% Fictional Shira Levi signed contract\n%%EOF",
            ),
        ],
        [
            "Shira Levi revised signed contract.pdf",
            Buffer.from(
                "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n% Revised fictional Shira Levi signed contract\n%%EOF",
            ),
        ],
    ] as const;
    for (const [index, [filename, buffer]] of originals.entries()) {
        await fileInput.setInputFiles({
            name: filename,
            mimeType: "application/pdf",
            buffer,
        });
        await upload.click();
        await expect(contracts.locator("article")).toHaveCount(index + 1);
        await expect(fileInput).toHaveValue("");
        await page.reload();
        await expect(contracts.locator("article").first()).toContainText(
            filename,
        );
    }
    for (const [index, [filename, buffer]] of originals.entries()) {
        const link = contracts.getByRole("link", {
            name: `Download version ${index + 1}`,
            exact: true,
        });
        await expect(link).toHaveAttribute(
            "href",
            new RegExp(`^${leadPath}/signed-contracts/\\d+/download$`),
        );
        const downloadPromise = page.waitForEvent("download");
        await link.click();
        const download = await downloadPromise;
        expect(download.suggestedFilename()).toBe(filename);
        expect(await download.failure()).toBeNull();
        expect(await readFile((await download.path())!)).toEqual(buffer);
    }
    const lastField = details.locator("label").filter({
        has: page.getByRole("combobox", {
            name: "Privacy policy accepted",
            exact: true,
        }),
    });
    const fieldBounds = await lastField.boundingBox();
    const saveBounds = await details
        .getByRole("button", { name: "Save changes", exact: true })
        .boundingBox();
    expect(fieldBounds).not.toBeNull();
    expect(saveBounds).not.toBeNull();
    expect(
        saveBounds!.y - (fieldBounds!.y + fieldBounds!.height),
    ).toBeGreaterThanOrEqual(23);
    const fileBounds = await contracts.locator("label.crm-field").boundingBox();
    const uploadBounds = await upload.boundingBox();
    expect(fileBounds).not.toBeNull();
    expect(uploadBounds).not.toBeNull();
    expect(
        uploadBounds!.y - (fileBounds!.y + fileBounds!.height),
    ).toBeGreaterThanOrEqual(23);
    await mkdir(evidenceDirectory, { recursive: true, mode: 0o700 });
    await contracts.screenshot({
        path: `${evidenceDirectory}/${info.project.name}-signed-contracts.png`,
        animations: "disabled",
    });
    await details.screenshot({
        path: `${evidenceDirectory}/${info.project.name}-lead-details.png`,
        animations: "disabled",
    });
    const anonymous = await browser.newContext();
    try {
        const downloadUrl = await contracts
            .getByRole("link", { name: "Download version 1", exact: true })
            .getAttribute("href");
        const response = await anonymous.request.get(
            new URL(downloadUrl!, page.url()).href,
            { maxRedirects: 0 },
        );
        expect(response.status()).toBe(302);
        expect(response.headers().location).toMatch(/\/login$/);
    } finally {
        await anonymous.close();
    }

    await page.goto("/leads");
    await page.getByRole("button", { name: "Columns", exact: false }).click();
    const columns = page.getByRole("dialog", { name: "Columns", exact: true });
    for (const label of [...fields.map((field) => field[1]), ...extraColumns]) {
        await columns.getByLabel("Find a column", { exact: true }).fill(label);
        const choice = columns.getByRole("checkbox", {
            name: `Show ${label} column`,
            exact: true,
        });
        if (!(await choice.isChecked())) {
            await choice.check();
            await expect(columns.getByRole("status")).toHaveText(
                "Layout saved",
            );
        }
        await expect(choice).toBeChecked();
    }
    await columns.getByRole("button", { name: "Done", exact: true }).click();
    await page.getByLabel("Search leads", { exact: true }).fill(email);
    const table = page.getByRole("table", {
        name: "Leads records",
        exact: true,
    });
    const row = table.locator("tbody tr");
    await expect(row).toHaveCount(1);
    await expect(await tableCell(table, row, "Business sector")).toHaveText(
        "Business consulting",
    );
    await expect(await tableCell(table, row, "Product sold")).toHaveText(
        "Business planning package",
    );
    for (const [label, expected] of [
        ["Contract sent date", "Nov 10, 2026"],
        ["Invoice sent date", "Nov 12, 2026"],
        ["Payment received date", "Nov 14, 2026"],
        ["Process start date", "Nov 16, 2026"],
        ["Proposal sent date", "Nov 9, 2026"],
    ])
        await expect(await tableCell(table, row, label)).toHaveText(expected);
    await expect(
        await tableCell(table, row, "Company registration number"),
    ).toHaveText("000987654");
    await expect(
        await tableCell(table, row, "Privacy policy accepted"),
    ).toHaveText("No");
    await expect(
        await tableCell(table, row, "Signed contract versions"),
    ).toHaveText("2");
    await expect(
        (await tableCell(table, row, "Signed contract file")).getByRole("link"),
    ).toHaveAttribute("href", `${leadPath}#signed-contracts`);
    for (const [label, url] of [
        ["Contract link", fields[6][3]],
        ["Proposal link", "https://documents.example.test/proposal"],
    ] as const) {
        const link = (await tableCell(table, row, label)).getByRole("link");
        await expect(link).toHaveAttribute("href", url);
        await expect(link).toHaveAttribute("target", "_blank");
        await expect(link).toHaveAttribute("rel", "noopener noreferrer");
    }
    await page.reload();
    await expect(row).toHaveCount(1);
    await expect(
        await tableCell(table, row, "Company registration number"),
    ).toHaveText("000987654");
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);

    // Hebrew preview shares only the isolated local Alpha fixture database.
    await page.goto(`${hebrewOrigin}${leadPath}`);
    await expect(page.locator("html")).toHaveAttribute("lang", "he");
    await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
    const hebrewDetails = leadDetails(page, "he");
    await values(hebrewDetails, "he");
    await expect(
        hebrewDetails.getByRole("combobox", {
            name: "אישור מדיניות פרטיות",
            exact: true,
        }),
    ).toHaveValue("false");
    await expect(
        hebrewDetails.getByLabel("לינק לחוזה", { exact: true }),
    ).toHaveAttribute("dir", "ltr");
    await expect(
        page
            .getByRole("region", { name: "חוזים חתומים", exact: true })
            .locator("article"),
    ).toHaveCount(2);
    await expect(
        page.getByText("הועלה חוזה חתום", { exact: true }),
    ).toHaveCount(2);
    await expect(
        page.getByText("signed contract uploaded", { exact: true }),
    ).toHaveCount(0);
    for (const [, , label, , edited] of fields)
        await hebrewDetails.getByLabel(label, { exact: true }).fill(edited);
    await hebrewDetails
        .getByRole("combobox", { name: "אישור מדיניות פרטיות", exact: true })
        .selectOption({ label: "כן" });
    await hebrewDetails
        .getByRole("button", { name: "שמירת שינויים", exact: true })
        .click();
    await expect(
        hebrewDetails.getByRole("button", {
            name: "שמירת שינויים",
            exact: true,
        }),
    ).toBeEnabled();
    await page.reload();
    await values(hebrewDetails, "he", true);
    await expect(
        hebrewDetails.getByRole("combobox", {
            name: "אישור מדיניות פרטיות",
            exact: true,
        }),
    ).toHaveValue("true");
    await expect(hebrewDetails.locator("time")).toHaveAttribute(
        "datetime",
        createdAt!,
    );
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    await page.screenshot({
        path: `${evidenceDirectory}/${info.project.name}-lead-baseline-fields-hebrew.png`,
        fullPage: true,
        animations: "disabled",
    });

    await page.goto(`http://127.0.0.1:8091${leadPath}`);
    await values(details, "en", true);
    for (const [, label] of fields)
        await details.getByLabel(label, { exact: true }).fill("");
    await details
        .getByRole("combobox", { name: "Privacy policy accepted", exact: true })
        .selectOption({ label: "Not recorded" });
    await details
        .getByRole("button", { name: "Save changes", exact: true })
        .click();
    await expect(
        details.getByRole("button", { name: "Save changes", exact: true }),
    ).toBeEnabled();
    await page.reload();
    for (const [, label] of fields)
        await expect(details.getByLabel(label, { exact: true })).toHaveValue(
            "",
        );
    await expect(
        details
            .getByRole("combobox", {
                name: "Privacy policy accepted",
                exact: true,
            })
            .locator("option:checked"),
    ).toHaveText("Not recorded");
    await expect(details.locator("time")).toHaveAttribute(
        "datetime",
        createdAt!,
    );
    await expect(contracts.locator("article")).toHaveCount(2);

    await page.goto(companyPath);
    await expect(registration).toHaveValue("000987654");
    await registration.fill("");
    await page
        .getByRole("button", { name: "Save changes", exact: true })
        .click();
    await expect(
        page.getByRole("button", { name: "Edit company details", exact: true }),
    ).toBeVisible();
    await page.reload();
    await expect(registration).toHaveValue("");
    expect(errors).toEqual([]);
});
