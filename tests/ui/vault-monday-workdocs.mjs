// Actual Vue/SFC with fictional HTTP responses; no Laravel DB, source, token or email is used.
import { createServer } from "vite";
import vue from "@vitejs/plugin-vue";
import { chromium } from "@playwright/test";
import assert from "node:assert/strict";
import { fileURLToPath } from "node:url";
import path from "node:path";

const root = fileURLToPath(new URL("../..", import.meta.url));
const virtual = "\0virtual:vault-workdocs-ui";
const server = await createServer({
    root,
    configFile: false,
    plugins: [
        vue(),
        {
            name: "isolated-workdocs-ui",
            resolveId(id) {
                if (id === "virtual:vault-workdocs-ui") return virtual;
            },
            load(id) {
                if (id === virtual)
                    return `import {createApp} from 'vue';
                import Component from '/packages/awove/vault/resources/js/components/VaultMondayWorkdocs.vue';
                import '/packages/awove/crm-core/resources/css/tokens.css';
                import '/packages/awove/crm-core/resources/css/components.css';
                import '/packages/awove/crm-core/resources/css/core.css';
                createApp(Component).mount('#app');`;
            },
            configureServer(vite) {
                vite.middlewares.use(async (request, response, next) => {
                    if (!request.url?.startsWith("/workdocs-harness"))
                        return next();
                    const hebrew = request.url.includes("he");
                    response.setHeader("Content-Type", "text/html");
                    response.end(
                        await vite.transformIndexHtml(
                            request.url,
                            `<!doctype html><html lang="${hebrew ? "he" : "en"}" dir="${hebrew ? "rtl" : "ltr"}" data-crm-theme="green"><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><main id="app" style="padding:16px"></main><script type="module" src="/@id/__x00__virtual:vault-workdocs-ui"></script></body></html>`,
                        ),
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
    for (const scenario of ["one", "awaiting", "locked", "he-mobile"]) {
        const page = await browser.newPage({
            viewport:
                scenario === "he-mobile"
                    ? { width: 390, height: 844 }
                    : { width: 1280, height: 900 },
        });
        const errors = [];
        page.on("pageerror", (error) => errors.push(error.message));
        let accepted = 0;
        let calls = 0;
        const issued = Date.now();
        const status = () => ({
            total: 3,
            accepted,
            eligible: scenario === "awaiting" ? 0 : 3 - accepted,
            pending_request_key: null,
            source_configured: true,
        });
        await page.route("**/vault/api/**", async (route) => {
            const pathname = new URL(route.request().url()).pathname;
            if (pathname === "/vault/api/auth/status")
                return route.fulfill({
                    json: {
                        auth_mode: "email_riddle",
                        enrolled: true,
                        approved: true,
                        unlocked: true,
                        issued_at: new Date(issued).toISOString(),
                        expires_at: new Date(issued + 300000).toISOString(),
                        server_now: new Date().toISOString(),
                        step: "email_start",
                        challenge_id: null,
                        dev_question_mode: false,
                        question: null,
                        can_admin: true,
                    },
                });
            if (pathname === "/vault/api/imports/monday/workdocs")
                return route.fulfill({ json: status() });
            if (pathname.endsWith("/workdocs/next")) {
                calls++;
                assert.deepEqual(Object.keys(route.request().postDataJSON()), [
                    "request_key",
                ]);
                assert.match(
                    route.request().postDataJSON().request_key,
                    /^[a-f0-9-]{36}$/,
                );
                if (scenario === "locked")
                    return route.fulfill({
                        status: 423,
                        json: { message: "Fictional expired grant" },
                    });
                accepted++;
                return route.fulfill({
                    json: { state: "verified", ...status() },
                });
            }
            throw new Error("Unapproved test request");
        });
        await page.goto(
            `${origin}/workdocs-harness${scenario === "he-mobile" ? "?he" : ""}`,
        );
        const button = page.getByRole("button", {
            name:
                scenario === "he-mobile"
                    ? "ייבוא מסמך אחד"
                    : "Import one workdoc",
            exact: true,
        });
        await button.waitFor();
        if (scenario === "awaiting") {
            assert.equal(await button.isDisabled(), true);
        } else {
            await button.click();
            await page.waitForFunction(
                () => !document.body.textContent.includes("Working…"),
            );
            assert.equal(calls, 1);
            if (scenario === "locked")
                await page
                    .getByText(
                        "Unlock the Vault to continue the approved import.",
                    )
                    .waitFor();
            else {
                await page
                    .getByRole("status")
                    .filter({
                        hasText:
                            scenario === "he-mobile"
                                ? "המסמך המוגן נקלט"
                                : "Protected workdoc accepted",
                    })
                    .waitFor();
                await page
                    .getByRole("button", {
                        name: scenario === "he-mobile" ? "רענון" : "Refresh",
                        exact: true,
                    })
                    .click();
                assert.equal(calls, 1); // Refresh never starts another source read.
            }
        }
        assert.deepEqual(errors, []);
        assert.equal(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
            true,
        );
        if (scenario === "he-mobile")
            await page.screenshot({
                path: "/private/tmp/basecrm-vault-workdocs-he-mobile.png",
                fullPage: true,
            });
        results.push({
            scenario,
            passed: true,
            source_requests: 0,
            next_requests: calls,
        });
        await page.close();
    }
    console.log(JSON.stringify({ ok: true, scenarios: results }));
} finally {
    await browser.close();
    await server.close();
}
