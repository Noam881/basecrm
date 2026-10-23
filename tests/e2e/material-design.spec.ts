import { test, expect, type Locator, type Page } from "@playwright/test";
import { signIn } from "./auth";

// Current baseline values, including the four updated light on-container roles.
const lightRoles = {
    primary: "#6750A4",
    "on-primary": "#FFFFFF",
    "primary-container": "#EADDFF",
    "on-primary-container": "#4F378B",
    "secondary-container": "#E8DEF8",
    "on-secondary-container": "#4A4458",
    "on-tertiary-container": "#633B48",
    "on-error-container": "#8C1D18",
    surface: "#FEF7FF",
    "on-surface": "#1D1B20",
    "on-surface-variant": "#49454F",
    "surface-container-lowest": "#FFFFFF",
    "surface-container-low": "#F7F2FA",
    "surface-container": "#F3EDF7",
    "surface-container-high": "#ECE6F0",
    "surface-container-highest": "#E6E0E9",
    outline: "#79747E",
    "outline-variant": "#CAC4D0",
    error: "#B3261E",
};
const darkRoles = {
    primary: "#D0BCFF",
    "on-primary": "#381E72",
    "primary-container": "#4F378B",
    "on-primary-container": "#EADDFF",
    "secondary-container": "#4A4458",
    "on-secondary-container": "#E8DEF8",
    "on-tertiary-container": "#FFD8E4",
    "on-error-container": "#F9DEDC",
    surface: "#141218",
    "on-surface": "#E6E0E9",
    "on-surface-variant": "#CAC4D0",
    "surface-container-lowest": "#0F0D13",
    "surface-container-low": "#1D1B20",
    "surface-container": "#211F26",
    "surface-container-high": "#2B2930",
    "surface-container-highest": "#36343B",
    outline: "#938F99",
    "outline-variant": "#49454F",
    error: "#F2B8B5",
};

async function expectRoles(page: Page, expected: Record<string, string>) {
    const actual = await page
        .locator(".crm-auth")
        .evaluate((element, names) => {
            const style = getComputedStyle(element);
            return Object.fromEntries(
                names.map((name) => [
                    name,
                    style
                        .getPropertyValue(`--md-sys-color-${name}`)
                        .trim()
                        .toUpperCase()
                        .replace(
                            /^#([0-9A-F])([0-9A-F])([0-9A-F])$/,
                            "#$1$1$2$2$3$3",
                        ),
                ]),
            );
        }, Object.keys(expected));
    expect(actual).toEqual(expected);
}

function fieldFor(page: Page, input: Locator) {
    return page.locator(".crm-outlined-field").filter({ has: input });
}

async function labelCenter(label: Locator) {
    const box = await label.boundingBox();
    if (!box) throw new Error("The field label must remain visible.");
    return box.y + box.height / 2;
}

async function nextFrameOpacity(layer: Locator) {
    return layer.evaluate(
        (element) =>
            new Promise<number>((resolve) =>
                requestAnimationFrame(() =>
                    resolve(Number(getComputedStyle(element).opacity)),
                ),
            ),
    );
}

test("light baseline fields retain semantic labels while empty, focused and populated", async ({
    page,
}) => {
    await page.goto("/login");
    await expectRoles(page, lightRoles);
    await expect(page.locator(".crm-auth")).toHaveCSS(
        "background-color",
        "rgb(250, 250, 250)",
    );
    const login = page.getByRole("button", {
        name: "Email me a sign-in link",
        exact: true,
    });
    await expect(login).toHaveCSS("background-color", "rgb(103, 80, 164)");
    await expect(login).toHaveCSS("color", "rgb(255, 255, 255)");

    const email = page.getByLabel("Email address", { exact: true });
    await expect(email).toHaveCount(1);
    await expect(page.locator("form input")).toHaveCount(1);
    await page.getByRole("heading", { level: 1 }).click();
    const field = fieldFor(page, email);
    const label = field.locator(".crm-floating-label");
    await expect(field).toHaveAttribute("data-filled", "false");
    await expect(label).toHaveCSS("color", "rgb(73, 69, 79)");
    const emptyBox = await email.boundingBox();
    if (!emptyBox) throw new Error("The email input must be visible.");
    await expect
        .poll(async () =>
            Math.abs(
                (await labelCenter(label)) - (emptyBox.y + emptyBox.height / 2),
            ),
        )
        .toBeLessThan(2);
    await expect(label).toHaveCSS("transition-duration", "0.15s, 0.15s, 0.15s");
    await expect(label).toHaveCSS(
        "transition-timing-function",
        /cubic-bezier\(0\.2, 0, 0, 1\)/,
    );

    await email.focus();
    await expect(label).toHaveCSS("color", "rgb(103, 80, 164)");
    await expect
        .poll(async () => Math.abs((await labelCenter(label)) - emptyBox.y))
        .toBeLessThan(2);
    await email.fill("noa.levi@example.test");
    await page.getByRole("heading", { level: 1 }).click();
    await expect(field).toHaveAttribute("data-filled", "true");
    await expect
        .poll(async () => Math.abs((await labelCenter(label)) - emptyBox.y))
        .toBeLessThan(2);
    await expect(email).toHaveValue("noa.levi@example.test");
    await email.fill("");
    await page.getByRole("heading", { level: 1 }).click();
    await expect(field).toHaveAttribute("data-filled", "false");
    await expect
        .poll(async () =>
            Math.abs(
                (await labelCenter(label)) - (emptyBox.y + emptyBox.height / 2),
            ),
        )
        .toBeLessThan(2);

    // The email and submit action remain reachable in natural DOM order.
    await email.focus();
    await page.keyboard.press("Tab");
    await expect(login).toBeFocused();
    await expect(login).toHaveCSS("outline-width", "3px");
    await expect(login).toHaveCSS("outline-style", "solid");
    await expect(login).toHaveCSS("outline-color", "rgb(98, 91, 113)");
    await expect(login).toHaveCSS("outline-offset", "2px");

    // Exercise keyboard submission without sending another email in a visual test.
    await page.route("**/login", async (route) => {
        if (route.request().method() !== "POST") {
            await route.continue();
            return;
        }
        await route.fulfill({ status: 303, headers: { location: "/login" } });
    });
    await email.fill("noa.levi@example.test");
    const submission = page.waitForRequest(
        (request) =>
            request.method() === "POST" &&
            new URL(request.url()).pathname === "/login",
    );
    await page.keyboard.press("Enter");
    expect((await submission).postDataJSON()).toEqual({
        email: "noa.levi@example.test",
    });
});

