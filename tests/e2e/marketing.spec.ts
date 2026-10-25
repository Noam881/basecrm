import { test, expect, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
const origin = "http://127.0.0.1:8102";
const mailpit = "http://127.0.0.1:8028";
let fixture: {
    email: string;
    contact_id: number;
    lead_id: number;
    today: string;
    next: string;
    after: string;
    currency: string;
};
function prepare(mode: "setup" | "cleanup", project: string) {
    return JSON.parse(
        execFileSync(
            "php",
            ["scripts/marketing-browser-fixtures.php", mode, project],
            { encoding: "utf8" },
        ),
    );
}
async function signInMarketing(page: Page) {
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
            "Only the fictional local Marketing login link may be used.",
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
            "Marketing browser fixtures require one worker and known projects.",
        );
    prepare("cleanup", info.project.name);
    fixture = prepare("setup", info.project.name);
});
test.afterEach(async ({}, info) => {
    prepare("cleanup", info.project.name);
});
test("Marketing campaigns, weighted performance, content publication and optional Lead attribution persist", async ({
    page,
}, info) => {
    test.setTimeout(180000);
    page.setDefaultTimeout(10000);
    const errors: string[] = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await signInMarketing(page);
    await page.goto(`${origin}/marketing/campaigns`);
    await expect(
        page.getByRole("heading", { name: "Campaigns", exact: true, level: 1 }),
    ).toBeVisible();
    await page.getByText("Add campaign", { exact: true }).click();
    const create = page
        .locator("details")
        .filter({ has: page.getByText("Add campaign", { exact: true }) });
    await create
        .getByLabel("Campaign name", { exact: true })
        .fill("Carmel community campaign");
    await create
        .getByLabel("Objective", { exact: true })
        .fill("Invite enquiries from the Carmel community.");
    await create.getByLabel("Start date", { exact: true }).fill(fixture.today);
    await create.getByLabel("End date", { exact: true }).fill(fixture.after);
    await create
        .getByLabel(`Planned budget (${fixture.currency})`, { exact: true })
        .fill("900.25");
    await create
        .getByRole("combobox", { name: "Status", exact: true })
        .selectOption("active");
    await create
        .getByRole("button", { name: "Create campaign", exact: true })
        .click();
    await expect(page).toHaveURL(/\/marketing\/campaigns\/\d+$/);
    const campaignId = new URL(page.url()).pathname.split("/").at(-1)!;
    await page.reload();
    await expect(
        page.getByLabel(`Planned budget (${fixture.currency})`, {
            exact: true,
        }),
    ).toHaveValue("900.25");
    await expect(page.getByLabel("Start date", { exact: true })).toHaveValue(
        fixture.today,
    );
    await page.getByText("Add performance", { exact: true }).click();
    const add = page
        .locator("details")
        .filter({ has: page.getByText("Add performance", { exact: true }) });
    await add.getByLabel("Period start", { exact: true }).fill(fixture.today);
    await add.getByLabel("Period end", { exact: true }).fill(fixture.today);
    await add
        .getByLabel(`Actual spend (${fixture.currency})`, { exact: true })
        .fill("100.05");
    await add.getByLabel("Reported leads", { exact: true }).fill("3");
    await add
        .getByRole("button", { name: "Add performance period", exact: true })
        .click();
    await expect(
        page.getByText("Cost per lead: 33.35", { exact: false }),
    ).toBeVisible();
    await add.getByLabel("Period start", { exact: true }).fill(fixture.next);
    await add.getByLabel("Period end", { exact: true }).fill(fixture.next);
    await add
        .getByLabel(`Actual spend (${fixture.currency})`, { exact: true })
        .fill("49.95");
    await add.getByLabel("Reported leads", { exact: true }).fill("0");
    await add
        .getByRole("button", { name: "Add performance period", exact: true })
        .click();
    await expect(
        page.getByText("Cost per lead: Not available", { exact: false }),
    ).toBeVisible();
    await page.goto(`${origin}/marketing/reports`);
    await expect(
        page.getByRole("status").filter({ hasText: "Actual spend:" }),
    ).toContainText("150.00");
    await expect(
        page.getByRole("status").filter({ hasText: "Actual spend:" }),
    ).toContainText("50.00");
    await expect(page.locator(".md-table-record").first()).toBeVisible();
    await page.screenshot({
        path: info.outputPath("marketing-performance.png"),
        fullPage: true,
    });
    const csv = await page.request.get(
        `${origin}/marketing/reports/export?campaign_id=${campaignId}`,
    );
    expect(csv.status()).toBe(200);
    expect(await csv.text()).toContain("100.05");
    await page.goto(`${origin}/marketing/content`);
    await page.getByText("Plan content", { exact: true }).click();
    const plan = page
        .locator("details")
        .filter({ has: page.getByText("Plan content", { exact: true }) });
    await plan
        .getByLabel("Content title", { exact: true })
        .fill("Yael planning guide");
    await plan
        .getByLabel("Planned publication date", { exact: true })
        .fill(fixture.today);
    await plan
        .getByLabel("Draft", { exact: true })
        .fill("A practical office planning guide reviewed by Tamar.");
    await plan
        .getByRole("combobox", { name: "Status", exact: true })
        .selectOption("ready");
    await plan
        .getByRole("button", { name: "Create content", exact: true })
        .click();
    await expect(page).toHaveURL(/\/marketing\/content\/\d+$/);
    await page.reload();
    await expect(page.getByLabel("Draft", { exact: true })).toHaveValue(
        "A practical office planning guide reviewed by Tamar.",
    );
    await page
        .getByRole("combobox", { name: "Status", exact: true })
        .selectOption("published");
    await page
        .getByLabel("Publication link", { exact: true })
        .fill("https://example.test/planning-guide");
    await page
        .getByLabel("Actual publication date", { exact: true })
        .fill(fixture.today);
    await page
        .getByRole("button", { name: "Save changes", exact: true })
        .click();
    await page.reload();
    await expect(
        page.getByRole("combobox", { name: "Status", exact: true }),
    ).toHaveValue("published");
    await expect(
        page.getByLabel("Actual publication date", { exact: true }),
    ).toHaveValue(fixture.today);
    await page.screenshot({
        path: info.outputPath("marketing-published-content.png"),
        fullPage: true,
    });
    await page.goto(`${origin}/leads/${fixture.lead_id}`);
    await expect(
        page.getByRole("heading", {
            name: "Marketing attribution",
            exact: true,
        }),
    ).toBeVisible();
    const panel = page.locator("section").filter({
        has: page.getByRole("heading", {
            name: "Marketing attribution",
            exact: true,
        }),
    });
    await panel
        .getByRole("combobox", { name: "Linked CRM campaign", exact: true })
        .selectOption(campaignId);
    await panel
        .getByLabel("Ad name", { exact: true })
        .fill("Carmel office introduction");
    await panel.getByLabel("Campaign ID", { exact: true }).fill("00001234");
    await panel
        .getByRole("button", { name: "Save attribution", exact: true })
        .click();
    await expect(panel.getByRole("status")).toContainText(
        "Marketing attribution saved.",
    );
    await page.reload();
    await expect(panel.getByLabel("Campaign ID", { exact: true })).toHaveValue(
        "00001234",
    );
    await expect(
        panel.getByRole("combobox", {
            name: "Linked CRM campaign",
            exact: true,
        }),
    ).toHaveValue(campaignId);
    await page.screenshot({
        path: info.outputPath("marketing-attribution.png"),
        fullPage: true,
    });
    await page.goto(`${origin}/marketing`);
    await expect(
        page.getByRole("heading", { name: "Marketing", exact: true, level: 1 }),
    ).toBeVisible();
    await expect(
        page.getByText("150.00 " + fixture.currency, { exact: true }),
    ).toBeVisible();
    await page.screenshot({
        path: info.outputPath("marketing-overview.png"),
        fullPage: true,
    });
    expect(errors).toEqual([]);
});
