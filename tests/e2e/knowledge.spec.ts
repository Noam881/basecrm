import { test, expect, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
test.use({ actionTimeout: 7000 });
const origin = "http://127.0.0.1:8101";
const mailpit = "http://127.0.0.1:8028";
let fixture: {
    email: string;
    owner_id: number;
    routine_id: number;
    next_expiry: string;
    today: string;
};
function prepare(mode: "setup" | "cleanup", project: string) {
    return JSON.parse(
        execFileSync(
            "php",
            ["scripts/knowledge-browser-fixtures.php", mode, project],
            { encoding: "utf8" },
        ),
    );
}
async function signInKnowledge(page: Page) {
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
        throw new Error(
            "Only the fictional local Knowledge login link may be used.",
        );
    await page.goto(signed);
    await expect(page).toHaveURL(`${origin}/dashboard`);
}
async function saveRecord(page: Page, label: string) {
    const [response] = await Promise.all([
        page.waitForResponse(
            (response) =>
                response.request().method() === "PATCH" &&
                response.url().startsWith(`${origin}/knowledge/`),
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
            "Knowledge fixtures require one worker and known projects.",
        );
    prepare("cleanup", info.project.name);
    fixture = prepare("setup", info.project.name);
});
test.afterEach(async ({}, info) => {
    prepare("cleanup", info.project.name);
});

test("Knowledge library, private references, manual renewals and linked routine instructions persist", async ({
    page,
}, info) => {
    test.setTimeout(120000);
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await signInKnowledge(page);
    await page.goto(`${origin}/knowledge?section=library`);
    await page.getByRole("button", { name: "Add record", exact: true }).click();
    await page.getByLabel("Title", { exact: true }).fill("Customer handover");
    await page.getByLabel("Category", { exact: true }).fill("Delivery");
    await page.getByLabel("Relevant role", { exact: true }).fill("Coordinator");
    await page.getByLabel("Current version", { exact: true }).fill("1.0");
    await page.getByLabel("Revision date", { exact: true }).fill(fixture.today);
    await page
        .getByLabel("Updated document date", { exact: true })
        .fill(fixture.today);
    await page
        .getByRole("combobox", { name: "Status", exact: true })
        .selectOption("current");
    await page
        .getByRole("button", { name: "Create record", exact: true })
        .click();
    await expect(page).toHaveURL(/\/knowledge\/library\/\d+$/);
    const entryUrl = page.url();
    await page.reload();
    await expect(page.getByLabel("Relevant role", { exact: true })).toHaveValue(
        "Coordinator",
    );
    await page
        .getByLabel("Title", { exact: true })
        .fill("Customer handover guide");
    await saveRecord(page, "Save record");
    await page.reload();
    await expect(page.getByLabel("Title", { exact: true })).toHaveValue(
        "Customer handover guide",
    );
    await page
        .getByLabel("Document name", { exact: true })
        .fill("Handover instructions");
    await page
        .getByLabel("HTTPS document link", { exact: true })
        .fill("https://example.test/handover.pdf");
    await page
        .getByRole("button", { name: "Add document reference", exact: true })
        .click();
    const doc = page.getByRole("link", {
        name: "Handover instructions",
        exact: true,
    });
    await expect(doc).toBeVisible();
    const documentHref = await doc.getAttribute("href");
    const documentResponse = await page.request.get(origin + documentHref, {
        maxRedirects: 0,
    });
    expect(documentResponse.status()).toBe(302);
    expect(documentResponse.headers().location).toBe(
        "https://example.test/handover.pdf",
    );
    await page
        .getByRole("combobox", { name: "Work or routine", exact: true })
        .selectOption("routine:" + fixture.routine_id);
    await page
        .getByRole("button", { name: "Link instruction", exact: true })
        .click();
    const routineLink = page.getByRole("link", {
        name: "Review delivery instructions",
        exact: true,
    });
    await expect(routineLink).toBeVisible();
    await routineLink.click();
    await expect(page).toHaveURL(
        new RegExp("/executive/routines/" + fixture.routine_id + "$"),
    );
    await expect(
        page.getByRole("heading", { name: "Instructions", exact: true }),
    ).toBeVisible();
    await page
        .getByRole("link", { name: "Customer handover guide", exact: true })
        .click();
    await expect(page).toHaveURL(entryUrl);
    await page.screenshot({
        path: info.outputPath("knowledge-library.png"),
        fullPage: true,
    });
    await page.goto(`${origin}/knowledge?section=library`);
    await page.getByRole("button", { name: "Add record", exact: true }).click();
    await page
        .getByRole("combobox", { name: "Kind", exact: true })
        .selectOption("training");
    await page.getByLabel("Topic", { exact: true }).fill("Welcome briefing");
    await page.getByLabel("Relevant role", { exact: true }).fill("Coordinator");
    await page.getByLabel("Category", { exact: true }).fill("Induction");
    await page
        .getByLabel("Updated document date", { exact: true })
        .fill(fixture.today);
    await page
        .getByRole("combobox", { name: "Status", exact: true })
        .selectOption("current");
    await page
        .getByRole("button", { name: "Create record", exact: true })
        .click();
    await expect(page).toHaveURL(/\/knowledge\/library\/\d+$/);
    await page.reload();
    await expect(page.getByLabel("Topic", { exact: true })).toHaveValue(
        "Welcome briefing",
    );
    await page.goto(`${origin}/knowledge?section=renewals`);
    await page.getByRole("button", { name: "Add record", exact: true }).click();
    await page
        .getByLabel("License or insurance name", { exact: true })
        .fill("Professional insurance");
    await page.getByLabel("Provider", { exact: true }).fill("Olive Cover");
    await page.getByLabel("Expiry date", { exact: true }).fill(fixture.today);
    await page.getByLabel("Reminder date", { exact: true }).fill(fixture.today);
    await page
        .getByRole("button", { name: "Create record", exact: true })
        .click();
    await expect(page).toHaveURL(/\/knowledge\/renewals\/\d+$/);
    await page
        .getByLabel("Next expiry date (optional)", { exact: true })
        .fill(fixture.next_expiry);
    await page
        .getByLabel("Next reminder date (optional)", { exact: true })
        .fill(fixture.next_expiry);
    await page
        .getByLabel("Renewal summary", { exact: true })
        .fill("Renewal confirmed manually.");
    await page
        .getByRole("button", { name: "Record renewal", exact: true })
        .click();
    await expect(
        page.getByText("Renewal confirmed manually.", { exact: true }),
    ).toBeVisible();
    await page.reload();
    await expect(page.getByLabel("Expiry date", { exact: true })).toHaveValue(
        fixture.next_expiry,
    );
    await expect(
        page.getByText(
            "Previous expiry " +
                fixture.today +
                " · Next expiry " +
                fixture.next_expiry,
            { exact: true },
        ),
    ).toBeVisible();
    await page.screenshot({
        path: info.outputPath("knowledge-renewal.png"),
        fullPage: true,
    });
    await page.goto(`${origin}/knowledge?section=systems`);
    await page.getByRole("button", { name: "Add record", exact: true }).click();
    await page
        .getByLabel("System name", { exact: true })
        .fill("Team workspace");
    await page
        .getByLabel("Public HTTPS login page", { exact: true })
        .fill("https://example.test/login");
    await page
        .getByLabel("Password-manager item reference", { exact: true })
        .fill("Business vault / Team workspace #42");
    await page
        .getByRole("button", { name: "Create record", exact: true })
        .click();
    await expect(page).toHaveURL(/\/knowledge\/systems\/\d+$/);
    await page.reload();
    await expect(
        page.getByLabel("Password-manager item reference", { exact: true }),
    ).toHaveValue("Business vault / Team workspace #42");
    const loginHref = await page
        .getByRole("link", { name: "Open public login page", exact: true })
        .getAttribute("href");
    const login = await page.request.get(origin + loginHref, {
        maxRedirects: 0,
    });
    expect(login.status()).toBe(302);
    expect(login.headers().location).toBe("https://example.test/login");
    await page.screenshot({
        path: info.outputPath("knowledge-system.png"),
        fullPage: true,
    });
    await page.goto(`${origin}/knowledge?section=library`);
    await page.getByLabel("Role filter", { exact: true }).fill("Coordinator");
    await page
        .getByRole("button", { name: "Apply filters", exact: true })
        .click();
    await expect(page).toHaveURL(/role=Coordinator/);
    await page
        .getByRole("textbox", { name: "Search library", exact: true })
        .fill("handover");
    await page
        .getByRole("textbox", { name: "Search library", exact: true })
        .press("Enter");
    await expect(page).toHaveURL(/q=handover/);
    expect(new URL(page.url()).searchParams.get("role")).toBe("Coordinator");
    const csvHref = await page
        .getByRole("link", { name: "Export library (CSV)", exact: true })
        .getAttribute("href");
    const response = await page.request.get(origin + csvHref);
    expect(response.ok()).toBe(true);
    const csv = await response.text();
    expect(csv).toContain("Customer handover guide");
    expect(csv).not.toContain("Welcome briefing");
    expect(csv).not.toContain("handover.pdf");
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
    ).toBe(true);
    await page.screenshot({
        path: info.outputPath("knowledge-overview.png"),
        fullPage: true,
    });
    expect(errors).toEqual([]);
});