test("server validation describes the invalid field and uses the Material error role", async ({
    page,
}, info) => {
    await page.goto("/login");
    const email = page.getByLabel("Email address", { exact: true });
    await email.fill(
        `material-invalid-email-${info.project.name}-${Date.now()}`,
    );
    await email.evaluate((input: HTMLInputElement) => {
        if (input.form) input.form.noValidate = true;
    });
    await page
        .getByRole("button", { name: "Email me a sign-in link", exact: true })
        .click();
    const error = page
        .getByRole("alert")
        .filter({ hasText: /email.*valid|valid.*email/i });
    await expect(error).toBeVisible();
    await expect(error).toHaveCSS("color", "rgb(179, 38, 30)");
    await expect(email).toHaveAttribute("aria-invalid", "true");
    const errorId = await error.getAttribute("id");
    expect(errorId).toBeTruthy();
    const descriptions = (await email.getAttribute("aria-describedby"))?.split(
        " ",
    );
    expect(descriptions).toContain(errorId);
    const field = fieldFor(page, email);
    await expect(field).toHaveAttribute("data-invalid", "true");
    await expect(field.locator(".crm-floating-label")).toHaveCSS(
        "color",
        "rgb(179, 38, 30)",
    );
    await expect(email).toHaveCSS("border-top-color", "rgb(179, 38, 30)");
    await email.hover();
    await expect(email).toHaveCSS("border-top-color", "rgb(179, 38, 30)");
});

test("one interactive layer responds to pointer hover and press without changing the email", async ({
    page,
}) => {
    await page.goto("/login");
    const email = page.getByLabel("Email address", { exact: true });
    await email.fill("noa.levi@example.test");
    const login = page.getByRole("button", {
        name: "Email me a sign-in link",
        exact: true,
    });
    const layer = login.locator(":scope > .crm-state-layer");
    await expect(layer).toHaveCount(1);
    await expect(login.locator("md-ripple")).toHaveCount(0);
    await login.hover();
    await expect(layer).toHaveCSS("opacity", "0.08");
    await page.mouse.down();
    try {
        await expect(layer).toHaveCSS("opacity", "0.1");
    } finally {
        // Release outside the button so this visual check sends no email.
        await page.mouse.move(0, 0);
        await page.mouse.up();
    }
    await expect(email).toHaveAttribute("type", "email");
    await expect(email).toHaveValue("noa.levi@example.test");
    await expect(layer).toHaveCSS("opacity", "0");
});

test("reduced motion makes field and interaction feedback immediate", async ({
    page,
}) => {
    await page.emulateMedia({ reducedMotion: "reduce" });
    await page.goto("/login");
    const email = page.getByLabel("Email address", { exact: true });
    const label = fieldFor(page, email).locator(".crm-floating-label");
    await expect(label).toHaveCSS("transition-duration", /^0s(?:, 0s)*$/);
    const login = page.getByRole("button", {
        name: "Email me a sign-in link",
        exact: true,
    });
    const layer = login.locator(":scope > .crm-state-layer");
    await expect(layer).toHaveCount(1);
    await login.hover();
    expect(await nextFrameOpacity(layer)).toBe(0.08);
    await page.mouse.down();
    try {
        expect(await nextFrameOpacity(layer)).toBe(0.1);
        await expect(layer).toHaveCSS("clip-path", "none");
    } finally {
        await page.mouse.move(0, 0);
        await page.mouse.up();
    }
    expect(await nextFrameOpacity(layer)).toBe(0);
});

