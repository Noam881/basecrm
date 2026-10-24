import { readFile } from "node:fs/promises";
import { test, expect } from "./fixtures";
import { signIn } from "./auth";

test("CSV preview, confirmed contact import, profile reload and scoped export work", async ({
    page,
    runId,
}, info) => {
    const installation = info.project.name === "desktop" ? "alpha" : "beta";
    const origin = `http://127.0.0.1:${installation === "alpha" ? 8091 : 8092}`;
    const email = `contact-${runId}@example.test`;
    const note = "Requested a fictional consultation on Sunday morning.";
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await signIn(page, info.project.name, installation);
    await page.goto(`${origin}/spreadsheets`);
    await expect(
        page.getByRole("heading", { name: "Import and export", exact: true }),
    ).toBeVisible();
    const file = [
        "name,email,phone,preferred_contact_method,preferred_contact_times,preferred_contact_days,address,city,country,source,notes",
        `Shira Levi,${email},+1 202-555-0181,email,mornings,sunday,18 Fictional Lane,Tel Aviv,Israel,referral,${note}`,
        "",
    ].join("\r\n");
    await page.getByLabel("CSV file", { exact: true }).setInputFiles({
        name: "fictional-contact.csv",
        mimeType: "text/csv",
        buffer: Buffer.from(file, "utf8"),
    });
    await page
        .getByRole("button", { name: "Preview import", exact: true })
        .click();
    await expect(
        page.getByRole("heading", { name: "Preview: 1 record", exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole("region", { name: "CSV import preview", exact: true }),
    ).toContainText(email);
    const before = await page.request.get(
        `${origin}/spreadsheets/export/contacts`,
    );
    expect(before.ok()).toBeTruthy();
    expect(await before.text()).not.toContain(email);
    const confirmButton = page.getByRole("button", {
        name: "Import 1 record",
        exact: true,
    });
    await expect(confirmButton).toBeEnabled();
    await confirmButton.focus();
    await expect(confirmButton).toBeFocused();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBeTruthy();
    await page.screenshot({
        path: info.outputPath("csv-preview.png"),
        fullPage: true,
    });
    const [confirmation] = await Promise.all([
        page.waitForResponse(
            (response) =>
                new URL(response.url()).pathname === "/spreadsheets/confirm" &&
                response.request().method() === "POST",
        ),
        confirmButton.click(),
    ]);
    expect(confirmation.ok()).toBeTruthy();
    const result = (await confirmation.json()) as {
        count: number;
        ids: number[];
    };
    expect(result.count).toBe(1);
    expect(result.ids).toHaveLength(1);
    await expect(page.getByRole("status")).toHaveText("1 contact imported.");
    const [download] = await Promise.all([
        page.waitForEvent("download"),
        page
            .getByRole("link", { name: "Download contacts CSV", exact: true })
            .click(),
    ]);
    expect(download.suggestedFilename()).toMatch(
        /^contacts-\d{4}-\d{2}-\d{2}\.csv$/,
    );
    const exportPath = info.outputPath("fictional-contact-export.csv");
    await download.saveAs(exportPath);
    const exported = await readFile(exportPath, "utf8");
    expect(exported).toContain(email);
    expect(exported).toContain("'+1 202-555-0181");
    expect(exported).toContain("Shira Levi");
    await page.goto(`${origin}/people/contacts/${result.ids[0]}`);
    await page.reload();
    await expect(
        page.getByRole("heading", { name: "Shira Levi", exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole("link", { name: email, exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole("link", { name: "+1 202-555-0181", exact: true }),
    ).toBeVisible();
    await expect(page.getByText(note, { exact: true })).toBeVisible();
    await expect(page.getByText("Tel Aviv", { exact: true })).toBeVisible();
    await expect(page.getByText("Mornings", { exact: true })).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBeTruthy();
    await page.screenshot({
        path: info.outputPath("csv-import-persisted-profile.png"),
        fullPage: true,
    });
    expect(errors).toEqual([]);
});
