import { expect, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
const origin = "http://127.0.0.1:8110";
const localOrigins = [
    origin,
    "http://127.0.0.1:8111",
    "http://127.0.0.1:8120",
    "http://127.0.0.1:8121",
];
const mailpit = "http://127.0.0.1:8028";
export async function inboxIds(page: Page): Promise<Set<string>> {
    const result = await (
        await page.request.get(`${mailpit}/api/v1/messages`)
    ).json();
    return new Set(
        result.messages.map((message: { ID: string }) => message.ID),
    );
}
export async function findMailLink(
    page: Page,
    email: string,
    before: Set<string>,
    path: "login" | "vault",
): Promise<string> {
    let link = "";
    await expect
        .poll(
            async () => {
                const inbox = await (
                    await page.request.get(`${mailpit}/api/v1/messages`)
                ).json();
                for (const item of inbox.messages as {
                    ID: string;
                    To: { Address: string }[];
                }[]) {
                    if (
                        before.has(item.ID) ||
                        !item.To.some((to) => to.Address === email)
                    )
                        continue;
                    const message = await (
                        await page.request.get(
                            `${mailpit}/api/v1/message/${item.ID}`,
                        )
                    ).json();
                    const links = await page.evaluate(
                        (html: string) =>
                            Array.from(
                                new DOMParser()
                                    .parseFromString(html, "text/html")
                                    .querySelectorAll("a[href]"),
                            ).map((node) => node.getAttribute("href") ?? ""),
                        message.HTML,
                    );
                    link =
                        links.find((candidate) => {
                            try {
                                const url = new URL(candidate);
                                return (
                                    localOrigins.includes(url.origin) &&
                                    (path === "login"
                                        ? /^\/magic-login\/[A-Za-z0-9]{64}$/.test(
                                              url.pathname,
                                          )
                                        : url.pathname === "/vault/confirm" &&
                                          url.hash.includes("token="))
                                );
                            } catch {
                                return false;
                            }
                        }) ?? "";
                    if (link) return true;
                }
                return false;
            },
            { timeout: 10000 },
        )
        .toBe(true);
    return link;
}
export async function signInVault(
    page: Page,
    email: string,
    loginOrigin = origin,
) {
    if (
        !/^vault-(riddle-)?(desktop|mobile)@alpha\.example\.test$/.test(
            email,
        ) ||
        !localOrigins.includes(loginOrigin)
    )
        throw new Error(
            "Only reserved fictional Vault fixture accounts may be used.",
        );
    const before = await inboxIds(page);
    await page.goto(`${loginOrigin}/login`);
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
    await page.goto(await findMailLink(page, email, before, "login"));
    await expect(page).toHaveURL(`${loginOrigin}/dashboard`);
}
