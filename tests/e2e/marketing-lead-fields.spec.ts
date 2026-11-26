import { test, expect, type Locator, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { readFileSync, writeFileSync, unlinkSync } from "node:fs";
import { resolve } from "node:path";
import { signInMarketingFieldFixture } from "./marketing-field-login";

test.use({ actionTimeout: 8000 });

const origin = "http://127.0.0.1:8102";
const hebrewOrigin = "http://127.0.0.1:8108";
const hebrewManifest = resolve(
    "storage/app/private/marketing-lead-fields-he-browser-manifest.json",
);
let fixture: { email: string; lead_id: number; manifest: string };
function prepare(mode: "setup" | "cleanup", project: string) {
    return JSON.parse(
        execFileSync(
            "php",
            ["scripts/marketing-browser-fixtures.php", mode, project],
            { encoding: "utf8" },
        ),
    );
}
function panel(page: Page, heading: string) {
    return page.locator("section").filter({
        has: page.getByRole("heading", { name: heading, exact: true }),
    });
}
async function save(page: Page, form: Locator, label: string) {
    const response = page.waitForResponse(
        (r) =>
            r.request().method() === "PATCH" &&
            new URL(r.url()).pathname ===
                `/marketing/leads/${fixture.lead_id}/attribution`,
    );
    await form.getByRole("button", { name: label, exact: true }).click();
    const result = await response;
    expect(result.status()).toBe(200);
    return result.json();
}

test.beforeEach(async ({}, info) => {
    if (
        info.config.workers !== 1 ||
        !["desktop", "mobile"].includes(info.project.name)
    )
        throw new Error(
            "Marketing field fixtures require serial known projects.",
        );
    prepare("cleanup", info.project.name);
    fixture = prepare("setup", info.project.name);
    if (
        resolve(fixture.manifest) !==
        resolve("storage/app/private/marketing-browser-manifest.json")
    )
        throw new Error(
            "Only the private synthetic Marketing manifest is allowed.",
        );
    const manifest = JSON.parse(readFileSync(fixture.manifest, "utf8"));
    manifest.locale = "he";
    manifest.theme = "green";
    writeFileSync(hebrewManifest, JSON.stringify(manifest), { mode: 0o600 });
});
test.afterEach(async ({}, info) => {
    prepare("cleanup", info.project.name);
});
test.afterAll(() => {
    try {
        unlinkSync(hebrewManifest);
    } catch (error) {
        if ((error as NodeJS.ErrnoException).code !== "ENOENT") throw error;
    }
});

test("Marketing lead questionnaire and nullable consent persist in English and Hebrew", async ({
    page,
}, info) => {
    test.setTimeout(180000);
    page.setDefaultTimeout(8000);
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await signInMarketingFieldFixture(page, fixture.email);
    await page.goto(`${origin}/leads/${fixture.lead_id}`);
    const english = panel(page, "Marketing attribution");
    await expect(
        english.getByRole("combobox", { name: /^Marketing consent/ }),
    ).toBeVisible();
    await expect(
        english
            .getByRole("combobox", { name: /^Marketing consent/ })
            .locator("option:checked"),
    ).toHaveText("Not recorded");
    const answers = {
        "Enquiry reason": "Review Yael's planning workflow.",
        "Business description": "An independent studio run by Yael Shalev.",
        "Areas to improve": "Scheduling and customer follow-up.",
        "Type of help requested": "A practical process review.",
        "Who handles this today": "Yael Shalev.",
        "Desired call outcome": "Agree on the next step.",
        "Current processes and tools":
            "Calendar and email.\nA shared checklist.",
        "UTM term": "office planning",
        "Facebook click ID": "0",
        "Google click ID": "0000015",
        "Referrer URL": "https://social.example.test/guide",
        "Website / landing page": "https://example.test/planning",
    };
    for (const [label, value] of Object.entries(answers)) {
        await english.getByLabel(label, { exact: true }).fill(value);
    }
    await english
        .getByRole("combobox", { name: /^Marketing consent/ })
        .selectOption({ label: "No" });
    const stored = await save(page, english, "Save attribution");
    expect(stored.fields.marketing_consent).toBe(false);
    expect(stored.fields.fbclid).toBe("0");
    expect(stored.fields.gclid).toBe("0000015");
    await page.reload();
    for (const [label, value] of Object.entries(answers)) {
        await expect(english.getByLabel(label, { exact: true })).toHaveValue(
            value,
        );
    }
    await expect(
        english.getByRole("combobox", { name: /^Marketing consent/ }),
    ).toHaveValue("false");
    await page.screenshot({
        path: info.outputPath("marketing-lead-fields-english.png"),
        fullPage: true,
    });

    await page.goto(`${hebrewOrigin}/leads/${fixture.lead_id}`);
    await expect(page.locator("html")).toHaveAttribute("lang", "he");
    await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
    const hebrew = panel(page, "שיוך שיווקי");
    await expect(
        hebrew.getByRole("combobox", { name: /^הסכמה לקבלת תוכן שיווקי/ }),
    ).toHaveValue("false");
    await expect(hebrew.getByLabel("תיאור העסק", { exact: true })).toHaveValue(
        answers["Business description"],
    );
    await expect(hebrew.getByLabel("סיבת הפנייה", { exact: true })).toHaveValue(
        answers["Enquiry reason"],
    );
    await expect(hebrew.getByLabel("מונח UTM", { exact: true })).toHaveValue(
        "office planning",
    );
    await hebrew.getByLabel("סיבת הפנייה", { exact: true }).fill("");
    await hebrew
        .getByRole("combobox", { name: /^הסכמה לקבלת תוכן שיווקי/ })
        .selectOption({ label: "לא תועד" });
    const cleared = await save(page, hebrew, "שמירת שיוך מקור");
    expect(cleared.fields.marketing_consent).toBeNull();
    expect(cleared.fields.qualification_reason).toBeNull();
    expect(cleared.fields.qualification_business_description).toBe(
        answers["Business description"],
    );
    expect(cleared.fields.fbclid).toBe("0");
    await page.reload();
    await expect(
        hebrew
            .getByRole("combobox", { name: /^הסכמה לקבלת תוכן שיווקי/ })
            .locator("option:checked"),
    ).toHaveText("לא תועד");
    await expect(hebrew.getByLabel("סיבת הפנייה", { exact: true })).toBeEmpty();
    await expect(hebrew.getByLabel("תיאור העסק", { exact: true })).toHaveValue(
        answers["Business description"],
    );
    await page.screenshot({
        path: info.outputPath("marketing-lead-fields-hebrew.png"),
        fullPage: true,
    });
    expect(errors).toEqual([]);
});
