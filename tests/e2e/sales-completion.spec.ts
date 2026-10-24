import { test, expect } from "./fixtures";
import { signIn } from "./auth";

test("Sales details, manual communication and accepted quotation persist through reload", async ({
    page,
    runId,
}, info) => {
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await signIn(page, info.project.name);
    await page.goto("/people");
    await page.getByLabel("Name", { exact: true }).fill("Shira Levi");
    await page
        .getByLabel("Email", { exact: true })
        .fill(`contact-${runId}@example.test`);
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
        .getByLabel("Needs", { exact: true })
        .fill("A process review and a clear action plan");
    await page
        .getByRole("textbox", { name: /^Estimated value/ })
        .fill("1500.25");
    await page
        .getByRole("button", { name: "Create lead", exact: true })
        .click();
    await expect(page).toHaveURL(/\/leads\/\d+$/);
    await page.getByLabel("Closing date", { exact: true }).fill("2026-10-24");
    await page
        .getByRole("textbox", { name: /^Final deal value/ })
        .fill("1400.50");
    await Promise.all([
        page.waitForResponse(
            (response) =>
                response.request().method() === "PATCH" &&
                /\/leads\/\d+$/.test(new URL(response.url()).pathname),
        ),
        page.getByRole("button", { name: "Save changes", exact: true }).click(),
    ]);
    await page.reload();
    await expect(page.getByLabel("Needs", { exact: true })).toHaveValue(
        "A process review and a clear action plan",
    );
    await expect(
        page.getByRole("textbox", { name: /^Estimated value/ }),
    ).toHaveValue("1500.25");
    await expect(
        page.getByRole("textbox", { name: /^Final deal value/ }),
    ).toHaveValue("1400.50");
    await expect(page.getByLabel("Closing date", { exact: true })).toHaveValue(
        "2026-10-24",
    );
    const communications = page.getByRole("region", {
        name: "Communication history",
        exact: true,
    });
    await communications
        .getByRole("combobox", { name: "Communication type", exact: true })
        .selectOption("meeting");
    await communications
        .getByLabel("Communication date and time", { exact: true })
        .fill("2026-10-24T11:30");
    await communications
        .getByLabel("Communication summary", { exact: true })
        .fill("Reviewed the scope and agreed to send a quotation.");
    await communications
        .getByRole("button", { name: "Record communication", exact: true })
        .click();
    await expect(communications.locator("article")).toHaveCount(1);
    const quotations = page.getByRole("region", {
        name: "Quotations",
        exact: true,
    });
    await quotations
        .getByLabel("Quotation number", { exact: true })
        .fill("Q-101");
    await quotations.getByLabel(/Amount/).fill("1400.50");
    await quotations
        .getByRole("combobox", { name: "Quotation status", exact: true })
        .selectOption("sent");
    await quotations
        .getByLabel("Sent date", { exact: true })
        .fill("2026-10-24");
    await quotations
        .getByLabel("Document link", { exact: true })
        .fill("https://example.test/quotation.pdf");
    await quotations
        .getByRole("button", { name: "Add quotation", exact: true })
        .click();
    await expect(quotations.locator("article")).toHaveCount(1);
    await quotations
        .getByRole("button", { name: "Edit quotation", exact: true })
        .click();
    await quotations
        .getByRole("combobox", { name: "Quotation status", exact: true })
        .selectOption("accepted");
    await quotations
        .getByRole("button", { name: "Save quotation", exact: true })
        .click();
    await expect(quotations.locator("article")).toContainText("Accepted");
    await expect(
        quotations
            .locator("article")
            .getByRole("button", { name: "Edit quotation", exact: true }),
    ).toHaveCount(0);
    await page.reload();
    await expect(communications.locator("article")).toContainText(
        "Reviewed the scope and agreed to send a quotation.",
    );
    await expect(quotations.locator("article")).toContainText("1400.50");
    await expect(
        quotations
            .locator("article")
            .getByRole("link", { name: "Open document link", exact: true }),
    ).toHaveAttribute("href", "https://example.test/quotation.pdf");
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBeTruthy();
    await page.screenshot({
        path: info.outputPath("sales-persisted-workflow.png"),
        fullPage: true,
    });
    expect(errors).toEqual([]);
});
