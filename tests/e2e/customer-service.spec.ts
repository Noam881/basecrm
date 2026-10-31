import { test, expect, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
const origin = "http://127.0.0.1:8093";
const mailpit = "http://127.0.0.1:8028";
let fixture: { email: string; contact_id: number };
function prepare(mode: "setup" | "cleanup", project: string) {
    return JSON.parse(
        execFileSync(
            "php",
            ["scripts/service-browser-fixtures.php", mode, project],
            { encoding: "utf8" },
        ),
    );
}
async function signInService(page: Page) {
    const prior = (
        await (await page.request.get(`${mailpit}/api/v1/messages`)).json()
    ).messages as { ID: string }[];
    await page.goto(`${origin}/login`);
    await page.getByLabel("Email address", { exact: true }).fill(fixture.email);
    await page
        .getByRole("button", { name: "Email me a sign-in link", exact: true })
        .click();
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
            "Only the fictional local Service login link may be used.",
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
            "Service browser fixtures require one worker and known projects.",
        );
    prepare("cleanup", info.project.name);
    fixture = prepare("setup", info.project.name);
});
test.afterEach(async ({}, info) => {
    prepare("cleanup", info.project.name);
});
test("Service requests, interactions, reopened resolution, feedback and owner reminders persist without Operations", async ({
    page,
}, info) => {
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await signInService(page);
    await page.goto(`${origin}/service`);
    await expect(
        page.getByRole("heading", { name: "Customer service", exact: true }),
    ).toBeVisible();
    await page.getByText("Add service request", { exact: true }).click();
    const intake = page.locator("details").filter({
        has: page.getByText("Add service request", { exact: true }),
    });
    await intake
        .getByLabel("Subject", { exact: true })
        .fill("Confirm delivery details");
    await intake
        .getByLabel("Description", { exact: true })
        .fill("Please confirm the new office address.");
    await intake
        .getByRole("combobox", { name: "Contact", exact: true })
        .selectOption(String(fixture.contact_id));
    await intake
        .getByLabel("Next action", { exact: true })
        .fill("Call Yael to confirm.");
    const today = await intake
        .getByLabel("Date received", { exact: true })
        .inputValue();
    await intake.getByLabel("Due date", { exact: true }).fill(today);
    await page.screenshot({
        path: info.outputPath("service-intake.png"),
        fullPage: true,
    });
    await intake
        .getByRole("button", { name: "Create request", exact: true })
        .click();
    await expect(page).toHaveURL(/\/service\/requests\/\d+$/);
    const requestId = new URL(page.url()).pathname.split("/").at(-1)!;
    await page
        .getByRole("combobox", { name: "Interaction type", exact: true })
        .selectOption("call");
    await page
        .getByLabel("Summary", { exact: true })
        .fill("Confirmed the correct office address.");
    await page
        .getByRole("button", { name: "Add interaction", exact: true })
        .click();
    await expect(
        page.getByText("Confirmed the correct office address.", {
            exact: true,
        }),
    ).toBeVisible();
    await page
        .getByRole("combobox", { name: "Status", exact: true })
        .selectOption("closed");
    await page
        .getByLabel("Resolution", { exact: true })
        .fill("Address corrected with the customer.");
    await page.getByLabel("Resolution date", { exact: true }).fill(today);
    await page
        .getByRole("button", { name: "Save request", exact: true })
        .click();
    await expect(
        page.getByRole("heading", { name: "Latest resolution", exact: true }),
    ).toBeVisible();
    await page
        .getByRole("combobox", { name: "Status", exact: true })
        .selectOption("in-progress");
    await page
        .getByRole("button", { name: "Save request", exact: true })
        .click();
    await page.reload();
    await expect(
        page.getByRole("combobox", { name: "Status", exact: true }),
    ).toHaveValue("in-progress");
    await expect(
        page.getByText("Request reopened; previous resolutions retained.", {
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page.getByRole("heading", { name: "Latest resolution", exact: true }),
    ).toBeVisible();
    await page.goto(`${origin}/service`);
    await page.getByText("Add customer feedback", { exact: true }).click();
    const feedback = page.locator("details").filter({
        has: page.getByText("Add customer feedback", { exact: true }),
    });
    await feedback
        .getByRole("combobox", {
            name: "Related request (optional)",
            exact: true,
        })
        .selectOption(requestId);
    await feedback
        .getByRole("combobox", { name: "Rating (1–5)", exact: true })
        .selectOption("2");
    await feedback
        .getByLabel("Comments", { exact: true })
        .fill("Please check that the courier has the updated address.");
    await feedback
        .getByRole("checkbox", { name: "Follow-up action needed", exact: true })
        .check();
    await feedback.getByLabel("Follow-up date", { exact: true }).fill(today);
    await feedback
        .getByRole("button", { name: "Add feedback", exact: true })
        .click();
    await expect(page).toHaveURL(/\/service\/feedback\/\d+$/);
    await page.reload();
    await expect(page.getByLabel("Comments", { exact: true })).toHaveValue(
        "Please check that the courier has the updated address.",
    );
    execFileSync("php", ["artisan", "--env=alpha", "crm:remind"], {
        env: {
            ...process.env,
            CRM_MANIFEST:
                process.cwd() +
                "/storage/app/private/service-browser-manifest.json",
        },
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
        {
            env: {
                ...process.env,
                CRM_MANIFEST:
                    process.cwd() +
                    "/storage/app/private/service-browser-manifest.json",
            },
            stdio: "ignore",
            timeout: 15000,
        },
    );
    await page.goto(`${origin}/reminders`);
    await expect(
        page.getByRole("link", {
            name: "Service action: Confirm delivery details",
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page.getByRole("link", { name: /Customer feedback follow-up #/ }),
    ).toBeVisible();
    await page.goto(`${origin}/service`);
    await page.getByLabel("Search", { exact: true }).fill("Confirm delivery");
    await page
        .getByRole("button", { name: "Apply service filters", exact: true })
        .click();
    await expect(
        page.getByRole("link", {
            name: "Confirm delivery details",
            exact: true,
        }),
    ).toBeVisible();
    const records = page.getByRole("table", {
        name: "Service requests records",
        exact: true,
    });
    const recordLink = records.getByRole("link", {
        name: "Confirm delivery details",
        exact: true,
    });
    await expect(recordLink).toHaveAttribute(
        "href",
        `/service/requests/${requestId}`,
    );
    await expect(recordLink).toHaveClass(/md-table-record/);
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
        .getByRole("button", { name: "Move Subject right", exact: true })
        .click();
    await columns.getByLabel("Find a column", { exact: true }).fill("received");
    await columns
        .getByRole("checkbox", {
            name: "Show Date received column",
            exact: true,
        })
        .check();
    await expect(columns.getByRole("status")).toHaveText("Layout saved");
    await columns.getByRole("button", { name: "Done", exact: true }).click();
    await page.reload();
    await expect(
        records.getByRole("columnheader").filter({ hasText: "Date received" }),
    ).toBeVisible();
    await expect(records.locator("th").nth(1)).toContainText("Contact Name");
    await page
        .getByRole("textbox", { name: "Search service requests", exact: true })
        .fill("Confirm delivery");
    await expect(records.locator("tbody tr")).toHaveCount(1);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBeTruthy();
    await page.screenshot({
        path: info.outputPath("service-persisted-workflow.png"),
        fullPage: true,
    });
    expect(errors).toEqual([]);
});
