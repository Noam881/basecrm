import assert from "node:assert/strict";
import { existsSync, readdirSync, readFileSync } from "node:fs";
import path from "node:path";
import test from "node:test";
import { parse as parseSfc } from "@vue/compiler-sfc";
import { parse as parseTemplate } from "@vue/compiler-dom";
import { parse as parseJavaScript, parseExpression } from "@babel/parser";

const displayAttributes = new Set([
    "title",
    "title-label",
    "aria-label",
    "aria-description",
    "placeholder",
    "description",
    "label",
]);
const technicalText = /^(?:\(?CSV\)?|https?:\/\/|[^\s@]+@[^\s@]+)$/u;
const hasWords = (value) =>
    /[A-Za-z]/u.test(value) && !technicalText.test(value.trim());

// Inspect values that actually reach a rendered label. Conditions, field names,
// URLs, enum values and formatter arguments are not themselves display text.
function displayLiterals(node, errors) {
    if (!node) return;
    switch (node.type) {
        case "StringLiteral":
            if (hasWords(node.value)) errors.push(node.value);
            return;
        case "TemplateLiteral":
            if (node.quasis.some((part) => hasWords(part.value.cooked ?? "")))
                errors.push(
                    node.quasis
                        .map(
                            (part, index) =>
                                `${part.value.cooked ?? ""}${index < node.expressions.length ? "{value}" : ""}`,
                        )
                        .join(""),
                );
            for (const expression of node.expressions)
                displayLiterals(expression, errors);
            return;
        case "ConditionalExpression":
            displayLiterals(node.consequent, errors);
            displayLiterals(node.alternate, errors);
            return;
        case "LogicalExpression":
            displayLiterals(node.left, errors);
            displayLiterals(node.right, errors);
            return;
        case "BinaryExpression":
            if (node.operator === "+") {
                displayLiterals(node.left, errors);
                displayLiterals(node.right, errors);
            }
            return;
        case "CallExpression":
            // t() owns the entire first argument, including conditional keys.
            // Other calls are display formatters receiving stable input keys.
            return;
        case "TSAsExpression":
        case "TSNonNullExpression":
        case "ParenthesizedExpression":
            displayLiterals(node.expression, errors);
            return;
        case "MemberExpression":
            if (node.object.type === "ObjectExpression")
                displayLiterals(node.object, errors);
            return;
        case "ObjectExpression":
            for (const property of node.properties)
                if (property.type === "ObjectProperty")
                    displayLiterals(property.value, errors);
            return;
    }
}
function untranslatedUi(source) {
    const { descriptor, errors: parseErrors } = parseSfc(source);
    assert.equal(parseErrors.length, 0, "Vue source must parse");
    if (!descriptor.template) return [];
    const errors = [];
    const expression = (value) =>
        displayLiterals(
            parseExpression(value, { plugins: ["typescript"] }),
            errors,
        );
    const visit = (node) => {
        if (node.type === 1) {
            const isSymbol = node.props.some(
                (attribute) =>
                    attribute.type === 6 &&
                    attribute.name === "class" &&
                    attribute.value?.content.includes("material-symbols"),
            );
            if (isSymbol || node.tag === "code") return;
            for (const attribute of node.props) {
                if (
                    attribute.type === 6 &&
                    displayAttributes.has(attribute.name) &&
                    attribute.value &&
                    hasWords(attribute.value.content)
                )
                    errors.push(attribute.value.content);
                if (
                    attribute.type === 7 &&
                    attribute.name === "bind" &&
                    displayAttributes.has(attribute.arg?.content) &&
                    attribute.exp
                )
                    expression(attribute.exp.content);
            }
        }
        if (node.type === 2 && hasWords(node.content))
            errors.push(node.content.trim());
        if (node.type === 5) expression(node.content.content);
        for (const child of node.children ?? []) visit(child);
    };
    visit(parseTemplate(descriptor.template.content));
    return errors;
}
function sourceFiles(directory, extensions = [".vue"]) {
    if (!existsSync(directory)) return [];
    return readdirSync(directory, { withFileTypes: true }).flatMap((entry) =>
        entry.isDirectory()
            ? sourceFiles(path.join(directory, entry.name), extensions)
            : extensions.some((extension) => entry.name.endsWith(extension))
              ? [path.join(directory, entry.name)]
              : [],
    );
}
function packageRoots(directory = "packages/awove") {
    if (!existsSync(directory)) return [];
    return readdirSync(directory, { withFileTypes: true })
        .filter((entry) => entry.isDirectory())
        .map((entry) => path.join(directory, entry.name));
}
const packages = packageRoots();
const uiRoots = [
    "resources/js/pages",
    "resources/js/layouts",
    ...packages.map((directory) => path.join(directory, "resources/js")),
];
// Match the front-end loader. Backend-only catalogs cannot mask a missing browser message.
const catalogFiles = [
    "lang/he.json",
    ...packages.map((directory) =>
        path.join(directory, "resources/lang/he.json"),
    ),
].filter(existsSync);
const messages = Object.assign(
    {},
    ...catalogFiles.map((filename) =>
        JSON.parse(readFileSync(filename, "utf8")),
    ),
);
function literalKeys(node) {
    if (!node) return [];
    if (node.type === "StringLiteral") return [node.value];
    if (node.type === "TemplateLiteral" && node.expressions.length === 0)
        return [node.quasis[0].value.cooked ?? ""];
    if (node.type === "ConditionalExpression")
        return [
            ...literalKeys(node.consequent),
            ...literalKeys(node.alternate),
        ];
    if (
        [
            "TSAsExpression",
            "TSNonNullExpression",
            "ParenthesizedExpression",
        ].includes(node.type)
    )
        return literalKeys(node.expression);
    return [];
}
function translationKeys(ast) {
    const keys = [];
    const visit = (node) => {
        if (!node || typeof node !== "object") return;
        const callee = node.type === "CallExpression" ? node.callee : null;
        const isTranslator =
            callee?.type === "Identifier"
                ? ["t", "tr", "$t"].includes(callee.name)
                : callee?.type === "MemberExpression" &&
                  callee.property?.type === "Identifier" &&
                  callee.property.name === "$t";
        if (isTranslator) keys.push(...literalKeys(node.arguments[0]));
        for (const [key, value] of Object.entries(node)) {
            if (["loc", "start", "end", "extra"].includes(key)) continue;
            if (Array.isArray(value)) value.forEach(visit);
            else if (value && typeof value === "object") visit(value);
        }
    };
    visit(ast);
    return keys;
}
function keysInSource(source, filename = "fixture.vue") {
    if (!filename.endsWith(".vue"))
        return translationKeys(
            parseJavaScript(source, {
                sourceType: "module",
                plugins: ["typescript", "jsx"],
            }),
        );
    const { descriptor, errors } = parseSfc(source);
    assert.equal(errors.length, 0, filename);
    const keys = [descriptor.script?.content, descriptor.scriptSetup?.content]
        .filter(Boolean)
        .flatMap((script) =>
            translationKeys(
                parseJavaScript(script, {
                    sourceType: "module",
                    plugins: ["typescript", "jsx"],
                }),
            ),
        );
    const expressionKeys = (value) => {
        try {
            keys.push(
                ...translationKeys(
                    parseExpression(value, { plugins: ["typescript"] }),
                ),
            );
        } catch {
            // Event handlers can be statement lists rather than expressions.
            keys.push(
                ...translationKeys(
                    parseJavaScript(value, {
                        plugins: ["typescript"],
                        allowReturnOutsideFunction: true,
                    }),
                ),
            );
        }
    };
    const visit = (node) => {
        if (node.type === 5) expressionKeys(node.content.content);
        if (node.type === 1)
            for (const attribute of node.props)
                if (
                    attribute.type === 7 &&
                    attribute.name !== "for" &&
                    attribute.exp
                )
                    expressionKeys(attribute.exp.content);
        for (const child of node.children ?? []) visit(child);
    };
    if (descriptor.template) visit(parseTemplate(descriptor.template.content));
    return keys;
}
function missingMessages(keys, catalog) {
    return [...new Set(keys)].filter(
        (key) =>
            hasWords(key) &&
            (!Object.hasOwn(catalog, key) ||
                !/[\u0590-\u05ff]/u.test(catalog[key])),
    );
}

