import { test as base, expect } from "@playwright/test";
import { execFileSync } from "node:child_process";

type Installation = "alpha" | "beta";

function cleanBrowserRecords(project: string, installations: Installation[]) {
    if (!["desktop", "mobile"].includes(project)) {
        throw new Error(
            "Browser cleanup requires a known isolated fixture account.",
        );
    }
    if (
        !installations.length ||
        installations.some(
            (installation) => !["alpha", "beta"].includes(installation),
        )
    ) {
        throw new Error(
            "Browser cleanup requires known isolated installations.",
        );
    }
    for (const installation of installations) {
        execFileSync("php", [
            "scripts/clean-browser-fixtures.php",
            installation,
            `--project=${project}`,
            "--apply",
        ]);
    }
}

export const test = base.extend<{
    runId: string;
    fixtureIsolation: void;
    installations: Installation[];
}>({
    installations: [["alpha", "beta"], { option: true }],
    runId: async ({}, use, info) => {
        await use(`${info.project.name}-${Date.now()}`);
    },
    fixtureIsolation: [
        async ({ runId, installations }, use, info) => {
            if (info.config.workers !== 1 || !runId) {
                throw new Error(
                    "Shared browser fixture accounts must run serially.",
                );
            }
            cleanBrowserRecords(info.project.name, installations);
            try {
                await use();
            } finally {
                cleanBrowserRecords(info.project.name, installations);
            }
        },
        { auto: true },
    ],
});
export { expect };
