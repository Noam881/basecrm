import { test, expect } from "@playwright/test";

test("submits the corrected reset email instead of the link's original email", async ({
    page,
}) => {
    const token = "browser-form-regression-token";
    const originalEmail = "noa.levi@example.test";
    const correctedEmail = "daniel.cohen@example.test";
    // Keep this a form regression: no account's password is changed.
    await page.route("**/reset-password", async (route) => {
        if (route.request().method() !== "POST") {
            await route.continue();
            return;
        }
        await route.fulfill({ status: 303, headers: { location: "/login" } });
    });
    await page.goto(
        `/reset-password/${token}?email=${encodeURIComponent(originalEmail)}`,
    );
    const email = page.getByLabel("Email", { exact: true });
    await expect(email).toHaveValue(originalEmail);
    await expect(email).toBeEditable();
    await email.fill(correctedEmail);
    await expect(email).toHaveValue(correctedEmail);
    await page
        .getByLabel("Password", { exact: true })
        .fill("Form-regression-only-29!");
    await page
        .getByLabel("Confirm password", { exact: true })
        .fill("Form-regression-only-29!");
    const submission = page.waitForRequest(
        (request) =>
            request.method() === "POST" &&
            new URL(request.url()).pathname === "/reset-password",
    );
    await page
        .getByRole("button", { name: "Reset password", exact: true })
        .click();
    expect((await submission).postDataJSON()).toMatchObject({
        email: correctedEmail,
        token,
    });
    await expect(page).toHaveURL(/\/login$/);
});

test("allows entering an email when the reset link has no email query", async ({
    page,
}) => {
    await page.goto("/reset-password/browser-form-regression-token");
    const email = page.getByLabel("Email", { exact: true });
    await expect(email).toHaveValue("");
    await expect(email).toBeEditable();
    await email.fill("yael.mizrahi@example.test");
    await expect(email).toHaveValue("yael.mizrahi@example.test");
});
