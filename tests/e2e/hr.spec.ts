import { test, expect, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
test.use({ actionTimeout: 7000 });
const origin = "http://127.0.0.1:8099";
const mailpit = "http://127.0.0.1:8028";
let fixture: {
    email: string;
    owner_id: number;
    employee_id: number;
    manager_id: number;
    today: string;
};
function prepare(mode: "setup" | "cleanup", project: string) {
    return JSON.parse(
        execFileSync(
            "php",
            ["scripts/hr-browser-fixtures.php", mode, project],
            { encoding: "utf8" },
        ),
    );
}
async function signInHr(page: Page) {
    const prior = (
        await (await page.request.get(`${mailpit}/api/v1/messages`)).json()
    ).messages as { ID: string }[];
    for (let attempt = 0; attempt < 2; attempt++) {
        await page.goto(`${origin}/login`);
        await page
            .getByLabel("Email address", { exact: true })
            .fill(fixture.email);
        const [response] = await Promise.all([
            page.waitForResponse(
                (response) =>
                    response.request().method() === "POST" &&
                    response.url() === `${origin}/login`,
            ),
            page
                .getByRole("button", {
                    name: "Email me a sign-in link",
                    exact: true,
                })
                .click(),
        ]);
        if (response.status() === 429) {
            const seconds = Number(response.headers()["retry-after"] ?? 60);
            if (
                attempt ||
                !Number.isFinite(seconds) ||
                seconds < 0 ||
                seconds > 60
            )
                throw new Error(
                    "Local sign-in limit did not clear within the bounded retry window.",
                );
            await page.waitForTimeout((seconds + 0.1) * 1000);
            continue;
        }
        expect(response.status()).toBeLessThan(400);
        await expect(page.getByRole("status")).toContainText(
            "a sign-in link is on its way",
        );
        break;
    }
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
        throw new Error("Only the fictional local HR login link may be used.");
    await page.goto(signed);
    await expect(page).toHaveURL(`${origin}/dashboard`);
}
async function saveRecord(page: Page, label: string) {
    const [response] = await Promise.all([
        page.waitForResponse(
            (response) =>
                response.request().method() === "PATCH" &&
                response.url().startsWith(`${origin}/hr/`),
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
        throw new Error("HR fixtures require one worker and known projects.");
    prepare("cleanup", info.project.name);
    fixture = prepare("setup", info.project.name);
});
test.afterEach(async ({}, info) => {
    prepare("cleanup", info.project.name);
});
async function createCase(
    page: Page,
    name: string,
    offboarding = false,
): Promise<string> {
    await page.goto(`${origin}/hr?section=cases`);
    await page.getByRole("button", { name: "Add record", exact: true }).click();
    await page
        .getByRole("combobox", { name: /^Employee/ })
        .selectOption(String(fixture.employee_id));
    await page.getByLabel("Case name", { exact: true }).fill(name);
    if (offboarding) {
        await page
            .getByRole("combobox", { name: "Purpose", exact: true })
            .selectOption("offboarding");
        await page
            .getByLabel("Final working date", { exact: true })
            .fill(fixture.today);
        await page
            .getByLabel("Manual final-pay information", { exact: true })
            .fill(
                "Review the final expense notes manually before payroll handover.",
            );
    }
    await page
        .getByRole("button", { name: "Create record", exact: true })
        .click();
    await expect(page).toHaveURL(/\/hr\/cases\/\d+$/);
    return new URL(page.url()).pathname;
}
async function completeChecklist(page: Page, title: string, category: string) {
    await page
        .locator("summary")
        .filter({ hasText: "Add checklist item" })
        .click();
    await page.getByLabel("Checklist item", { exact: true }).fill(title);
    await page
        .getByLabel("Description", { exact: true })
        .fill("Reviewed manually with the responsible staff member.");
    await page
        .getByRole("combobox", { name: "Category", exact: true })
        .selectOption(category);
    await page
        .getByRole("button", { name: "Add checklist item", exact: true })
        .click();
    await expect(page).toHaveURL(/\/hr\/checklist\/\d+$/);
    await page.reload();
    await expect(
        page.getByLabel("Checklist item", { exact: true }),
    ).toHaveValue(title);
    await page
        .getByRole("combobox", { name: "Status", exact: true })
        .selectOption("done");
    await page.getByLabel(/^Completion date/).fill(fixture.today);
    await saveRecord(page, "Save checklist item");
    await page.reload();
    await expect(
        page.getByRole("combobox", { name: "Status", exact: true }),
    ).toHaveValue("done");
    await expect(page.getByLabel(/^Completion date/)).toHaveValue(
        fixture.today,
    );
}
test("HR private employment, candidate CV, manual onboarding/offboarding and scoped exports work without Finance", async ({
    page,
}, info) => {
    test.setTimeout(120000);
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await signInHr(page);
    await page.goto(`${origin}/hr?section=employees`);
    await page.getByRole("button", { name: "Add record", exact: true }).click();
    await page
        .getByRole("combobox", { name: /^Employee/ })
        .selectOption(String(fixture.employee_id));
    await page.getByLabel("Role", { exact: true }).fill("Project coordinator");
    await page
        .getByRole("combobox", { name: "Manager (optional)", exact: true })
        .selectOption(String(fixture.manager_id));
    await page
        .getByLabel("Role description", { exact: true })
        .fill("Coordinate agreed work.");
    await page
        .getByLabel("Responsibilities", { exact: true })
        .fill("Maintain delivery records.");
    await page
        .getByLabel("Private email", { exact: true })
        .fill("yael.private@example.test");
    await page
        .getByLabel("Private phone", { exact: true })
        .fill("+972 50 000 0000");
    await page
        .getByRole("button", { name: "Create record", exact: true })
        .click();
    await expect(page).toHaveURL(/\/hr\/employees\/\d+$/);
    await page.reload();
    await expect(page.getByLabel("Private email", { exact: true })).toHaveValue(
        "yael.private@example.test",
    );
    await expect(
        page.getByLabel("Responsibilities", { exact: true }),
    ).toHaveValue("Maintain delivery records.");
    await page
        .getByLabel("Role", { exact: true })
        .fill("Senior project coordinator");
    await saveRecord(page, "Save record");
    await page.reload();
    await expect(page.getByLabel("Role", { exact: true })).toHaveValue(
        "Senior project coordinator",
    );
    await page.screenshot({
        path: info.outputPath("hr-employment.png"),
        fullPage: true,
    });
    await page.goto(`${origin}/hr?section=vacancies`);
    await page.getByRole("button", { name: "Add record", exact: true }).click();
    await page
        .getByLabel("Vacancy role", { exact: true })
        .fill("Operations coordinator");
    await page
        .getByLabel("Description", { exact: true })
        .fill("Coordinate customer work.");
    await page
        .getByRole("button", { name: "Create record", exact: true })
        .click();
    await expect(page).toHaveURL(/\/hr\/vacancies\/\d+$/);
    const vacancyId = new URL(page.url()).pathname.split("/").at(-1)!;
    await page.goto(`${origin}/hr?section=candidates`);
    await page.getByRole("button", { name: "Add record", exact: true }).click();
    await page.getByLabel("Candidate name", { exact: true }).fill("Tamar Erez");
    await page
        .getByRole("combobox", { name: "Vacancy", exact: true })
        .selectOption(vacancyId);
    await page
        .getByLabel("Private email", { exact: true })
        .fill("tamar.candidate@example.test");
    await page
        .getByLabel("Private phone", { exact: true })
        .fill("+972 50 000 0001");
    await page
        .getByLabel("Candidate notes", { exact: true })
        .fill("Private interview notes recorded manually.");
    await page
        .getByLabel("Next action date", { exact: true })
        .fill(fixture.today);
    await page
        .getByRole("button", { name: "Create record", exact: true })
        .click();
    await expect(page).toHaveURL(/\/hr\/candidates\/\d+$/);
    await page
        .getByLabel("Document name", { exact: true })
        .fill("Candidate CV");
    await page
        .getByLabel("HTTPS document link", { exact: true })
        .fill("https://example.test/private-cv.pdf");
    await page
        .getByRole("button", { name: "Add document reference", exact: true })
        .click();
    const cv = page.getByRole("link", { name: "Candidate CV", exact: true });
    await expect(cv).toBeVisible();
    const href = await cv.getAttribute("href");
    const redirect = await page.request.get(origin + href, { maxRedirects: 0 });
    expect(redirect.status()).toBe(302);
    expect(redirect.headers().location).toBe(
        "https://example.test/private-cv.pdf",
    );
    await page
        .getByRole("combobox", { name: "Candidate stage", exact: true })
        .selectOption("interview");
    await saveRecord(page, "Save record");
    await page.reload();
    await expect(
        page.getByRole("combobox", { name: "Candidate stage", exact: true }),
    ).toHaveValue("interview");
    await page
        .getByRole("combobox", { name: "Candidate stage", exact: true })
        .selectOption("hired");
    await page
        .getByRole("combobox", { name: /^Resolved employee/ })
        .selectOption(String(fixture.employee_id));
    await saveRecord(page, "Save record");
    await page.reload();
    await expect(
        page.getByRole("combobox", { name: "Candidate stage", exact: true }),
    ).toHaveValue("hired");
    await expect(
        page.getByRole("combobox", { name: /^Resolved employee/ }),
    ).toHaveValue(String(fixture.employee_id));
    await page.screenshot({
        path: info.outputPath("hr-candidate.png"),
        fullPage: true,
    });
    const onboarding = await createCase(page, "Prepare first working day");
    await completeChecklist(page, "Review required documents", "documents");
    await page.goto(origin + onboarding);
    await page
        .getByLabel("Case completion date", { exact: true })
        .fill(fixture.today);
    await page
        .getByRole("button", { name: "Complete case", exact: true })
        .click();
    await expect(
        page.getByText(
            `Case completed ${fixture.today}. Checklist history is retained.`,
            { exact: true },
        ),
    ).toBeVisible();
    await page.reload();
    await expect(
        page.getByRole("link", {
            name: "Review required documents",
            exact: true,
        }),
    ).toBeVisible();
    const offboarding = await createCase(page, "Prepare final handover", true);
    await completeChecklist(page, "Return office equipment", "equipment");
    await page.goto(origin + offboarding);
    await page
        .getByRole("checkbox", {
            name: "Retain the employee reference as inactive",
            exact: true,
        })
        .check();
    await page
        .getByRole("button", { name: "Complete case", exact: true })
        .click();
    await expect(
        page.getByText(
            `Case completed ${fixture.today}. Checklist history is retained.`,
            { exact: true },
        ),
    ).toBeVisible();
    await page.reload();
    await expect(
        page.getByText("Yael Shalev · Inactive", { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText(
            "Review the final expense notes manually before payroll handover.",
            { exact: true },
        ),
    ).toBeVisible();
    await page.screenshot({
        path: info.outputPath("hr-offboarding.png"),
        fullPage: true,
    });
    await page.goto(`${origin}/hr?section=candidates`);
    await page
        .getByRole("combobox", { name: "Owner filter", exact: true })
        .selectOption(String(fixture.owner_id));
    await page.getByLabel("From date", { exact: true }).fill(fixture.today);
    await page.getByLabel("To date", { exact: true }).fill(fixture.today);
    await page
        .getByRole("button", { name: "Apply filters", exact: true })
        .click();
    await expect(page).toHaveURL(new RegExp(`owner_id=${fixture.owner_id}`));
    await page
        .getByRole("textbox", { name: "Search candidates", exact: true })
        .fill("Tamar");
    await page
        .getByRole("textbox", { name: "Search candidates", exact: true })
        .press("Enter");
    await expect(page).toHaveURL(/q=Tamar/);
    expect(new URL(page.url()).searchParams.get("owner_id")).toBe(
        String(fixture.owner_id),
    );
    const exportHref = await page
        .getByRole("link", { name: "Export candidates (CSV)", exact: true })
        .getAttribute("href");
    const response = await page.request.get(origin + exportHref);
    expect(response.ok()).toBe(true);
    const csv = await response.text();
    expect(csv).toContain("Tamar Erez");
    expect(csv).not.toContain("private-cv.pdf");
    expect(csv).not.toContain("Private interview notes");
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
    ).toBe(true);
    await page.screenshot({
        path: info.outputPath("hr-overview.png"),
        fullPage: true,
    });
    expect(errors).toEqual([]);
});
