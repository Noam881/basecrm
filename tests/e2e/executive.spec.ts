import { test, expect, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
test.use({ actionTimeout: 7000 });
const origin = "http://127.0.0.1:8094";
const mailpit = "http://127.0.0.1:8028";
let fixture: { email: string; owner_id: number; today: string; next: string };
function prepare(mode: "setup" | "cleanup", project: string) {
    return JSON.parse(
        execFileSync(
            "php",
            ["scripts/executive-browser-fixtures.php", mode, project],
            { encoding: "utf8" },
        ),
    );
}
async function signInExecutive(page: Page) {
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
            "Only the fictional local Executive login link may be used.",
        );
    await page.goto(signed);
    await expect(page).toHaveURL(`${origin}/dashboard`);
}
async function saveRecord(page: Page, label: string) {
    const [response] = await Promise.all([
        page.waitForResponse(
            (response) =>
                response.request().method() === "PATCH" &&
                response.url().startsWith(`${origin}/executive/`),
        ),
        page.getByRole("button", { name: label, exact: true }).click(),
    ]);
    expect(response.status()).toBeLessThan(400);
}
test.beforeEach(async ({}, info) => {
    if (
        info.config.workers !== 1 ||
        !["desktop", "mobile"].includes(info.project.name)
    )
        throw new Error(
            "Executive fixtures require one worker and known projects.",
        );
    prepare("cleanup", info.project.name);
    fixture = prepare("setup", info.project.name);
});
test.afterEach(async ({}, info) => {
    prepare("cleanup", info.project.name);
});
test("Executive goals, tasks, manual routine occurrences and filtered exports persist without Sales or Finance", async ({
    page,
}, info) => {
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await signInExecutive(page);
    await expect(
        page.getByRole("heading", { name: "Your workspace", exact: true }),
    ).toBeVisible();
    await page.goto(`${origin}/executive?section=goals`);
    await page.getByRole("button", { name: "Add goal", exact: true }).click();
    await page
        .getByLabel("Goal name", { exact: true })
        .fill("Improve project delivery");
    await page.getByLabel("Target date", { exact: true }).fill(fixture.next);
    await page.getByLabel("Unit", { exact: true }).fill("completed projects");
    await page.getByLabel("Target result", { exact: true }).fill("100.1250");
    await page.getByLabel("Current result", { exact: true }).fill("10.2500");
    await page
        .getByLabel("Success criterion", { exact: true })
        .fill("Complete the agreed project checklist.");
    await page
        .getByRole("combobox", { name: "Goal status", exact: true })
        .selectOption("in-progress");
    await page
        .getByRole("button", { name: "Create goal", exact: true })
        .click();
    await expect(page).toHaveURL(/\/executive\/goals\/\d+$/);
    const goalId = new URL(page.url()).pathname.split("/").at(-1)!;
    await page.reload();
    await expect(page.getByLabel("Target result", { exact: true })).toHaveValue(
        "100.1250",
    );
    await expect(page.getByLabel("Target date", { exact: true })).toHaveValue(
        fixture.next,
    );
    await page.getByLabel("Current result", { exact: true }).fill("20.3750");
    await saveRecord(page, "Save goal");
    await expect(
        page.getByRole("heading", { name: "History", exact: true }),
    ).toBeVisible();
    await page.reload();
    await expect(
        page.getByLabel("Current result", { exact: true }),
    ).toHaveValue("20.3750");
    await expect(
        page.getByRole("combobox", { name: "Goal status", exact: true }),
    ).toHaveValue("in-progress");
    await page.screenshot({
        path: info.outputPath("executive-goal.png"),
        fullPage: true,
    });

    await page.goto(`${origin}/executive?section=tasks`);
    await page.getByRole("button", { name: "Add task", exact: true }).click();
    await page
        .getByLabel("Task name", { exact: true })
        .fill("Review project progress");
    await page
        .getByLabel("Description", { exact: true })
        .fill("Check the outstanding project actions.");
    await page
        .getByRole("combobox", { name: "Priority", exact: true })
        .selectOption("high");
    await page.getByLabel("Due date", { exact: true }).fill(fixture.today);
    await page
        .getByRole("combobox", { name: "Status", exact: true })
        .selectOption("blocked");
    await page
        .getByRole("combobox", { name: "Goal (optional)", exact: true })
        .selectOption(goalId);
    await page
        .getByRole("button", { name: "Create task", exact: true })
        .click();
    await expect(page).toHaveURL(/\/executive\/tasks\/\d+$/);
    await page.reload();
    await expect(page.getByLabel("Task name", { exact: true })).toHaveValue(
        "Review project progress",
    );
    await expect(page.getByLabel("Description", { exact: true })).toHaveValue(
        "Check the outstanding project actions.",
    );
    await expect(
        page.getByRole("combobox", { name: "Priority", exact: true }),
    ).toHaveValue("high");
    await expect(
        page.getByRole("combobox", { name: "Status", exact: true }),
    ).toHaveValue("blocked");
    await expect(
        page.getByRole("combobox", { name: "Goal (optional)", exact: true }),
    ).toHaveValue(goalId);
    await page
        .getByRole("combobox", { name: "Status", exact: true })
        .selectOption("done");
    await page.getByLabel(/^Completion date/).fill(fixture.today);
    await saveRecord(page, "Save task");
    await expect(
        page.getByRole("combobox", { name: "Status", exact: true }),
    ).toHaveValue("done");
    await page.reload();
    await expect(page.getByLabel(/^Completion date/)).toHaveValue(
        fixture.today,
    );
    await page
        .getByRole("combobox", { name: "Status", exact: true })
        .selectOption("blocked");
    await saveRecord(page, "Save task");
    await expect(
        page.getByRole("combobox", { name: "Status", exact: true }),
    ).toHaveValue("blocked");
    await page.screenshot({
        path: info.outputPath("executive-task.png"),
        fullPage: true,
    });

    await page.goto(`${origin}/executive?section=tasks`);
    await page.getByRole("button", { name: "Add task", exact: true }).click();
    await page
        .getByLabel("Task name", { exact: true })
        .fill("Prepare review agenda");
    await page
        .getByLabel("Description", { exact: true })
        .fill("Prepare the next review agenda.");
    await page
        .getByRole("combobox", { name: "Priority", exact: true })
        .selectOption("low");
    await page
        .getByRole("button", { name: "Create task", exact: true })
        .click();
    await expect(page).toHaveURL(/\/executive\/tasks\/\d+$/);

    await page.goto(`${origin}/executive?section=routines`);
    await page
        .getByRole("button", { name: "Add routine", exact: true })
        .click();
    await page
        .getByLabel("Name", { exact: true })
        .fill("Weekly management review");
    await page.getByLabel(/^Frequency/).fill("Weekly");
    await page.getByLabel("Next due date", { exact: true }).fill(fixture.today);
    await page
        .getByRole("button", { name: "Create routine", exact: true })
        .click();
    await expect(page).toHaveURL(/\/executive\/routines\/\d+$/);
    await page.reload();
    await expect(page.getByLabel("Name", { exact: true })).toHaveValue(
        "Weekly management review",
    );
    await expect(page.getByLabel(/^Frequency/)).toHaveValue("Weekly");
    await expect(page.getByLabel("Next due date", { exact: true })).toHaveValue(
        fixture.today,
    );
    await page.getByLabel(/^Next due date \(optional\)/).fill(fixture.next);
    await page
        .getByRole("button", { name: "Complete occurrence", exact: true })
        .click();
    await expect(
        page
            .locator("section")
            .filter({
                has: page.getByRole("heading", {
                    name: "Completed occurrences",
                    exact: true,
                }),
            })
            .locator("article.crm-note"),
    ).toHaveCount(1);
    await page.reload();
    await expect(page.getByLabel("Next due date", { exact: true })).toHaveValue(
        fixture.next,
    );
    await page
        .getByRole("button", { name: "Complete occurrence", exact: true })
        .click();
    await expect(
        page
            .locator("section")
            .filter({
                has: page.getByRole("heading", {
                    name: "Completed occurrences",
                    exact: true,
                }),
            })
            .locator("article.crm-note"),
    ).toHaveCount(2);
    await page.reload();
    await expect(page.getByLabel("Next due date", { exact: true })).toHaveValue(
        "",
    );
    await expect(
        page.getByText(
            "Set the next due date in the routine details to schedule another occurrence.",
            { exact: true },
        ),
    ).toBeVisible();
    await page.screenshot({
        path: info.outputPath("executive-routine.png"),
        fullPage: true,
    });

    await page.goto(`${origin}/executive?section=tasks`);
    await page
        .getByRole("combobox", { name: "Owner filter", exact: true })
        .selectOption(String(fixture.owner_id));
    await page
        .getByRole("combobox", { name: "Priority filter (tasks)", exact: true })
        .selectOption("high");
    await page.getByLabel("From date", { exact: true }).fill(fixture.today);
    await page.getByLabel("To date", { exact: true }).fill(fixture.today);
    await page
        .getByRole("combobox", { name: "View", exact: true })
        .selectOption("blocked");
    await page
        .getByRole("button", { name: "Apply filters", exact: true })
        .click();
    await expect(page).toHaveURL(/owner_id=/);
    await page
        .getByRole("textbox", { name: "Search tasks", exact: true })
        .fill("Review project");
    await page
        .getByRole("textbox", { name: "Search tasks", exact: true })
        .press("Enter");
    await expect(page).toHaveURL(/q=Review/);
    const current = new URL(page.url());
    for (const [key, value] of Object.entries({
        owner_id: String(fixture.owner_id),
        priority: "high",
        from: fixture.today,
        to: fixture.today,
        view: "blocked",
        section: "tasks",
    }))
        expect(current.searchParams.get(key)).toBe(value);
    await expect(
        page.getByRole("link", {
            name: "Review project progress",
            exact: true,
        }),
    ).toBeVisible();
    await page.getByRole("button", { name: "Filters", exact: true }).click();
    const dialog = page.getByRole("dialog", {
        name: "Advanced filters",
        exact: true,
    });
    await dialog
        .getByRole("button", { name: "Add condition", exact: true })
        .click();
    await dialog
        .getByLabel("Filter 1 field", { exact: true })
        .selectOption("status");
    await dialog
        .getByLabel("Filter 1 operator", { exact: true })
        .selectOption("eq");
    await dialog
        .getByLabel("Filter 1 value", { exact: true })
        .selectOption("blocked");
    await dialog
        .getByRole("button", { name: "Apply filters", exact: true })
        .click();
    await expect(page).toHaveURL(/filters%5B0%5D/);
    const exportHref = await page
        .getByRole("link", { name: "Export tasks (CSV)", exact: true })
        .getAttribute("href");
    const exportQuery = new URL(exportHref!, origin);
    expect(exportQuery.searchParams.get("q")).toBe("Review project");
    expect(exportQuery.searchParams.get("priority")).toBe("high");
    expect(exportQuery.searchParams.get("filters[0][id]")).toBe("status");
    expect(exportQuery.searchParams.get("filters[0][value]")).toBe("blocked");
    const response = await page.request.get(exportQuery.toString());
    expect(response.ok()).toBe(true);
    expect(await response.text()).toContain("Review project progress");
    expect(await response.text()).not.toContain("Prepare review agenda");
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
    ).toBe(true);
    await page.screenshot({
        path: info.outputPath("executive-overview.png"),
        fullPage: true,
    });
    expect(errors).toEqual([]);
});
