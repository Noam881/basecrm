import { ref } from "vue";

export type Locale = "en" | "he";
export type Theme = "purple" | "green";
type Dictionary = Record<string, string>;
type Parameters = Record<string, string | number>;

// Only installed packages contribute UI messages; authored record content is never traversed.
const catalogs = import.meta.glob<Dictionary>(
    ["../../../lang/he.json", "../../../vendor/awove/*/resources/lang/he.json"],
    { eager: true, import: "default" },
);
const hebrew: Dictionary = Object.assign(
    Object.create(null),
    ...Object.values(catalogs),
);
export const locale = ref<Locale>(
    typeof document !== "undefined" && document.documentElement.lang === "he"
        ? "he"
        : "en",
);
export const theme = ref<Theme>(
    typeof document !== "undefined" &&
        document.documentElement.dataset.crmTheme === "green"
        ? "green"
        : "purple",
);

export function localeTag(): "en-US" | "he-IL" {
    return locale.value === "he" ? "he-IL" : "en-US";
}

export function isRtl(): boolean {
    return locale.value === "he";
}

export function t(
    text: string | number | null | undefined,
    parameters: Parameters = {},
): string {
    const source = text == null ? "" : String(text);
    const value = locale.value === "he" ? (hebrew[source] ?? source) : source;
    return value.replace(
        /\{([A-Za-z][A-Za-z0-9_]*)\}|:([A-Za-z][A-Za-z0-9_]*)/g,
        (
            placeholder,
            braces: string | undefined,
            colon: string | undefined,
        ) => {
            const key = braces ?? colon!;
            return Object.hasOwn(parameters, key)
                ? String(parameters[key])
                : placeholder;
        },
    );
}

/** Translate code-owned default label maps before applying any authored overrides. */
export function translateLabels(
    labels: Readonly<Record<string, string>>,
): Record<string, string> {
    return Object.fromEntries(
        Object.entries(labels).map(([key, label]) => [key, t(label)]),
    );
}

export function configurePresentation(
    brand: { locale?: unknown; theme?: unknown } = {},
): void {
    locale.value = brand.locale === "he" ? "he" : "en";
    theme.value = brand.theme === "green" ? "green" : "purple";
    if (typeof document !== "undefined") {
        document.documentElement.lang = locale.value;
        document.documentElement.dir = isRtl() ? "rtl" : "ltr";
        document.documentElement.dataset.crmTheme = theme.value;
    }
}
