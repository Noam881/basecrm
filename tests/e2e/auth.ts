import { expect, type APIRequestContext, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import {
    chmodSync,
    existsSync,
    readFileSync,
    rmSync,
    writeFileSync,
} from "node:fs";

type Installation = "alpha" | "beta";
type MessageSummary = {
    ID: string;
    To: { Address: string }[];
    Subject: string;
    Created: string;
};
const fixtures = JSON.parse(readFileSync(".local-fixtures.json", "utf8"));
const mailpit = "http://127.0.0.1:8028";
const genericStatus =
    "If this email belongs to an active account, a sign-in link is on its way. Check your inbox.";

function account(project: string, installation: Installation) {
    if (
        !["desktop", "mobile"].includes(project) ||
        !["alpha", "beta"].includes(installation)
    ) {
        throw new Error(
            "Email sign-in requires a known isolated browser account.",
        );
    }
    const email = fixtures[`tables_${installation}_${project}`]?.email;
    if (
        typeof email !== "string" ||
        !/@(?:[a-z0-9-]+\.)*example\.test$/i.test(email)
    ) {
        throw new Error("Email sign-in requires a fictional fixture address.");
    }
    return {
        email,
        origin: `http://127.0.0.1:${installation === "alpha" ? 8091 : 8092}`,
        cache: `/private/tmp/basecrm-e2e-auth-${process.ppid}-${project}-${installation}.json`,
    };
}

async function accountMessages(request: APIRequestContext, email: string) {
    const response = await request.get(`${mailpit}/api/v1/messages`);
    if (!response.ok()) throw new Error("Local Mailpit is unavailable.");
    const inbox = (await response.json()) as { messages: MessageSummary[] };
    return inbox.messages
        .filter(
            (message) =>
                message.To.some(
                    (recipient) =>
                        recipient.Address.toLowerCase() === email.toLowerCase(),
                ) && message.Subject.endsWith("sign-in link"),
        )
        .sort((a, b) => Date.parse(b.Created) - Date.parse(a.Created));
}

/** Request one real local email without consuming its link or altering another cached session. */
export async function requestSignInLink(
    page: Page,
    project: string,
    installation: Installation = "alpha",
): Promise<string> {
    const { email, origin } = account(project, installation);
    let stage = "loading the email sign-in form";
    try {
        const previousIds = new Set(
            (await accountMessages(page.request, email)).map(({ ID }) => ID),
        );
        await page.context().setStorageState({ cookies: [], origins: [] });
        await page.goto(`${origin}/login`);
        await expect(
            page.getByRole("heading", { name: "Sign in", exact: true }),
        ).toBeVisible();
        await page.getByLabel("Email address", { exact: true }).fill(email);
        await page
            .getByRole("button", {
                name: "Email me a sign-in link",
                exact: true,
            })
            .click();
        await expect(page.getByRole("status")).toHaveText(genericStatus);

        stage = "delivering the local queued email";
        execFileSync(
            "php",
            [
                "artisan",
                `--env=${installation}`,
                "queue:work",
                "database",
                "--stop-when-empty",
                "--tries=1",
            ],
            { stdio: "ignore", timeout: 15000 },
        );
        let messageId: string | undefined;
        await expect
            .poll(
                async () => {
                    messageId = (
                        await accountMessages(page.request, email)
                    ).find(({ ID }) => !previousIds.has(ID))?.ID;
                    return Boolean(messageId);
                },
                {
                    message:
                        "A new sign-in email should reach the isolated fixture account",
                },
            )
            .toBe(true);

        stage = "reading the fictional account's new sign-in message";
        const response = await page.request.get(
            `${mailpit}/api/v1/message/${messageId}`,
        );
        if (!response.ok())
            throw new Error("The local message is unavailable.");
        const message = (await response.json()) as {
            HTML: string;
            Text: string;
        };
        // Only this exact fictional recipient's new message is read; never log its body or signed URL.
        const signedUrl = await page.evaluate(({ HTML, Text }) => {
            const document = new DOMParser().parseFromString(HTML, "text/html");
            return (
                Array.from(document.querySelectorAll("a[href]"))
                    .map((link) => link.getAttribute("href") ?? "")
                    .find((href) =>
                        /\/magic-login\/[A-Za-z0-9]{64}\?/.test(href),
                    ) ??
                Text.match(/https?:\/\/[^\s<>"']+/g)?.find((href) =>
                    /\/magic-login\/[A-Za-z0-9]{64}\?/.test(href),
                ) ??
                ""
            );
        }, message);
        const link = new URL(signedUrl);
        if (
            link.origin !== origin ||
            !/^\/magic-login\/[A-Za-z0-9]{64}$/.test(link.pathname) ||
            !link.searchParams.has("expires") ||
            !link.searchParams.has("signature")
        ) {
            throw new Error(
                "The sign-in message must target the local fixture installation.",
            );
        }
        return signedUrl;
    } catch {
        throw new Error(`Local email sign-in failed while ${stage}.`);
    }
}

/** Reuse an isolated fixture session within this Playwright run, validating it before every use. */
export async function signIn(
    page: Page,
    project: string,
    installation: Installation = "alpha",
): Promise<void> {
    const { email, origin, cache } = account(project, installation);
    if (existsSync(cache)) {
        try {
            await page.context().setStorageState(cache);
            // A normal document navigation avoids Inertia's asset-version negotiation.
            const response = await page.goto(`${origin}/dashboard`);
            if (
                response?.status() === 200 &&
                page.url() === `${origin}/dashboard`
            ) {
                const currentEmail = await page.evaluate(() => {
                    const initialPage = document.querySelector(
                        'script[data-page="app"][type="application/json"]',
                    )?.textContent;
                    return initialPage
                        ? JSON.parse(initialPage).props?.auth?.user?.email
                        : null;
                });
                if (currentEmail === email) {
                    await expect(
                        page.getByRole("heading", {
                            name: "Overview",
                            exact: true,
                        }),
                    ).toBeVisible();
                    return;
                }
            }
        } catch {
            // Expired or invalid cached sessions must go through the real email flow again.
        }
        rmSync(cache, { force: true });
    }
    const signedUrl = await requestSignInLink(page, project, installation);
    await page.goto(signedUrl);
    await expect(page).toHaveURL(`${origin}/dashboard`);
    await expect(
        page.getByRole("heading", { name: "Overview", exact: true }),
    ).toBeVisible();
    writeFileSync(cache, JSON.stringify(await page.context().storageState()), {
        mode: 0o600,
    });
    chmodSync(cache, 0o600);
}