test("UI coverage finds an untranslated alternate even when the first conditional branch is translated", () => {
    assert.deepEqual(
        untranslatedUi(
            '<template><button>{{ open ? t("Close form") : "+ Create lead" }}</button></template>',
        ),
        ["+ Create lead"],
    );
    assert.deepEqual(
        untranslatedUi(
            '<template><button>{{ open ? t("Close form") : t("+ Create lead") }}</button></template>',
        ),
        [],
    );
});

test("UI coverage finds English template text beginning with an interpolation", () => {
    assert.deepEqual(
        untranslatedUi(
            '<template><table :aria-label="`${title} records`" /></template>',
        ),
        ["{value} records"],
    );
    assert.deepEqual(
        untranslatedUi(
            "<template><table :aria-label=\"t('{title} records', { title })\" /></template>",
        ),
        [],
    );
});

test("UI coverage leaves authored records and stable control keys outside translation", () => {
    assert.deepEqual(
        untranslatedUi(
            '<template><span>{{ record.name }}</span><span>{{ errorFor("email") }}</span><option value="normal">{{ t("Normal") }}</option><span class="material-symbols-outlined">{{ rtl ? "arrow_back" : "arrow_forward" }}</span></template>',
        ),
        [],
    );
});

test("present packages and host labels cover every literal branch and parameterized accessibility message", () => {
    const failures = uiRoots
        .flatMap((directory) => sourceFiles(directory))
        .flatMap((filename) =>
            untranslatedUi(readFileSync(filename, "utf8")).map(
                (text) => `${filename}: ${text}`,
            ),
        );
    assert.deepEqual(failures, []);
});

