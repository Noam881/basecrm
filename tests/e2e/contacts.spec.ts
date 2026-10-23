import { test, expect } from "./fixtures";
import { signIn } from "./auth";

test("edits contact details from the customer table and lead customer card", async ({
    page,
    runId,
}, info) => {
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    const companyName = "Levi Solutions";
    const contactName = "Shira Levi";
    const email = `contact-${runId}@example.test`;
    const phone =
        info.project.name === "desktop" ? "+1 202-555-0170" : "+1 202-555-0180";
    const companyPhone =
        info.project.name === "desktop" ? "+1 202-555-0171" : "+1 202-555-0181";
    const finalPhone =
        info.project.name === "desktop" ? "+1 202-555-0172" : "+1 202-555-0182";
    await signIn(page, info.project.name);
    await page.goto("/people");
    await page.getByLabel("Record type").selectOption("companies");
    await page.getByLabel("Name", { exact: true }).fill(companyName);
    await page
        .getByLabel("Email", { exact: true })
        .fill(`company-${runId}@example.test`);
    await page
        .getByRole("button", { name: "Create customer", exact: true })
        .click();
    await expect(page).toHaveURL(/people\?table=companies/);
    await page
        .getByRole("table", { name: "Companies records", exact: true })
        .getByRole("link", { name: companyName, exact: true })
        .click();
    await expect(
        page.getByRole("heading", { name: "Company details", exact: true }),
    ).toBeVisible();
    await page
        .getByLabel("Email", { exact: true })
        .fill(`company-${runId}@example.test`);
    await page.getByLabel("Phone", { exact: true }).fill(companyPhone);
    await page
        .getByRole("checkbox", { name: /Keep a separate record/ })
        .check();
    await page
        .getByRole("button", { name: "Save changes", exact: true })
        .click();
    await expect(page).toHaveURL(/people\?table=companies/);
    await expect(
        page
            .getByRole("table", { name: "Companies records", exact: true })
            .locator("tbody tr")
            .filter({ hasText: companyName }),
    ).toContainText(`company-${runId}@example.test`);
    await page.getByLabel("Record type").selectOption("contacts");
    await page.getByLabel("Name", { exact: true }).fill(contactName);
    await page.getByLabel("Email", { exact: true }).fill(email);
    await page
        .getByRole("combobox", { name: "Company", exact: true })
        .selectOption({ label: companyName });
    await page
        .getByRole("button", { name: "Create customer", exact: true })
        .click();
    await expect(page).toHaveURL(/people\?table=contacts/);
    await page
        .getByRole("textbox", { name: "Search contacts", exact: true })
        .fill(email);
    const records = page.getByRole("table", {
        name: "Contacts records",
        exact: true,
    });
    await expect(records.locator("tbody tr")).toHaveCount(1);
    await records.getByRole("link", { name: contactName, exact: true }).click();
    await expect(
        page.getByRole("heading", { name: "Contact details", exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole("combobox", { name: "Company", exact: true }),
    ).toHaveValue(/\d+/);
    await expect(page.getByLabel("Phone", { exact: true })).toHaveValue("");
    const changedPhone = phone;
    await page.getByLabel("Phone", { exact: true }).fill(changedPhone);
    await page
        .getByRole("checkbox", { name: /Keep a separate record/ })
        .check();
    await page
        .getByRole("button", { name: "Save changes", exact: true })
        .click();
    await expect(page).toHaveURL(/people\?table=contacts/);
    await page
        .getByRole("textbox", { name: "Search contacts", exact: true })
        .fill(changedPhone);
    const savedContact = page
        .getByRole("table", { name: "Contacts records", exact: true })
        .locator("tbody tr")
        .filter({ hasText: contactName });
    await expect(savedContact).toHaveCount(1);
    await expect(savedContact).toContainText(changedPhone);
    await page.goto("/leads");
    await page
        .getByRole("button", { name: "+ Create lead", exact: true })
        .click();
    await page
        .getByRole("combobox", { name: "Contact", exact: true })
        .selectOption({ label: contactName });
    await page
        .getByRole("button", { name: "Create lead", exact: true })
        .click();
    await expect(page).toHaveURL(/leads\/\d+$/);
    await expect(
        page.getByRole("heading", { name: contactName, exact: true }),
    ).toBeVisible();
    const leadUrl = page.url();
    await page.getByRole("link", { name: "Edit contact", exact: true }).click();
    await expect(page.getByLabel("Phone", { exact: true })).toHaveValue(
        changedPhone,
    );
    await page.getByLabel("Phone", { exact: true }).fill(finalPhone);
    await page.getByLabel("Email", { exact: true }).fill(`changed-${email}`);
    await page
        .getByRole("checkbox", { name: /Keep a separate record/ })
        .check();
    await page
        .getByRole("button", { name: "Save changes", exact: true })
        .click();
    await expect(page).toHaveURL(leadUrl);
    const customer = page
        .getByRole("heading", { name: "Customer", exact: true })
        .locator("..");
    await expect(customer).toContainText(finalPhone);
    await expect(customer).toContainText(`changed-${email}`);
    await page.getByRole("link", { name: "Edit contact", exact: true }).click();
    await expect(page.getByLabel("Phone", { exact: true })).toHaveValue(
        finalPhone,
    );
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBeTruthy();
    await page.screenshot({
        path: `docs/screenshots/${info.project.name}-contact-details.png`,
        fullPage: true,
    });
    expect(errors).toEqual([]);
});
