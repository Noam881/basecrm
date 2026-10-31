import { test, expect } from "./fixtures";
import { signIn } from "./auth";

test("creates a complete contact profile, appends notes and edits details from its lead", async ({
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
    const officePhone =
        info.project.name === "desktop" ? "+1 202-555-0171" : "+1 202-555-0181";
    const finalPhone =
        info.project.name === "desktop" ? "+1 202-555-0172" : "+1 202-555-0182";
    const initialNote =
        "Ask about the new office and the best delivery entrance.";
    const nextNote =
        "Confirmed that WhatsApp is the easiest way to arrange a call.";
    await signIn(page, info.project.name);
    await expect(
        page.locator(".crm-sidebar").getByRole("link", {
            name: "Contacts & companies",
            exact: true,
            includeHidden: true,
        }),
    ).toHaveCount(0);
    await page.goto("/leads");
    await page
        .getByRole("link", { name: "Add new contact", exact: true })
        .click();
    await expect(page).toHaveURL(/\/people$/);
    await expect(
        page.getByRole("heading", {
            name: "Add new contact",
            exact: true,
            level: 1,
        }),
    ).toBeVisible();
    await expect(page.locator("table")).toHaveCount(0);
    await page.getByLabel("Record type").selectOption("companies");
    await expect(
        page.getByRole("heading", {
            name: "Add new company",
            exact: true,
            level: 1,
        }),
    ).toBeVisible();
    await page.getByLabel("Name", { exact: true }).fill(companyName);
    await page
        .getByLabel("Email", { exact: true })
        .fill(`company-${runId}@example.test`);
    const companyNote = "Requested a fictional company consultation.";
    await page.getByLabel("Notes", { exact: true }).fill(companyNote);
    await page
        .getByRole("button", { name: "Create company", exact: true })
        .click();
    await expect(page).toHaveURL(/\/people\/companies\/\d+$/);
    const companyUrl = page.url();
    const companyNotes = page.getByRole("region", {
        name: "Notes",
        exact: true,
    });
    await expect(
        companyNotes.getByText(companyNote, { exact: true }),
    ).toBeVisible();
    await companyNotes
        .getByLabel("Add a note", { exact: true })
        .fill("Confirmed the company contact person.");
    await companyNotes
        .getByRole("button", { name: "Add note", exact: true })
        .click();
    await expect(
        companyNotes.getByText("Confirmed the company contact person.", {
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page.getByRole("heading", { name: "Company details", exact: true }),
    ).toBeVisible();
    await page.getByLabel("Phone", { exact: true }).fill(officePhone);
    await page
        .getByRole("checkbox", { name: /Keep a separate record/ })
        .check();
    await page
        .getByRole("button", { name: "Save changes", exact: true })
        .click();
    await expect(page).toHaveURL(companyUrl);
    await expect(
        page.getByRole("button", { name: "Edit company details", exact: true }),
    ).toBeVisible();
    await page.reload();
    await expect(page.getByLabel("Phone", { exact: true })).toHaveValue(
        officePhone,
    );
    await expect(
        companyNotes.getByText(companyNote, { exact: true }),
    ).toBeVisible();
    await expect(companyNotes.locator("article")).toHaveCount(2);
    await page.goto("/leads");
    await page
        .getByRole("link", { name: "Add new contact", exact: true })
        .click();
    await expect(page).toHaveURL(/\/people$/);
    await expect(page.locator("table")).toHaveCount(0);
    await expect(page.getByLabel("Record type")).toHaveValue("contacts");
    await page.getByLabel("Name", { exact: true }).fill(contactName);
    await page.getByLabel("Email", { exact: true }).fill(email);
    await page.getByLabel("Phone", { exact: true }).fill(phone);
    await page.getByLabel("Secondary phone", { exact: true }).fill(officePhone);
    await page
        .getByRole("combobox", { name: "Secondary phone type", exact: true })
        .selectOption("office");
    await page
        .getByRole("combobox", {
            name: "Preferred contact method",
            exact: true,
        })
        .selectOption("whatsapp");
    await page.getByRole("checkbox", { name: "Mornings", exact: true }).check();
    await page.getByRole("checkbox", { name: "Evenings", exact: true }).check();
    await page.getByRole("checkbox", { name: "Monday", exact: true }).check();
    await page.getByRole("button", { name: "Weekends", exact: true }).click();
    await expect(
        page.getByRole("checkbox", { name: "Monday", exact: true }),
    ).toBeChecked();
    await expect(
        page.getByRole("checkbox", { name: "Saturday", exact: true }),
    ).toBeChecked();
    await expect(
        page.getByRole("checkbox", { name: "Sunday", exact: true }),
    ).toBeChecked();
    await page
        .getByLabel("Address", { exact: true })
        .fill("12 Olive Street, Office 4");
    await page.getByLabel("City", { exact: true }).fill("Tel Aviv");
    await page.getByLabel("Country", { exact: true }).fill("Israel");
    await page
        .getByRole("combobox", {
            name: "How did they hear about us?",
            exact: true,
        })
        .selectOption("referral");
    await page.getByLabel("Notes", { exact: true }).fill(initialNote);
    await page
        .getByRole("combobox", { name: "Company", exact: true })
        .selectOption({ label: companyName });
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBeTruthy();
    await page.screenshot({
        path: `docs/screenshots/${info.project.name}-contact-create.png`,
        fullPage: true,
        animations: "disabled",
    });
    await page
        .getByRole("button", { name: "Create contact", exact: true })
        .click();
    await expect(page).toHaveURL(/\/people\/contacts\/\d+$/);
    await expect(
        page.getByRole("heading", { name: contactName, exact: true, level: 1 }),
    ).toBeVisible();
    const contactUrl = page.url();
    const profile = page.locator("section").filter({
        has: page.getByRole("heading", {
            name: "Contact details",
            exact: true,
        }),
    });
    for (const value of [
        email,
        phone,
        officePhone,
        "Office",
        companyName,
        "WhatsApp",
        "Mornings",
        "Evenings",
        "Monday",
        "Saturday",
        "Sunday",
        "12 Olive Street, Office 4",
        "Tel Aviv",
        "Israel",
        "Referral",
    ]) {
        await expect(profile).toContainText(value);
    }
    await expect(page.getByText(initialNote, { exact: true })).toBeVisible();
    await expect(
        page.getByRole("button", { name: "Edit contact details", exact: true }),
    ).toBeVisible();
    await page.reload();
    await expect(profile).toContainText(officePhone);
    await expect(page.getByText(initialNote, { exact: true })).toBeVisible();
    await page.getByLabel("Add a note", { exact: true }).fill(nextNote);
    await page.getByRole("button", { name: "Add note", exact: true }).click();
    await expect(page.getByText(nextNote, { exact: true })).toBeVisible();
    await expect(page.getByLabel("Add a note", { exact: true })).toHaveValue(
        "",
    );
    await page.reload();
    await expect(page.getByText(initialNote, { exact: true })).toBeVisible();
    await expect(page.getByText(nextNote, { exact: true })).toBeVisible();
    await page.screenshot({
        path: `docs/screenshots/${info.project.name}-contact-profile.png`,
        fullPage: true,
        animations: "disabled",
    });
    await page
        .getByRole("button", { name: "Edit contact details", exact: true })
        .click();
    await expect(page.getByLabel("Phone", { exact: true })).toHaveValue(phone);
    await expect(
        page.getByLabel("Secondary phone", { exact: true }),
    ).toHaveValue(officePhone);
    await expect(
        page.getByRole("combobox", {
            name: "Preferred contact method",
            exact: true,
        }),
    ).toHaveValue("whatsapp");
    await expect(
        page.getByRole("checkbox", { name: "Monday", exact: true }),
    ).toBeChecked();
    await expect(
        page.getByRole("checkbox", { name: "Saturday", exact: true }),
    ).toBeChecked();
    await page.getByLabel("Secondary phone", { exact: true }).fill("");
    await page
        .getByRole("combobox", { name: "Secondary phone type", exact: true })
        .selectOption("");
    await page.getByLabel("Address", { exact: true }).fill("");
    await page.getByLabel("City", { exact: true }).fill("Jerusalem");
    await page.getByRole("checkbox", { name: "Anytime", exact: true }).check();
    await expect(
        page.getByRole("checkbox", { name: "Mornings", exact: true }),
    ).not.toBeChecked();
    await expect(
        page.getByRole("checkbox", { name: "Evenings", exact: true }),
    ).not.toBeChecked();
    await page.getByRole("button", { name: "Weekends", exact: true }).click();
    await expect(
        page.getByRole("checkbox", { name: "Monday", exact: true }),
    ).toBeChecked();
    await expect(
        page.getByRole("checkbox", { name: "Saturday", exact: true }),
    ).not.toBeChecked();
    await expect(
        page.getByRole("checkbox", { name: "Sunday", exact: true }),
    ).not.toBeChecked();
    await page
        .getByRole("button", { name: "Save changes", exact: true })
        .click();
    await expect(page).toHaveURL(contactUrl);
    await expect(
        page.getByRole("button", { name: "Edit contact details", exact: true }),
    ).toBeVisible();
    await page.reload();
    await expect(profile).toContainText("Jerusalem");
    await expect(profile).toContainText("Anytime");
    await expect(profile).toContainText("Monday");
    await expect(profile).not.toContainText(officePhone);
    await expect(profile).not.toContainText("12 Olive Street");
    await expect(page.getByText(initialNote, { exact: true })).toBeVisible();
    await expect(page.getByText(nextNote, { exact: true })).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBeTruthy();
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
    const leadUrl = page.url();
    await page.getByRole("link", { name: "Edit contact", exact: true }).click();
    await expect(page.getByLabel("Phone", { exact: true })).toHaveValue(phone);
    await expect(
        page.getByLabel("Secondary phone", { exact: true }),
    ).toHaveValue("");
    await expect(page.getByLabel("Address", { exact: true })).toHaveValue("");
    await expect(page.getByLabel("City", { exact: true })).toHaveValue(
        "Jerusalem",
    );
    await expect(
        page.getByRole("checkbox", { name: "Anytime", exact: true }),
    ).toBeChecked();
    await expect(
        page.getByRole("checkbox", { name: "Monday", exact: true }),
    ).toBeChecked();
    await page.getByLabel("Phone", { exact: true }).fill(finalPhone);
    await page.getByLabel("Email", { exact: true }).fill(`changed-${email}`);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBeTruthy();
    await page.screenshot({
        path: `docs/screenshots/${info.project.name}-contact-edit.png`,
        fullPage: true,
        animations: "disabled",
    });
    await page
        .getByRole("button", { name: "Save changes", exact: true })
        .click();
    await expect(page).toHaveURL(leadUrl);
    const customer = page
        .getByRole("heading", { name: "Customer", exact: true })
        .locator("..");
    await expect(customer).toContainText(finalPhone);
    await expect(customer).toContainText(`changed-${email}`);
    await page.goto(contactUrl);
    await expect(profile).toContainText(finalPhone);
    await expect(profile).toContainText(`changed-${email}`);
    await expect(page.getByText(nextNote, { exact: true })).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBeTruthy();
    const history = page.getByRole("region", {
        name: "Change history",
        exact: true,
    });
    await expect(history.locator("article")).toHaveCount(3);
    await expect(history.locator("article").first()).toContainText(
        "Record updated",
    );
    await expect(history.locator("article").first()).toContainText(
        "Email, Phone",
    );
    await expect(history).not.toContainText(finalPhone);
    await expect(history).not.toContainText(`changed-${email}`);
    await expect(history).not.toContainText(nextNote);
    await page.screenshot({
        path: `docs/screenshots/${info.project.name}-contact-details.png`,
        fullPage: true,
        animations: "disabled",
    });
    expect(errors).toEqual([]);
});
