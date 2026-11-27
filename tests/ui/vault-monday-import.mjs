// Isolated actual Vue/SFC browser harness. No Laravel database, source token, email or provider is used.
import { createServer } from "vite";
import vue from "@vitejs/plugin-vue";
import { chromium } from "@playwright/test";
import assert from "node:assert/strict";
import { fileURLToPath } from "node:url";
import path from "node:path";

const root = fileURLToPath(new URL("../..", import.meta.url));
const virtualId = "\0virtual:vault-import-ui";
let server;
server = await createServer({
    root,
    configFile: false,
    plugins: [
        vue(),
        {
            name: "isolated-vault-import-ui",
            resolveId(id) {
                if (id === "virtual:vault-import-ui") return virtualId;
            },
            load(id) {
                if (id !== virtualId) return;
                return `import {createApp} from 'vue';
                import Component from '/packages/awove/vault/resources/js/components/VaultMondayImport.vue';
                import '/packages/awove/crm-core/resources/css/tokens.css';
                import '/packages/awove/crm-core/resources/css/components.css';
                import '/packages/awove/crm-core/resources/css/core.css';
                createApp(Component).mount('#app');`;
            },
            configureServer(vite) {
                vite.middlewares.use(async (request, response, next) => {
                    if (!request.url?.startsWith("/ui-harness")) return next();
                    const hebrew = request.url.includes("he");
                    const html = `<!doctype html><html lang="${hebrew ? "he" : "en"}" dir="${hebrew ? "rtl" : "ltr"}" data-crm-theme="green"><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><main id="app" style="padding:16px"></main><script type="module" src="/@id/__x00__virtual:vault-import-ui"></script></body></html>`;
                    response.setHeader("Content-Type", "text/html");
                    response.end(
                        await vite.transformIndexHtml(request.url, html),
                    );
                });
            },
        },
    ],
    resolve: { alias: { "@": path.join(root, "resources/js") } },
    server: { host: "127.0.0.1", port: 0, hmr: false },
});
await server.listen();
const origin = `http://127.0.0.1:${server.httpServer.address().port}`;
const browser = await chromium.launch({ headless: true });
const results = [];
try {
    for (const scenario of [
        "items",
        "rate",
        "locked",
        "files",
        "representative",
        "one",
        "he-mobile",
    ]) {
        const page = await browser.newPage({
            viewport:
                scenario === "he-mobile"
                    ? { width: 390, height: 844 }
                    : { width: 1280, height: 900 },
        });
        const errors = [];
        page.on("pageerror", (error) => errors.push(error.message));
        let items =
            scenario === "files" ? 20 : scenario === "representative" ? 1 : 0;
        let files = 0;
        let itemCalls = 0;
        let fileCalls = 0;
        const issuedAt = Date.now();
        const status = () => ({
            total: 20,
            accepted: items,
            pending_request_key: null,
            source_configured: true,
            file_scope_ready: true,
            file_total: 10,
            files_accepted: files,
            files_eligible:
                scenario === "representative"
                    ? Math.max(0, 1 - files)
                    : 10 - files,
            pending_file_request_key: null,
            binary_assets_pending: 10 - files,
            workdocs_pending: 3,
            migration_complete: false,
        });
        await page.route("**/vault/api/**", async (route) => {
            const pathname = new URL(route.request().url()).pathname;
            if (pathname === "/vault/api/auth/status") {
                const now = Date.now();
                return route.fulfill({
                    json: {
                        auth_mode: "email_riddle",
                        enrolled: true,
                        approved: true,
                        unlocked: true,
                        issued_at: new Date(issuedAt).toISOString(),
                        expires_at: new Date(issuedAt + 300000).toISOString(),
                        server_now: new Date(now).toISOString(),
                        step: "email_start",
                        challenge_id: null,
                        dev_question_mode: false,
                        question: null,
                        can_admin: true,
                    },
                });
            }
            if (pathname === "/vault/api/imports/monday")
                return route.fulfill({ json: status() });
            if (pathname.endsWith("/next-file")) {
                fileCalls++;
                files++;
                return route.fulfill({
                    json: { state: "verified", ...status() },
                });
            }
            if (pathname.endsWith("/next")) {
                itemCalls++;
                assert.match(
                    route.request().postDataJSON().request_key,
                    /^[a-f0-9-]{36}$/,
                );
                if (scenario === "rate" && itemCalls === 3)
                    return route.fulfill({
                        status: 429,
                        json: { message: "Fictional rate boundary" },
                    });
                if (scenario === "locked" && itemCalls === 2)
                    return route.fulfill({
                        status: 423,
                        json: { message: "Fictional locked boundary" },
                    });
                items++;
                return route.fulfill({
                    json: { state: "verified", ...status() },
                });
            }
            throw new Error("Unapproved test request");
        });
        await page.goto(
            `${origin}/ui-harness${scenario === "he-mobile" ? "?he" : ""}`,
        );
        const button = page.getByRole("button", {
            name:
                scenario === "files" || scenario === "representative"
                    ? "Import next 5 files"
                    : scenario === "one"
                      ? "Import one record"
                      : scenario === "he-mobile"
                        ? "ייבוא 5 הרשומות הבאות"
                        : "Import next 5 records",
            exact: true,
        });
        await button.waitFor({ state: "visible", timeout: 15000 });
        await button.click();
        if (scenario === "locked") {
            await page
                .getByText("Unlock the Vault to continue the approved import.")
                .waitFor();
        } else {
            await page
                .getByRole("button", {
                    name: scenario === "he-mobile" ? "רענון" : "Refresh",
                    exact: true,
                })
                .waitFor({ state: "visible" });
            await page.waitForFunction(
                () =>
                    ![...document.querySelectorAll("button")].some((node) =>
                        node.textContent.includes("Working"),
                    ),
            );
        }
        await page.waitForTimeout(200);
        assert.equal(
            itemCalls,
            scenario === "files" || scenario === "representative"
                ? 0
                : scenario === "rate"
                  ? 3
                  : scenario === "locked"
                    ? 2
                    : scenario === "one"
                      ? 1
                      : 5,
        );
        assert.equal(
            fileCalls,
            scenario === "files" ? 5 : scenario === "representative" ? 1 : 0,
        );
        assert.deepEqual(errors, []);
        if (scenario === "he-mobile") {
            assert.equal(await page.locator("html").getAttribute("dir"), "rtl");
            assert.ok(
                await page.evaluate(
                    () => document.documentElement.scrollWidth <= innerWidth,
                ),
            );
            await page.screenshot({
                path: "/private/tmp/basecrm-vault-monday-ui-he-mobile.png",
                fullPage: true,
            });
        }
        results.push(scenario);
        await page.close();
    }
    process.stdout.write(
        JSON.stringify({ result: "passed", scenarios: results }) + "\n",
    );
} finally {
    await browser.close();
    await server.close();
}
