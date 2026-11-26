import { expect, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
const origin = "http://127.0.0.1:8102";
const mailpit = "http://127.0.0.1:8028";
export async function signInMarketingFieldFixture(page: Page, email: string) {
    if (
        !["desktop", "mobile"].some(
            (project) => email === `marketing-${project}@alpha.example.test`,
        )
    ) {
        throw new Error(
            "Only reserved fictional Marketing fixture accounts may be used.",
        );
    }
    const prior = (
        await (await page.request.get(`${mailpit}/api/v1/messages`)).json()
    ).messages as { ID: string }[];
    for (let attempt = 0; attempt < 2; attempt++) {
        await page.goto(`${origin}/login`);
        await page.getByLabel("Email address", { exact: true }).fill(email);
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
            setTimeout(resolve, Math.min(retryAfter + 1, 60) * 1000),
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
                    message.To.some((to) => to.Address === email) &&
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
