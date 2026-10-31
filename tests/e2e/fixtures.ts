import { test as base, expect } from "@playwright/test";
import { execFileSync } from "node:child_process";

function cleanBrowserRecords(project: string) {
    if (!["desktop", "mobile"].includes(project)) {
        throw new Error(
            "Browser cleanup requires a known isolated fixture account.",
        );
    }
    for (const installation of ["alpha", "beta"]) {
        execFileSync("php", [
            "scripts/clean-browser-fixtures.php",
            installation,
            `--project=${project}`,
            "--apply",
        ]);
    }
}

export const test = base.extend<{ runId: string; fixtureIsolation: void }>({
    runId: async ({}, use, info) => {
        await use(`${info.project.name}-${Date.now()}`);
    },
    fixtureIsolation: [
        async ({ runId }, use, info) => {
            if (info.config.workers !== 1 || !runId) {
                throw new Error(
                    "Shared browser fixture accounts must run serially.",
                );
            }
            cleanBrowserRecords(info.project.name);
            try {
                await use();
            } finally {
                cleanBrowserRecords(info.project.name);
            }
        },
        { auto: true },
    ],
});
export { expect };