test("dark baseline is applied to real auth controls without rewriting the light scheme", async ({
    page,
    context,
}) => {
    await context.addInitScript(() => {
        document.addEventListener(
            "DOMContentLoaded",
            () => {
                document.documentElement.classList.add("dark");
            },
            { once: true },
        );
    });
    await page.goto("/login");
    await expect(page.locator("html")).toHaveClass(/dark/);
    await expectRoles(page, darkRoles);
    await expect(page.locator(".crm-auth")).toHaveCSS(
        "background-color",
        "rgb(20, 18, 24)",
    );
    const login = page.getByRole("button", {
        name: "Email me a sign-in link",
        exact: true,
    });
    await expect(login).toHaveCSS("background-color", "rgb(208, 188, 255)");
    await expect(login).toHaveCSS("color", "rgb(56, 30, 114)");
    await expect(page.getByLabel("Email address", { exact: true })).toHaveCSS(
        "color",
        "rgb(230, 224, 233)",
    );
});

test("a native table dialog uses elevated containment and restores keyboard focus", async ({
    page,
    isMobile,
}, info) => {
    const origin = "http://127.0.0.1:8092";
    await signIn(page, info.project.name, "beta");
    await expect(page).toHaveURL(`${origin}/dashboard`);
    if (isMobile)
        await page.getByRole("button", { name: "Open navigation" }).click();
    const leadNavigation = page.locator('.crm-sidebar nav a[href="/leads"]');
    const originalNavigation = await leadNavigation.elementHandle();
    await expect(leadNavigation).toHaveClass(/\bcrm-interactive\b/);
    await leadNavigation.click();
    await expect(page).toHaveURL(`${origin}/leads`);
    expect(
        await leadNavigation.evaluate(
            (element, previous) => element === previous,
            originalNavigation,
        ),
    ).toBe(true);
    await expect(leadNavigation).toHaveAttribute("aria-current", "page");
    await expect(leadNavigation).toHaveClass(/\bcrm-interactive\b/);
    if (isMobile) {
        await expect(
            page.getByRole("dialog", { name: "Navigation", exact: true }),
        ).not.toBeVisible();
        await page.getByRole("button", { name: "Open navigation" }).click();
    }
    await expect(leadNavigation).toHaveCSS("position", "relative");
    await expect
        .poll(() =>
            leadNavigation.evaluate((element) => {
                const layer = element.querySelector(".crm-state-layer");
                if (!layer) return Infinity;
                const controlBounds = element.getBoundingClientRect();
                const layerBounds = layer.getBoundingClientRect();
                return Math.max(
                    Math.abs(controlBounds.width - layerBounds.width),
                    Math.abs(controlBounds.height - layerBounds.height),
                );
            }),
        )
        .toBeLessThan(1);
    if (isMobile) {
        await page.getByRole("button", { name: "Close navigation" }).click();
        await expect(
            page.getByRole("dialog", { name: "Navigation", exact: true }),
        ).not.toBeVisible();
    }
    const open = page.getByRole("button", { name: /^Filters/ });
    await open.click();
    const dialog = page.getByRole("dialog", {
        name: "Advanced filters",
        exact: true,
    });
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveCSS("background-color", "rgb(236, 230, 240)");
    const shadow = await dialog.evaluate(
        (element) => getComputedStyle(element).boxShadow,
    );
    expect(shadow).toContain("0px 1px 3px 0px");
    expect(shadow).toContain("0px 4px 8px 3px");
    await expect
        .poll(() =>
            dialog.evaluate(
                (element) =>
                    getComputedStyle(element, "::backdrop").backgroundColor,
            ),
        )
        .toMatch(/(?:\/\s*0\.32|,\s*0\.32)\)/);
    expect(
        await dialog.evaluate((element) =>
            element.contains(document.activeElement),
        ),
    ).toBe(true);
    await page.keyboard.press("Escape");
    await expect(dialog).not.toBeVisible();
    await expect(open).toBeFocused();

    await page.getByRole("button", { name: /^Columns/ }).click();
    const columns = page.getByRole("dialog", { name: "Columns", exact: true });
    const search = columns.getByRole("textbox", { name: "Find a column" });
    await expect(search).toBeVisible();
    await columns
        .getByRole("heading", { name: "Columns", exact: true })
        .click();
    await expect(columns.locator(".md-dialog-search")).not.toHaveClass(
        /\bcrm-outlined-field\b/,
    );
    await expect(search).toHaveAttribute("placeholder", "Find a field…");
    await expect
        .poll(() =>
            search.evaluate(
                (element) => getComputedStyle(element, "::placeholder").opacity,
            ),
        )
        .toBe("1");
    await page.keyboard.press("Escape");
    await expect(columns).not.toBeVisible();
});
