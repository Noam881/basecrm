import { test, expect } from "./fixtures";
import { signIn } from "./auth";

test("lead stage bubbles save immediately from the profile and table without changing the creation date", async ({
    page,
    runId,
}, info) => {
    test.setTimeout(60000);
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await signIn(page, info.project.name);
    await page.goto("/people");
    const name = "Tamar Ben Ami";
    await page.getByLabel("Name", { exact: true }).fill(name);
    await page
        .getByLabel("Email", { exact: true })
        .fill(`tamar.stage.${runId}@demo.example.test`);
    await page.getByLabel("Phone", { exact: true }).fill("+1 202-555-0178");
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
    const detailsCard = page.locator("section.crm-card").filter({
        has: page.getByRole("heading", {
            name: "Lead details",
            exact: true,
        }),
    });
    const created = detailsCard.locator("time");
    await expect(created).toBeVisible();
    const createdAt = await created.getAttribute("datetime");
    expect(Number.isNaN(Date.parse(createdAt ?? ""))).toBe(false);
    await page
        .getByRole("button", {
            name: `Change stage for ${name}; current stage: New`,
            exact: true,
        })
        .press("Enter");
    const contacted = page.getByRole("menuitemradio", {
        name: "Contacted",
        exact: true,
    });
    await contacted.focus();
    const profileSave = page.waitForRequest(
        (request) =>
            request.method() === "PATCH" &&
            new URL(request.url()).pathname === leadPath,
    );
    await contacted.press("Enter");
    expect(Object.keys((await profileSave).postDataJSON()).sort()).toEqual([
        "status_id",
        "version",
    ]);
    await expect(
        page.getByRole("button", {
            name: `Change stage for ${name}; current stage: Contacted`,
            exact: true,
        }),
    ).toBeVisible();
    await page.getByLabel("Source", { exact: true }).fill("Referral");
    // Another normal session edits the same lead while this form has unsaved details.
    const otherSession = await page.context().newPage();
    await otherSession.goto(leadPath);
    await otherSession
        .getByRole("button", {
            name: `Change stage for ${name}; current stage: Contacted`,
            exact: true,
        })
        .click();
    await otherSession
        .getByRole("menuitemradio", { name: "Qualified", exact: true })
        .click();
    await expect(
        otherSession.getByRole("button", {
            name: `Change stage for ${name}; current stage: Qualified`,
            exact: true,
        }),
    ).toBeVisible();
    await otherSession.close();
    await page
        .getByRole("button", {
            name: `Change stage for ${name}; current stage: Contacted`,
            exact: true,
        })
        .click();
    const conflict = page.waitForResponse(
        (response) =>
            response.request().method() === "PATCH" &&
            new URL(response.url()).pathname === leadPath,
    );
    await page.getByRole("menuitemradio", { name: "New", exact: true }).click();
    expect((await conflict).status()).toBe(409);
    await expect(page.getByRole("alert")).toContainText(
        "changed in another session",
    );
    await page
        .getByRole("button", { name: `Refresh stage for ${name}`, exact: true })
        .click();
    await expect(
        page.getByRole("button", {
            name: `Change stage for ${name}; current stage: Qualified`,
            exact: true,
        }),
    ).toBeVisible();
    await expect(page.getByLabel("Source", { exact: true })).toHaveValue(
        "Referral",
    );
    await page
        .getByRole("button", {
            name: `Change stage for ${name}; current stage: Qualified`,
            exact: true,
        })
        .click();
    await page
        .getByRole("menuitemradio", { name: "Contacted", exact: true })
        .click();
    await expect(
        page.getByRole("button", {
            name: `Change stage for ${name}; current stage: Contacted`,
            exact: true,
        }),
    ).toBeVisible();
    await page
        .getByRole("button", { name: "Save changes", exact: true })
        .click();
    await expect(
        page.getByRole("button", { name: "Save changes", exact: true }),
    ).toBeEnabled();
    await page.reload();
    await expect(
        page.getByRole("button", {
            name: `Change stage for ${name}; current stage: Contacted`,
            exact: true,
        }),
    ).toBeVisible();
    await expect(created).toHaveAttribute("datetime", createdAt!);
    await page.goto("/leads");
    await page.getByRole("button", { name: "Columns", exact: false }).click();
    const columns = page.getByRole("dialog", {
        name: "Columns",
        exact: true,
    });
    await columns
        .getByRole("checkbox", { name: "Show Stage column", exact: true })
        .check();
    await columns
        .getByRole("checkbox", { name: "Show Created column", exact: true })
        .check();
    await columns.getByRole("button", { name: "Done", exact: true }).click();
    await page
        .getByLabel("Search leads", { exact: true })
        .fill(`tamar.stage.${runId}@demo.example.test`);
    const table = page.getByRole("table", {
        name: "Leads records",
        exact: true,
    });
    await expect(table.locator("tbody tr")).toHaveCount(1);
    const bubble = table.getByRole("button", {
        name: `Change stage for ${name}; current stage: Contacted`,
        exact: true,
    });
    await bubble.scrollIntoViewIfNeeded();
    await bubble.click();
    await expect(
        page.getByRole("menuitemradio", { name: "Contacted", exact: true }),
    ).toHaveAttribute("aria-checked", "true");
    const tableSave = page.waitForRequest(
        (request) =>
            request.method() === "PATCH" &&
            new URL(request.url()).pathname === leadPath,
    );
    await page
        .getByRole("menuitemradio", { name: "Qualified", exact: true })
        .click();
    expect(Object.keys((await tableSave).postDataJSON()).sort()).toEqual([
        "status_id",
        "version",
    ]);
    await expect(
        table.getByRole("button", {
            name: `Change stage for ${name}; current stage: Qualified`,
            exact: true,
        }),
    ).toBeVisible();
    await page.reload();
    await expect(
        table.getByRole("button", {
            name: `Change stage for ${name}; current stage: Qualified`,
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        table.getByRole("columnheader").filter({ hasText: "Created" }),
    ).toBeAttached();
    await page.goto(leadPath);
    await expect(created).toHaveAttribute("datetime", createdAt!);
    await expect(
        page.getByRole("button", {
            name: `Change stage for ${name}; current stage: Qualified`,
            exact: true,
        }),
    ).toBeVisible();
    // A stage-only refresh must not bless a stale full-details draft with a newer version.
    await detailsCard
        .getByRole("combobox", { name: "Priority", exact: true })
        .selectOption("high");
    const detailsSession = await page.context().newPage();
    await detailsSession.goto(leadPath);
    await detailsSession.getByLabel("Source", { exact: true }).fill("Website");
    await detailsSession
        .getByRole("button", { name: "Save changes", exact: true })
        .click();
    await expect(
        detailsSession.getByRole("button", {
            name: "Save changes",
            exact: true,
        }),
    ).toBeEnabled();
    await detailsSession.reload();
    await expect(
        detailsSession.getByLabel("Source", { exact: true }),
    ).toHaveValue("Website");
    await page
        .getByRole("button", {
            name: `Change stage for ${name}; current stage: Qualified`,
            exact: true,
        })
        .click();
    await page
        .getByRole("menuitemradio", { name: "Contacted", exact: true })
        .click();
    await page
        .getByRole("button", { name: `Refresh stage for ${name}`, exact: true })
        .click();
    await expect(
        page
            .getByRole("alert")
            .filter({ hasText: "Lead details changed in another session" }),
    ).toBeVisible();
    await expect(
        page.getByRole("button", { name: "Save changes", exact: true }),
    ).toBeDisabled();
    await expect(page.getByLabel("Source", { exact: true })).toHaveValue(
        "Referral",
    );
    await expect(
        detailsCard.getByRole("combobox", { name: "Priority", exact: true }),
    ).toHaveValue("high");
    await detailsSession.reload();
    await expect(
        detailsSession.getByLabel("Source", { exact: true }),
    ).toHaveValue("Website");
    await expect(
        detailsSession
            .locator("section.crm-card")
            .filter({
                has: detailsSession.getByRole("heading", {
                    name: "Lead details",
                    exact: true,
                }),
            })
            .getByRole("combobox", { name: "Priority", exact: true }),
    ).toHaveValue("normal");
    await detailsSession.close();
    // This explicit action explains that unsaved lead details will be replaced.
    await page
        .getByRole("button", { name: "Reload lead details", exact: true })
        .click();
    await expect(page.getByLabel("Source", { exact: true })).toHaveValue(
        "Website",
    );
    await expect(
        detailsCard.getByRole("combobox", { name: "Priority", exact: true }),
    ).toHaveValue("normal");
    await expect(
        page.getByRole("button", { name: "Save changes", exact: true }),
    ).toBeEnabled();
    await page.reload();
    await expect(page.getByLabel("Source", { exact: true })).toHaveValue(
        "Website",
    );
    await page.screenshot({
        path: info.outputPath("lead-stage-profile.png"),
        fullPage: true,
    });
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    // A denied update is handled by this control rather than an HTML error modal.
    await page.route(`**${leadPath}`, async (route) => {
        if (route.request().method() !== "PATCH") return route.continue();
        await route.fulfill({
            status: 403,
            contentType: "application/json",
            body: JSON.stringify({ message: "Forbidden" }),
        });
    });
    await page
        .getByRole("button", {
            name: `Change stage for ${name}; current stage: Qualified`,
            exact: true,
        })
        .click();
    await page.getByRole("menuitemradio", { name: "New", exact: true }).click();
    await expect(page.getByRole("alert")).toContainText(
        "no longer have permission",
    );
    await expect(
        page.getByRole("button", { name: /^Change stage for/ }),
    ).toHaveCount(0);
    await expect(page.locator(".crm-stage-control .crm-badge")).toHaveText(
        "Qualified",
    );
    expect(errors).toEqual([]);
});
