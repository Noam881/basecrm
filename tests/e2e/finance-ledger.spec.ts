import { test, expect, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
const origin = "http://127.0.0.1:8098";
const mailpit = "http://127.0.0.1:8028";
let fixture: {
    email: string;
    contact_id: number;
    company_id: number;
    today: string;
    next: string;
    currency: string;
};
function prepare(mode: "setup" | "cleanup", project: string) {
    return JSON.parse(
        execFileSync(
            "php",
            ["scripts/finance-ledger-browser-fixtures.php", mode, project],
            { encoding: "utf8" },
        ),
    );
}
async function signInFinance(page: Page) {
    const prior = (
        await (await page.request.get(`${mailpit}/api/v1/messages`)).json()
    ).messages as { ID: string }[];
    for (let attempt = 0; attempt < 2; attempt++) {
        await page.goto(`${origin}/login`);
        await page
            .getByLabel("Email address", { exact: true })
            .fill(fixture.email);
        const responsePromise = page.waitForResponse(
            (response) =>
                response.request().method() === "POST" &&
                response.url() === `${origin}/login`,
        );
        await page
            .getByRole("button", {
                name: "Email me a sign-in link",
                exact: true,
            })
            .click();
        const response = await responsePromise;
        if (response.status() !== 429) break;
        const retryAfter = Number(response.headers()["retry-after"]);
        if (
            attempt ||
            !Number.isInteger(retryAfter) ||
            retryAfter < 1 ||
            retryAfter > 60
        )
            throw new Error(
                "Local sign-in rate limit did not provide a bounded retry.",
            );
        // Respect the shared localhost limiter; never flush another account's limits.
        await new Promise((resolve) =>
            setTimeout(resolve, (retryAfter + 1) * 1000),
        );
    }
    await expect(page.getByRole("status")).toContainText(
        "a sign-in link is on its way",
    );
    execFileSync(
        "php",
        [
            "artisan",
            "--env=alpha",
            "queue:work",
            "database",
            "--stop-when-empty",
            "--tries=1",
        ],
        { stdio: "ignore", timeout: 15000 },
    );
    let id: string | undefined;
    await expect
        .poll(async () => {
            const inbox = await (
                await page.request.get(`${mailpit}/api/v1/messages`)
            ).json();
            id = inbox.messages.find(
                (message: {
                    ID: string;
                    To: { Address: string }[];
                    Subject: string;
                }) =>
                    !prior.some((old) => old.ID === message.ID) &&
                    message.To.some((to) => to.Address === fixture.email) &&
                    message.Subject.endsWith("sign-in link"),
            )?.ID;
            return !!id;
        })
        .toBe(true);
    const message = (await (
        await page.request.get(`${mailpit}/api/v1/message/${id}`)
    ).json()) as { HTML: string; Text: string };
    const signed = await page.evaluate(({ HTML, Text }) => {
        const document = new DOMParser().parseFromString(HTML, "text/html");
        return (
            Array.from(document.querySelectorAll("a[href]"))
                .map((a) => a.getAttribute("href") ?? "")
                .find((href) =>
                    /\/magic-login\/[A-Za-z0-9]{64}\?/.test(href),
                ) ??
            Text.match(/https?:\/\/[^\s<>"']+/g)?.find((href) =>
                /\/magic-login\/[A-Za-z0-9]{64}\?/.test(href),
            ) ??
            ""
        );
    }, message);
    const url = new URL(signed);
    if (
        url.origin !== origin ||
        !/^\/magic-login\/[A-Za-z0-9]{64}$/.test(url.pathname) ||
        !url.searchParams.has("signature")
    )
        throw new Error(
            "Only the fictional local Finance login link may be used.",
        );
    await page.goto(signed);
    await expect(page).toHaveURL(`${origin}/dashboard`);
}
test.beforeEach(async ({}, info) => {
    if (
        info.config.workers !== 1 ||
        !["desktop", "mobile"].includes(info.project.name)
    )
        throw new Error(
            "Finance browser fixtures require one worker and known projects.",
        );
    prepare("cleanup", info.project.name);
    fixture = prepare("setup", info.project.name);
});
test.afterEach(async ({}, info) => {
    prepare("cleanup", info.project.name);
});
test("Finance classifications, exact partial payments, independent supplier expenses and date-based reports persist", async ({
    page,
}, info) => {
    test.setTimeout(180000);
    page.setDefaultTimeout(10000);
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await signInFinance(page);
    await page.goto(`${origin}/finance/collections`);
    await expect(
        page.getByRole("heading", {
            name: "Customer charges",
            exact: true,
            level: 1,
        }),
    ).toBeVisible();
    await page
        .getByText("Classify an existing contact or company", { exact: true })
        .click();
    let intake = page.locator("details").filter({
        has: page.getByText("Classify an existing contact or company", {
            exact: true,
        }),
    });
    await intake
        .getByRole("combobox", { name: "Contact", exact: true })
        .selectOption(String(fixture.contact_id));
    await intake
        .getByLabel("Relationship notes", { exact: true })
        .fill("Customer payment contact.");
    await intake
        .getByRole("button", {
            name: "Add customer classification",
            exact: true,
        })
        .click();
    await page.getByText("Add customer charge", { exact: true }).click();
    intake = page.locator("details").filter({
        has: page.getByText("Add customer charge", { exact: true }),
    });
    await intake
        .getByRole("combobox", { name: "Customer", exact: true })
        .selectOption({ label: "Yael Shalev" });
    await intake.getByLabel("Reference", { exact: true }).fill("CH-101");
    await intake
        .getByLabel("Description", { exact: true })
        .fill("Agreed office consultation.");
    await intake.getByLabel(/^Total payable/).fill("1000.10");
    await intake.getByLabel("Due date", { exact: true }).fill(fixture.today);
    await intake
        .getByLabel("Next collection action", { exact: true })
        .fill("Confirm the remaining payment date.");
    await intake
        .getByLabel("Next action date", { exact: true })
        .fill(fixture.today);
    await intake
        .getByLabel("Supporting document link", { exact: true })
        .fill("https://documents.example.test/charge");
    await intake
        .getByRole("button", { name: "Create charge", exact: true })
        .click();
    await expect(page).toHaveURL(/\/finance\/collections\/\d+$/);
    const chargeId = new URL(page.url()).pathname.split("/").at(-1)!;
    await expect(page.getByLabel("Due date", { exact: true })).toHaveValue(
        fixture.today,
    );
    await expect(page.locator(".crm-heading .crm-badge")).toHaveText("Unpaid");
    let payment = page.getByRole("region", {
        name: "Record payment",
        exact: true,
    });
    await payment.getByLabel(/^Payment amount/).fill("333.33");
    await payment
        .getByLabel("Payment reference (optional)", { exact: true })
        .fill("BANK-101");
    await payment
        .getByLabel("Payment notes", { exact: true })
        .fill("First received payment checked manually.");
    await payment
        .getByRole("button", { name: "Record payment", exact: true })
        .click();
    await expect(page.locator(".crm-heading .crm-badge")).toHaveText(
        "Part paid",
    );
    await page.reload();
    const balance = page
        .locator(".crm-detail-item")
        .filter({ has: page.getByText("Remaining balance", { exact: true }) });
    await expect(balance).toContainText(`666.77 ${fixture.currency}`);
    await expect(page.getByLabel(/^Total payable/)).toHaveValue("1000.10");
    await expect(page.getByLabel(/^Total payable/)).toHaveAttribute(
        "readonly",
        "",
    );
    await page.getByLabel("Due date", { exact: true }).fill(fixture.next);
    await page
        .getByRole("button", { name: "Save record", exact: true })
        .click();
    await page.reload();
    await expect(page.getByLabel("Due date", { exact: true })).toHaveValue(
        fixture.next,
    );

    await page.goto(`${origin}/finance/expenses`);
    await page
        .getByText("Classify an existing contact or company", { exact: true })
        .click();
    intake = page.locator("details").filter({
        has: page.getByText("Classify an existing contact or company", {
            exact: true,
        }),
    });
    await intake
        .getByRole("combobox", { name: "Identity type", exact: true })
        .selectOption("company");
    await intake
        .getByRole("combobox", { name: "Company", exact: true })
        .selectOption(String(fixture.company_id));
    await intake
        .getByRole("combobox", { name: "Main contact", exact: true })
        .selectOption(String(fixture.contact_id));
    await intake
        .getByRole("button", {
            name: "Add supplier classification",
            exact: true,
        })
        .click();
    await page.getByText("Add supplier expense", { exact: true }).click();
    intake = page.locator("details").filter({
        has: page.getByText("Add supplier expense", { exact: true }),
    });
    await intake
        .getByRole("combobox", { name: "Supplier", exact: true })
        .selectOption({ label: "Carmel Supply" });
    await intake
        .getByLabel("Document reference", { exact: true })
        .fill("EXP-101");
    await intake
        .getByRole("combobox", { name: "Category", exact: true })
        .fill("Office supplies");
    await intake
        .getByLabel("Description", { exact: true })
        .fill("Agreed equipment supplies expense.");
    await intake.getByLabel(/^Total payable/).fill("250.00");
    await intake.getByLabel("Due date", { exact: true }).fill(fixture.today);
    await intake
        .getByLabel("Invoice or receipt link", { exact: true })
        .fill("https://documents.example.test/supplier-invoice");
    await intake
        .getByRole("button", { name: "Create expense", exact: true })
        .click();
    await expect(page).toHaveURL(/\/finance\/expenses\/\d+$/);
    const expenseId = new URL(page.url()).pathname.split("/").at(-1)!;
    payment = page.getByRole("region", { name: "Record payment", exact: true });
    await payment.getByLabel(/^Payment amount/).fill("50.10");
    await payment
        .getByLabel("Payment notes", { exact: true })
        .fill("Partial supplier payment recorded manually.");
    await payment
        .getByRole("button", { name: "Record payment", exact: true })
        .click();
    await page.reload();
    await expect(page.locator(".crm-heading .crm-badge")).toHaveText(
        "Part paid",
    );
    await expect(balance).toContainText(`199.90 ${fixture.currency}`);

    const moduleEnv = {
        ...process.env,
        CRM_MANIFEST:
            process.cwd() +
            "/storage/app/private/finance-ledger-browser-manifest.json",
    };
    execFileSync("php", ["artisan", "--env=alpha", "crm:remind"], {
        env: moduleEnv,
        stdio: "ignore",
    });
    execFileSync(
        "php",
        [
            "artisan",
            "--env=alpha",
            "queue:work",
            "database",
            "--stop-when-empty",
            "--tries=1",
        ],
        { env: moduleEnv, stdio: "ignore", timeout: 15000 },
    );
    await page.goto(`${origin}/reminders`);
    await expect(
        page.getByRole("link", {
            name: "Collection action: CH-101",
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page.getByRole("link", {
            name: "Supplier payment due: EXP-101",
            exact: true,
        }),
    ).toBeVisible();
    await page.goto(`${origin}/finance/collections/${chargeId}`);
    payment = page.getByRole("region", { name: "Record payment", exact: true });
    await payment.getByLabel(/^Payment amount/).fill("666.78");
    await payment
        .getByRole("button", { name: "Record payment", exact: true })
        .click();
    await expect(payment.getByRole("alert")).toContainText(
        "exceeds the outstanding balance",
    );
    await payment.getByLabel(/^Payment amount/).fill("666.77");
    await payment
        .getByLabel("Payment notes", { exact: true })
        .fill("Final received payment checked manually.");
    await payment
        .getByRole("button", { name: "Record payment", exact: true })
        .click();
    await expect(page.locator(".crm-heading .crm-badge")).toHaveText("Paid");
    await page.reload();
    await expect(balance).toContainText(`0.00 ${fixture.currency}`);
    await expect(
        page.getByText("First received payment checked manually.", {
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page.getByText("Final received payment checked manually.", {
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page.getByRole("region", { name: "Record payment", exact: true }),
    ).toHaveCount(0);
    await page.screenshot({
        path: info.outputPath("finance-paid-charge.png"),
        fullPage: true,
    });
    await page.goto(`${origin}/finance`);
    const receipts = page.locator("section").filter({
        has: page.getByRole("heading", {
            name: "Collections",
            exact: true,
        }),
    });
    await expect(receipts).toContainText(`1000.10 ${fixture.currency}`);
    const expenses = page.locator("section").filter({
        has: page.getByRole("heading", {
            name: "Supplier expenses",
            exact: true,
        }),
    });
    await expect(expenses).toContainText(`199.90 ${fixture.currency}`);
    await expect(expenses).toContainText(`50.10 ${fixture.currency}`);
    await page.getByLabel("Period from", { exact: true }).fill(fixture.today);
    await page.getByLabel("Period to", { exact: true }).fill(fixture.today);
    await page
        .getByRole("button", { name: "Apply period", exact: true })
        .click();
    await expect(receipts).toContainText(`1000.10 ${fixture.currency}`);
    await expect(expenses).toContainText(`199.90 ${fixture.currency}`);
    await page.screenshot({
        path: info.outputPath("finance-overview.png"),
        fullPage: true,
    });

    await page.goto(`${origin}/finance/collections`);
    const table = page.getByRole("table", {
        name: "Customer charges records",
        exact: true,
    });
    const recordLink = table.getByRole("link", { name: "CH-101", exact: true });
    await expect(recordLink).toHaveAttribute(
        "href",
        `/finance/collections/${chargeId}`,
    );
    await recordLink.hover();
    await expect(recordLink.locator(":scope > .crm-state-layer")).toHaveCount(
        0,
    );
    await page.getByRole("button", { name: "Columns", exact: false }).click();
    const columns = page.getByRole("dialog");
    await columns
        .getByRole("button", { name: "Reset layout", exact: true })
        .click();
    await columns
        .getByRole("button", { name: "Move Reference right", exact: true })
        .click();
    await columns
        .getByLabel("Find a column", { exact: true })
        .fill("Next collection action");
    await columns
        .getByRole("checkbox", {
            name: "Show Next collection action column",
            exact: true,
        })
        .check();
    await expect(columns.getByRole("status")).toHaveText("Layout saved");
    await columns.getByRole("button", { name: "Done", exact: true }).click();
    await page.reload();
    await expect(
        table
            .getByRole("columnheader")
            .filter({ hasText: "Next collection action" }),
    ).toBeVisible();
    await page
        .getByLabel("Search customer charges", { exact: true })
        .fill("CH-101");
    await expect(page).toHaveURL(/q=CH-101/);
    const download = page.waitForEvent("download");
    await page.getByRole("link", { name: "Export CSV", exact: true }).click();
    expect((await download).suggestedFilename()).toBe("collections.csv");
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 2,
        ),
    ).toBe(true);
    await page.screenshot({
        path: info.outputPath("finance-advanced-table.png"),
        fullPage: true,
    });
    await page.goto(`${origin}/finance/expenses/${expenseId}`);
    await expect(balance).toContainText(`199.90 ${fixture.currency}`);
    expect(errors).toEqual([]);
});
