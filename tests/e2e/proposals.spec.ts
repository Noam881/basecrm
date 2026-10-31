import { test, expect } from "./fixtures";
import { signIn } from "./auth";
import { readFile } from "node:fs/promises";

test("private proposal versions persist on the lead and table and download their original bytes", async ({
    page,
    runId,
}, info) => {
    test.setTimeout(60000);
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await signIn(page, info.project.name);
    const name = "Shira Levi";
    const email = `contact-${runId}@example.test`;
    await page.goto("/people");
    await page.getByLabel("Name", { exact: true }).fill(name);
    await page.getByLabel("Email", { exact: true }).fill(email);
    await page.getByLabel("Phone", { exact: true }).fill("+1 202-555-0184");
    await page
        .getByRole("checkbox", {
            name: "Keep a separate record if a duplicate is found",
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
        .getByRole("button", { name: "Create lead", exact: true })
        .click();
    await expect(page).toHaveURL(/\/leads\/\d+$/);
    const leadPath = new URL(page.url()).pathname;
    const proposals = page.getByRole("region", {
        name: "Proposals",
        exact: true,
    });
    const fileInput = proposals.getByLabel("Proposal file", { exact: true });
    const upload = proposals.getByRole("button", {
        name: "Upload proposal",
        exact: true,
    });
    await expect(
        proposals.getByText("No proposal files yet.", { exact: true }),
    ).toBeVisible();
    // The server checks actual bytes even when a browser reports a PDF MIME type.
    await fileInput.setInputFiles({
        name: "Invalid.pdf",
        mimeType: "application/pdf",
        buffer: Buffer.from("<?php echo 'Fictional invalid document';"),
    });
    await upload.click();
    await expect(proposals.getByRole("alert")).toBeVisible();
    await expect(proposals.locator("article")).toHaveCount(0);
    const original = Buffer.from(
        "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n% Fictional proposal for Shira Levi\n%%EOF",
    );
    await fileInput.setInputFiles({
        name: "Shira Levi proposal.pdf",
        mimeType: "application/pdf",
        buffer: original,
    });
    await upload.click();
    await expect(proposals.locator("article")).toHaveCount(1);
    await expect(
        proposals.getByText("Shira Levi proposal.pdf", { exact: true }),
    ).toBeVisible();
    await expect(fileInput).toHaveValue("");
    await page.reload();
    await expect(proposals.locator("article")).toHaveCount(1);
    const second = Buffer.from(
        "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n% Revised fictional proposal for Shira Levi\n%%EOF",
    );
    await fileInput.setInputFiles({
        name: "Shira Levi revised proposal.pdf",
        mimeType: "application/pdf",
        buffer: second,
    });
    await upload.click();
    await expect(proposals.locator("article")).toHaveCount(2);
    await expect(proposals.locator("article").first()).toContainText(
        "Shira Levi revised proposal.pdf",
    );
    await expect(proposals.locator("article").first()).toContainText(
        "Version 2",
    );
    for (const [version, filename, bytes] of [
        [1, "Shira Levi proposal.pdf", original],
        [2, "Shira Levi revised proposal.pdf", second],
    ] as const) {
        const downloadPromise = page.waitForEvent("download");
        await proposals
            .getByRole("link", {
                name: `Download version ${version}`,
                exact: true,
            })
            .click();
        const download = await downloadPromise;
        expect(download.suggestedFilename()).toBe(filename);
        expect(await download.failure()).toBeNull();
        expect(await readFile((await download.path())!)).toEqual(bytes);
    }
    await page.reload();
    await expect(proposals.locator("article")).toHaveCount(2);
    await expect(proposals.getByRole("alert")).toHaveCount(0);
    await page.screenshot({
        path: info.outputPath("proposal-versions-profile.png"),
        fullPage: true,
    });
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    await page.goto("/leads");
    await page.getByRole("button", { name: "Columns", exact: false }).click();
    const columns = page.getByRole("dialog", { name: "Columns", exact: true });
    await columns
        .getByRole("checkbox", {
            name: "Show Proposal file column",
            exact: true,
        })
        .check();
    await columns.getByRole("button", { name: "Done", exact: true }).click();
    await page.getByLabel("Search leads", { exact: true }).fill(email);
    const table = page.getByRole("table", {
        name: "Leads records",
        exact: true,
    });
    await expect(table.locator("tbody tr")).toHaveCount(1);
    await expect(
        table.getByRole("cell", {
            name: "Shira Levi revised proposal.pdf",
            exact: true,
        }),
    ).toBeAttached();
    await page.reload();
    await expect(
        table.getByRole("cell", {
            name: "Shira Levi revised proposal.pdf",
            exact: true,
        }),
    ).toBeAttached();
    await page.goto(leadPath);
    await expect(proposals.locator("article")).toHaveCount(2);
    expect(errors).toEqual([]);
});
