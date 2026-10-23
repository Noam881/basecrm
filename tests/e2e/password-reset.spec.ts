import { test, expect } from "@playwright/test";

test("legacy password links return to the email-only sign-in screen", async ({
    page,
}) => {
    for (const path of [
        "/forgot-password",
        "/reset-password/retired-fictional-link?email=noa.levi%40example.test",
        "/reset-password",
        "/user/confirm-password",
    ]) {
        await page.goto(path);
        await expect(page).toHaveURL(/\/login$/);
    }
    await expect(
        page.getByRole("heading", { name: "Sign in", exact: true }),
    ).toBeVisible();
    await expect(page.getByLabel("Email address", { exact: true })).toHaveValue(
        "",
    );
    await expect(page.locator("form input")).toHaveCount(1);
    await expect(page.locator('input[type="password"]')).toHaveCount(0);
});