test("message coverage detects a translated call whose Hebrew entry is absent", () => {
    const source = `<script setup>const fallback = tr("Missing message");</script><template><button :aria-label="t(active ? 'Active' : 'Missing alternate')">{{ $t('Save') }}</button></template>`;
    assert.deepEqual(
        missingMessages(keysInSource(source), {
            Active: "פעיל",
            Save: "שמירה",
        }).sort(),
        ["Missing alternate", "Missing message"],
    );
    assert.deepEqual(missingMessages(["Save"], { Save: "Save" }), ["Save"]);
    assert.deepEqual(
        missingMessages(["CSV", "(CSV)", "https://", "email@example.com"], {}),
        [],
    );
    assert.deepEqual(
        sourceFiles("/a-missing-optional-package/resources/js"),
        [],
    );
    assert.deepEqual(packageRoots("/a-missing-package-root"), []);
});

test("literal browser translation calls have a message in the available Hebrew catalogs", () => {
    const files = uiRoots.flatMap((directory) =>
        sourceFiles(directory, [".vue", ".ts", ".tsx"]),
    );
    const failures = files.flatMap((filename) =>
        missingMessages(
            keysInSource(readFileSync(filename, "utf8"), filename),
            messages,
        ).map((key) => `${filename}: ${key}`),
    );
    assert.deepEqual(failures, []);
});

// Domain labels may equal a system message (a task named "Save", for example).
// Translating them silently changes what the user authored, even though no DB write occurs.
function translatedAuthoredLabels(source, filename = "fixture.vue") {
    const findings = [];
    const inspect = (node) => {
        if (!node || typeof node !== "object") return;
        if (
            node.type === "CallExpression" &&
            node.callee.type === "Identifier" &&
            ["t", "tr", "$t"].includes(node.callee.name)
        ) {
            const argument = node.arguments[0];
            if (
                ["MemberExpression", "OptionalMemberExpression"].includes(
                    argument?.type,
                ) &&
                argument.object.type === "Identifier" &&
                ["entry", "item", "row", "field"].includes(
                    argument.object.name,
                ) &&
                argument.property.type === "Identifier" &&
                argument.property.name === "label"
            )
                findings.push(`${argument.object.name}.label`);
        }
        for (const [key, value] of Object.entries(node)) {
            if (["loc", "start", "end", "extra"].includes(key)) continue;
            if (Array.isArray(value)) value.forEach(inspect);
            else if (value && typeof value === "object") inspect(value);
        }
    };
    const { descriptor } = parseSfc(source);
    for (const script of [
        descriptor.script?.content,
        descriptor.scriptSetup?.content,
    ].filter(Boolean))
        inspect(
            parseJavaScript(script, {
                sourceType: "module",
                plugins: ["typescript"],
            }),
        );
    if (descriptor.template) {
        const visit = (node) => {
            if (node.type === 5)
                inspect(
                    parseExpression(node.content.content, {
                        plugins: ["typescript"],
                    }),
                );
            if (node.type === 1)
                for (const attribute of node.props)
                    if (
                        attribute.type === 7 &&
                        attribute.name !== "for" &&
                        attribute.exp
                    )
                        try {
                            inspect(
                                parseExpression(attribute.exp.content, {
                                    plugins: ["typescript"],
                                }),
                            );
                        } catch {}
            for (const child of node.children ?? []) visit(child);
        };
        visit(parseTemplate(descriptor.template.content));
    }
    return findings;
}

test("authored task, document, checklist and configured field labels stay outside translation", () => {
    assert.deepEqual(
        translatedAuthoredLabels(
            "<template><a>{{ tr(entry.label) }}</a><span>{{ t(field.label) }}</span></template>",
        ),
        ["entry.label", "field.label"],
    );
    assert.deepEqual(
        translatedAuthoredLabels(
            '<template><a>{{ entry.label }}</a><span>{{ field.label }}</span><h2>{{ tr("Linked work and routines") }}</h2></template>',
        ),
        [],
    );
    const failures = uiRoots
        .flatMap((directory) => sourceFiles(directory))
        .flatMap((filename) =>
            translatedAuthoredLabels(
                readFileSync(filename, "utf8"),
                filename,
            ).map((label) => `${filename}: ${label}`),
        );
    assert.deepEqual(failures, []);
});
