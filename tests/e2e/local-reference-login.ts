import { expect, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
const mailpit = "http://127.0.0.1:8028";
export async function signInLocalFixture(
    page: Page,
    email: string,
    origin: string,
) {
    const family =
        origin === "http://127.0.0.1:8096"
            ? "employees"
            : origin === "http://127.0.0.1:8100"
              ? "payroll"
              : origin === "http://127.0.0.1:8103"
                ? "assets"
                : null;
    if (
        !family ||
        !["desktop", "mobile"].some(
            (project) => email === `${family}-${project}@alpha.example.test`,
        )
    )
        throw new Error(
            "Only reserved fictional employee/payroll/assets accounts and local proof origins are permitted.",
        );
    const prior = (
        await (await page.request.get(`${mailpit}/api/v1/messages`)).json()
    ).messages as { ID: string }[];
    await page.goto(`${origin}/login`);
    await page.getByLabel("Email address", { exact: true }).fill(email);
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
                    message.To.some((to) => to.Address === email) &&
                    message.Subject.endsWith("sign-in link"),
            )?.ID;
            return !!id;
        })
        .toBe(true);
    const message = await (
        await page.request.get(`${mailpit}/api/v1/message/${id}`)
    ).json();
    const signed = await page.evaluate(
        ({ HTML, Text }: { HTML: string; Text: string }) =>
            Array.from(
                new DOMParser()
                    .parseFromString(HTML, "text/html")
                    .querySelectorAll("a[href]"),
            )
                .map((a) => a.getAttribute("href") ?? "")
                .find((href) =>
                    /\/magic-login\/[A-Za-z0-9]{64}\?/.test(href),
                ) ??
            Text.match(/https?:\/\/[^\s<>"']+/g)?.find((href) =>
                /\/magic-login\/[A-Za-z0-9]{64}\?/.test(href),
            ) ??
            "",
        message,
    );
    const url = new URL(signed);
    if (
        url.origin !== origin ||
        !/^\/magic-login\/[A-Za-z0-9]{64}$/.test(url.pathname) ||
        !url.searchParams.has("signature")
    )
        throw new Error(
            "Only the reserved fictional local fixture login link may be used.",
        );
    await page.goto(signed);
    await expect(page).toHaveURL(`${origin}/dashboard`);
}
