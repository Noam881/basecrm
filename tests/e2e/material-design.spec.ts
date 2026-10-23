import { test, expect, type Locator, type Page } from "@playwright/test";
import { signIn } from "./auth";

// Shared product palette: neutral surfaces and controls with scoped pink accents.
const lightRoles = {
    primary: "#262626",
    "on-primary": "#FFFFFF",
    "primary-container": "#E8E8E8",
    "on-primary-container": "#202020",
    "secondary-container": "#EDEDED",
    "on-secondary-container": "#292929",
    surface: "#FFFFFF",
    "on-surface": "#202020",
    "on-surface-variant": "#525252",
    "surface-container-lowest": "#FFFFFF",
    "surface-container-low": "#F5F5F5",
    "surface-container": "#F0F0F0",
    "surface-container-high": "#EBEBEB",
    "surface-container-highest": "#E5E5E5",
    outline: "#767676",
    "outline-variant": "#C9C9C9",
    error: "#9E3F65",
};
const darkRoles = {
    primary: "#E6E6E6",
    surface: "#141414",
    "on-surface": "#E6E6E6",
    "on-surface-variant": "#C7C7C7",
    "surface-container-low": "#1C1C1C",
    "surface-container": "#212121",
    "surface-container-high": "#2B2B2B",
    "surface-container-highest": "#363636",
    outline: "#939393",
    error: "#F3ACC9",
};
const lightAccent = {
    accent: "#9E3F65",
    "on-accent": "#FFFFFF",
    "accent-container": "#F9DCE7",
    "on-accent-container": "#3E0B22",
};
const darkAccent = {
    accent: "#F3ACC9",
    "on-accent": "#5A1733",
    "accent-container": "#5A1733",
    "on-accent-container": "#FFD9E8",
};

async function expectRoles(
    page: Page,
    expected: Record<string, string>,
    prefix = "--md-sys-color-",
) {
    const actual = await page.locator(".crm-auth").evaluate(
        (element, { names, prefix }) => {
            const style = getComputedStyle(element);
            return Object.fromEntries(
                names.map((name) => [
                    name,
                    style
                        .getPropertyValue(`${prefix}${name}`)
                        .trim()
                        .toUpperCase()
                        .replace(
                            /^#([0-9A-F])([0-9A-F])([0-9A-F])$/,
                            "#$1$1$2$2$3$3",
                        ),
                ]),
            );
        },
        { names: Object.keys(expected), prefix },
    );
    expect(actual).toEqual(expected);
}

async function expectNeutralColor(element: Locator, property: string) {
    const color = await element.evaluate(
        (node, name) => getComputedStyle(node).getPropertyValue(name),
        property,
    );
    const channels = color.match(/^rgba?\(\s*(\d+)\D+(\d+)\D+(\d+)/);
    expect(channels, `${property} must be an RGB colour`).not.toBeNull();
    expect(channels?.[1], `${property} must stay neutral`).toBe(channels?.[2]);
    expect(channels?.[2], `${property} must stay neutral`).toBe(channels?.[3]);
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

test("neutral light fields retain semantic labels while empty, focused and populated", async ({
    page,
}) => {
    await page.goto("/login");
    await expectRoles(page, lightRoles);
    await expectRoles(page, lightAccent, "--crm-");
    await expect(page.locator(".crm-auth")).toHaveCSS(
        "background-color",
        "rgb(250, 250, 250)",
    );
    await expectNeutralColor(page.getByRole("heading", { level: 1 }), "color");
    const login = page.getByRole("button", {
        name: "Email me a sign-in link",
        exact: true,
    });
    await expect(login).toHaveCSS("background-color", "rgb(158, 63, 101)");
    await expect(login).toHaveCSS("color", "rgb(255, 255, 255)");

    const email = page.getByLabel("Email address", { exact: true });
    await expect(email).toHaveCount(1);
    await expect(page.locator("form input")).toHaveCount(1);
    await page.getByRole("heading", { level: 1 }).click();
    const field = fieldFor(page, email);
    const label = field.locator(".crm-floating-label");
    await expectNeutralColor(email, "color");
    await expectNeutralColor(email, "border-top-color");
    await expectNeutralColor(label, "background-color");
    await expect(field).toHaveAttribute("data-filled", "false");
    await expect(label).toHaveCSS("color", "rgb(82, 82, 82)");
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
    await expectNeutralColor(label, "color");
    await expectNeutralColor(email, "border-top-color");
    await expectNeutralColor(email, "caret-color");
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
    await expect(login).toHaveCSS("outline-color", "rgb(82, 82, 82)");
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

test("validation uses a pink notice while the invalid field stays neutral", async ({
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
    await expect(error).toHaveCSS("color", "rgb(158, 63, 101)");
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
        "rgb(38, 38, 38)",
    );
    await expect(email).toHaveCSS("border-top-color", "rgb(38, 38, 38)");
    await email.hover();
    await expect(email).toHaveCSS("border-top-color", "rgb(38, 38, 38)");
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

test("dark controls stay neutral while the sign-in button retains its pink accent", async ({
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
    await expectRoles(page, darkAccent, "--crm-");
    await expect(page.locator(".crm-auth")).toHaveCSS(
        "background-color",
        "rgb(20, 20, 20)",
    );
    const login = page.getByRole("button", {
        name: "Email me a sign-in link",
        exact: true,
    });
    await expect(login).toHaveCSS("background-color", "rgb(243, 172, 201)");
    await expect(login).toHaveCSS("color", "rgb(90, 23, 51)");
    await expect(page.getByLabel("Email address", { exact: true })).toHaveCSS(
        "color",
        "rgb(230, 230, 230)",
    );
});

test("a native table dialog uses elevated containment and restores keyboard focus", async ({
    page,
    isMobile,
}, info) => {
    const origin = "http://127.0.0.1:8092";
    await signIn(page, info.project.name, "beta");
    await expect(page).toHaveURL(`${origin}/dashboard`);
    await expectNeutralColor(page.locator(".crm-sidebar"), "background-color");
    await expectNeutralColor(
        page.locator(".crm-metrics .crm-card").first(),
        "background-color",
    );
    await expectNeutralColor(
        page.locator(".crm-metrics strong").first(),
        "color",
    );
    const count = page.locator(".crm-stage-row strong").first();
    await expect(count).toHaveCSS("background-color", "rgb(249, 220, 231)");
    await expect(count).toHaveCSS("color", "rgb(62, 11, 34)");
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
    await expectNeutralColor(leadNavigation, "background-color");
    await expectNeutralColor(leadNavigation, "color");
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
    await expect(dialog).toHaveCSS("background-color", "rgb(235, 235, 235)");
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
