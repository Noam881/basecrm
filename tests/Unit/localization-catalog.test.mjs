import assert from "node:assert/strict";
import { existsSync, readdirSync, readFileSync } from "node:fs";
import path from "node:path";
import test from "node:test";

const packages = readdirSync("packages/awove").map((name) =>
    path.join("packages/awove", name),
);
const catalogs = [
    "lang/he.json",
    ...packages.flatMap((root) => [
        path.join(root, "resources/lang/he.json"),
        path.join(root, "resources/lang/backend/he.json"),
    ]),
].filter(existsSync);
const placeholders = (text) =>
    [...text.matchAll(/\{([A-Za-z][A-Za-z0-9_]*)\}|:([A-Za-z][A-Za-z0-9_]*)/g)]
        .map((match) => match[1] ?? match[2])
        .sort();

test("installed Hebrew catalogs retain interpolation values and contain real localized messages", () => {
    assert.ok(catalogs.length >= 2);
    for (const filename of catalogs) {
        const messages = JSON.parse(readFileSync(filename, "utf8"));
        for (const [source, translated] of Object.entries(messages)) {
            assert.equal(typeof translated, "string", `${filename}: ${source}`);
            assert.ok(translated.trim(), `${filename}: ${source}`);
            assert.deepEqual(
                placeholders(translated),
                placeholders(source),
                `${filename}: ${source} must retain every interpolation value`,
            );
        }
    }
    const core = JSON.parse(
        readFileSync("packages/awove/crm-core/resources/lang/he.json", "utf8"),
    );
    for (const label of ["Leads", "Email address", "Columns", "Save changes"]) {
        assert.match(core[label], /[\u0590-\u05ff]/u, label);
    }
});

test("shared UI words have one translation independent of optional package selection", () => {
    const messages = new Map();
    for (const filename of catalogs.filter(
        (file) => !file.includes("/backend/"),
    )) {
        for (const [source, translated] of Object.entries(
            JSON.parse(readFileSync(filename, "utf8")),
        )) {
            if (messages.has(source))
                assert.equal(
                    translated,
                    messages.get(source),
                    `Conflicting shared translation for ${source} in ${filename}`,
                );
            messages.set(source, translated);
        }
    }
});
