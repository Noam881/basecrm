import { test, expect, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
const origin = "http://127.0.0.1:8095";
const mailpit = "http://127.0.0.1:8028";
let fixture: {
    email: string;
    contact_id: number;
    company_id: number;
    today: string;
    next: string;
};
function prepare(mode: "setup" | "cleanup", project: string) {
    return JSON.parse(
        execFileSync(
            "php",
            ["scripts/operations-browser-fixtures.php", mode, project],
            { encoding: "utf8" },
        ),
    );
}
async function signInOperations(page: Page) {
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
            "Only the fictional local Operations login link may be used.",
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
            "Operations browser fixtures require one worker and known projects.",
        );
    prepare("cleanup", info.project.name);
    fixture = prepare("setup", info.project.name);
});
test.afterEach(async ({}, info) => {
    prepare("cleanup", info.project.name);
});
test("Operations directory, exact supplier receipts, shared requests and manual routines persist independently", async ({
    page,
}, info) => {
    test.setTimeout(180000);
    page.setDefaultTimeout(10000);
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await signInOperations(page);
    await page.goto(`${origin}/operations`);
    await expect(
        page.getByRole("heading", {
            name: "Operations",
            exact: true,
        }),
    ).toBeVisible();
    await page.goto(`${origin}/operations/directory`);
    await page
        .getByText("Classify existing contact or company", { exact: true })
        .click();
    let intake = page.locator("details").filter({
        has: page.getByText("Classify existing contact or company", {
            exact: true,
        }),
    });
    await intake
        .getByRole("combobox", { name: "Contact", exact: true })
        .selectOption(String(fixture.contact_id));
    await intake
        .getByLabel("Relationship notes", { exact: true })
        .fill("Customer office access contact.");
    await intake
        .getByRole("button", { name: "Add classification", exact: true })
        .click();
    await expect(page).toHaveURL(/\/operations\/directory\/\d+$/);
    const customerId = new URL(page.url()).pathname.split("/").at(-1)!;
    await expect(
        page.getByText("+1 202-555-0101", { exact: true }),
    ).toBeVisible();
    await page
        .getByLabel("Relationship notes", { exact: true })
        .fill("Customer office access contact, verified.");
    await page
        .getByRole("button", { name: "Save classification", exact: true })
        .click();
    await page.reload();
    await expect(
        page.getByLabel("Relationship notes", { exact: true }),
    ).toHaveValue("Customer office access contact, verified.");
    await page.goto(`${origin}/operations/directory`);
    await page
        .getByText("Classify existing contact or company", { exact: true })
        .click();
    intake = page.locator("details").filter({
        has: page.getByText("Classify existing contact or company", {
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
        .getByRole("combobox", { name: "Classification", exact: true })
        .selectOption("supplier");
    await intake
        .getByRole("combobox", { name: "Main contact", exact: true })
        .selectOption(String(fixture.contact_id));
    await intake
        .getByLabel("Relationship notes", { exact: true })
        .fill("Office equipment supplier.");
    await intake
        .getByRole("button", { name: "Add classification", exact: true })
        .click();
    await expect(page).toHaveURL(/\/operations\/directory\/\d+$/);
    const supplierId = new URL(page.url()).pathname.split("/").at(-1)!;
    await expect(
        page.locator("dd").filter({ hasText: /^Yael Shalev$/ }),
    ).toBeVisible();

    await page.goto(`${origin}/operations/requests`);
    await page.getByText("Add operational request", { exact: true }).click();
    intake = page.locator("details").filter({
        has: page.getByText("Add operational request", { exact: true }),
    });
    await intake
        .getByLabel("Title", { exact: true })
        .fill("Arrange office access");
    await intake.getByLabel("Due date", { exact: true }).fill(fixture.today);
    await intake
        .getByRole("combobox", { name: "Customer (optional)", exact: true })
        .selectOption(customerId);
    await intake
        .getByLabel("Notes", { exact: true })
        .fill("Arrange keys and confirm the delivery entrance.");
    await intake
        .getByLabel("Document link", { exact: true })
        .fill("https://documents.example.test/access");
    await intake
        .getByRole("button", { name: "Create request", exact: true })
        .click();
    await expect(page).toHaveURL(/\/operations\/requests\/\d+$/);
    const requestId = new URL(page.url()).pathname.split("/").at(-1)!;
    await expect(page.getByLabel("Due date", { exact: true })).toHaveValue(
        fixture.today,
    );
    await page.getByRole("combobox", { name: /^Status/ }).selectOption("done");
    await page.getByLabel(/^Completion date/).fill(fixture.today);
    await page
        .getByRole("button", { name: "Save request", exact: true })
        .click();
    await page.reload();
    await expect(page.getByRole("combobox", { name: /^Status/ })).toHaveValue(
        "done",
    );
    await page
        .getByRole("combobox", { name: /^Status/ })
        .selectOption("in-progress");
    await page
        .getByRole("button", { name: "Save request", exact: true })
        .click();
    await expect(
        page.getByText(`Previous completion: ${fixture.today}`, {
            exact: true,
        }),
    ).toBeVisible();

    await page.goto(`${origin}/operations/orders`);
    await page.getByText("Add supplier order", { exact: true }).click();
    intake = page
        .locator("details")
        .filter({ has: page.getByText("Add supplier order", { exact: true }) });
    await intake
        .getByRole("combobox", { name: "Supplier", exact: true })
        .selectOption(supplierId);
    await intake.getByLabel("Reference", { exact: true }).fill("OPS-101");
    await intake
        .getByLabel("Description", { exact: true })
        .fill("Office equipment delivery.");
    await intake
        .getByLabel("Agreed quantity (optional)", { exact: true })
        .fill("10.125");
    await intake.getByLabel(/^Agreed total/).fill("1200.50");
    await intake
        .getByRole("combobox", { name: /^Status/ })
        .selectOption("ordered");
    await intake
        .getByLabel("Expected delivery date", { exact: true })
        .fill(fixture.today);
    await intake
        .getByRole("button", { name: "Create order", exact: true })
        .click();
    await expect(page).toHaveURL(/\/operations\/orders\/\d+$/);
    const orderId = new URL(page.url()).pathname.split("/").at(-1)!;
    await expect(page.getByLabel("Order date", { exact: true })).toHaveValue(
        fixture.today,
    );
    const receipt = page.getByRole("region", {
        name: "Record delivery receipt",
        exact: true,
    });
    await receipt
        .getByLabel("Received quantity", { exact: true })
        .fill("2.005");
    await receipt
        .getByLabel("Receipt description", { exact: true })
        .fill("First delivery checked by Yael.");
    await receipt
        .getByLabel("Items still outstanding", { exact: true })
        .fill("Remaining equipment delivery.");
    await receipt
        .getByRole("button", { name: "Record receipt", exact: true })
        .click();
    await expect(page.getByRole("combobox", { name: /^Status/ })).toHaveValue(
        "part-received",
    );
    await expect(page.getByText("8.120", { exact: true })).toBeVisible();
    await page.reload();
    await expect(page.getByLabel(/^Agreed total/)).toHaveValue("1200.50");
    await expect(
        page.getByText("First delivery checked by Yael.", { exact: true }),
    ).toBeVisible();

    await page.goto(`${origin}/operations/routines`);
    await page.getByText("Add operational routine", { exact: true }).click();
    intake = page.locator("details").filter({
        has: page.getByText("Add operational routine", { exact: true }),
    });
    await intake
        .getByLabel("Name", { exact: true })
        .fill("Weekly office readiness");
    await intake.getByLabel(/^Frequency/).fill("Weekly");
    await intake
        .getByLabel("Next due date", { exact: true })
        .fill(fixture.today);
    await intake
        .getByRole("button", { name: "Add checklist item", exact: true })
        .click();
    await intake.getByLabel(/^Checklist item 1\b/).fill("Check equipment");
    await intake
        .getByRole("button", { name: "Add checklist item", exact: true })
        .click();
    await intake.getByLabel(/^Checklist item 2\b/).fill("Confirm access");
    await intake
        .getByRole("button", { name: "Create routine", exact: true })
        .click();
    await expect(page).toHaveURL(/\/operations\/routines\/\d+$/);
    const routineId = new URL(page.url()).pathname.split("/").at(-1)!;
    const completion = page.getByRole("region", {
        name: "Complete occurrence",
        exact: true,
    });
    await completion
        .getByRole("checkbox", { name: "Check equipment", exact: true })
        .check();
    await completion
        .getByLabel(/^Next due date \(optional\)/)
        .fill(fixture.next);
    await completion
        .getByRole("button", { name: "Complete occurrence", exact: true })
        .click();
    await page.reload();
    await expect(
        page.getByText(`Due ${fixture.today} · completed ${fixture.today}`, {
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page.getByText("✓ Check equipment", { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText("○ Confirm access", { exact: true }),
    ).toBeVisible();
    await expect(page.getByLabel("Next due date", { exact: true })).toHaveValue(
        fixture.next,
    );

    const moduleEnv = {
        ...process.env,
        CRM_MANIFEST:
            process.cwd() +
            "/storage/app/private/operations-browser-manifest.json",
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
        page.getByRole("link", { name: /Arrange office access/ }),
    ).toBeVisible();
    await expect(
        page.getByRole("link", {
            name: "Supplier delivery: OPS-101",
            exact: true,
        }),
    ).toBeVisible();
    await page.goto(`${origin}/operations/orders/${orderId}`);
    await receipt
        .getByLabel("Received quantity", { exact: true })
        .fill("8.120");
    await receipt
        .getByLabel("Receipt description", { exact: true })
        .fill("Remaining equipment checked.");
    await receipt
        .getByRole("button", { name: "Record receipt", exact: true })
        .click();
    await expect(page.getByRole("combobox", { name: /^Status/ })).toHaveValue(
        "received",
    );
    await page.reload();
    await expect(
        page.getByText("First delivery checked by Yael.", { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText("Remaining equipment checked.", { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText(
            "Outstanding at this receipt: Remaining equipment delivery.",
            { exact: true },
        ),
    ).toBeVisible();
    await expect(page.getByText("0.000", { exact: true })).toBeVisible();
    await page.goto(`${origin}/operations/directory/${supplierId}`);
    await page.getByRole("link", { name: "OPS-101", exact: true }).click();
    await expect(page).toHaveURL(`${origin}/operations/orders/${orderId}`);

    await page.goto(`${origin}/operations/requests`);
    const recordLink = page
        .getByRole("table", {
            name: "Operational requests records",
            exact: true,
        })
        .getByRole("link", { name: "Arrange office access", exact: true });
    await expect(recordLink).toHaveAttribute(
        "href",
        `/operations/requests/${requestId}`,
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
        .getByRole("button", { name: "Move Title right", exact: true })
        .click();
    await columns.getByLabel("Find a column", { exact: true }).fill("Notes");
    await columns
        .getByRole("checkbox", { name: "Show Notes column", exact: true })
        .check();
    await expect(columns.getByRole("status")).toHaveText("Layout saved");
    await columns
        .getByRole("button", { name: "Close columns", exact: true })
        .click();
    await page
        .getByLabel("Search operational requests", { exact: true })
        .fill("Arrange office");
    await expect(
        page
            .getByRole("table", {
                name: "Operational requests records",
                exact: true,
            })
            .getByRole("row"),
    ).toHaveCount(2);
    await expect(page).toHaveURL(/q=Arrange/);
    await page.reload();
    await expect(
        page.getByRole("columnheader").filter({ hasText: "Notes" }),
    ).toBeVisible();
    const downloadPromise = page.waitForEvent("download");
    await page.getByRole("link", { name: "Export CSV", exact: true }).click();
    expect((await downloadPromise).suggestedFilename()).toBe(
        "operations-requests.csv",
    );
    await page.screenshot({
        path: info.outputPath("operations-advanced-table.png"),
        fullPage: true,
    });
    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth > window.innerWidth + 2,
    );
    expect(overflow).toBe(false);
    await recordLink.click();
    await expect(page).toHaveURL(`${origin}/operations/requests/${requestId}`);
    await page.screenshot({
        path: info.outputPath("operations-request.png"),
        fullPage: true,
    });
    await page.goto(`${origin}/operations/routines/${routineId}`);
    await expect(page.getByLabel("Next due date", { exact: true })).toHaveValue(
        fixture.next,
    );
    expect(errors).toEqual([]);
});
