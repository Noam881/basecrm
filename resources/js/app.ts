import "@fontsource/roboto/400.css";
import "@fontsource/roboto/500.css";
import "@fontsource/roboto/700.css";
import "@fontsource/noto-sans-hebrew/400.css";
import "@fontsource/noto-sans-hebrew/500.css";
import "@fontsource/noto-sans-hebrew/700.css";
import "@fontsource/material-symbols-outlined";
import { materialStateLayers } from "./lib/material";
import { configurePresentation, t } from "./lib/i18n";
import { createInertiaApp, router } from "@inertiajs/vue3";
import { createApp, h, type DefineComponent, type DirectiveBinding } from "vue";
import Shell from "../../vendor/awove/crm-core/resources/js/Shell.vue";
import AuthLayout from "@/layouts/AuthLayout.vue";
import SettingsLayout from "@/layouts/settings/Layout.vue";
const host = import.meta.glob<{ default: DefineComponent }>("./pages/**/*.vue");
const installed = import.meta.glob<{ default: DefineComponent }>([
    "../../vendor/awove/*/resources/js/pages/**/*.vue",
]);
void createInertiaApp({
    title: (title) => (title ? `${title} · CRM` : "CRM"),
    layout: (name) =>
        name.startsWith("auth/")
            ? AuthLayout
            : name.startsWith("settings/")
              ? [Shell, SettingsLayout]
              : Shell,
    resolve: async (name) => {
        let loader: (() => Promise<{ default: DefineComponent }>) | undefined =
            host[`./pages/${name}.vue`];
        if (!loader) {
            const matches = Object.entries(installed).filter(([path]) =>
                path.endsWith(`/pages/${name}.vue`),
            );
            if (matches.length > 1)
                throw new Error(
                    `Page ${name} is declared by multiple installed packages.`,
                );
            loader = matches[0]?.[1];
        }
        if (!loader) throw new Error(`Page ${name} is not installed.`);
        return (await loader()).default;
    },
    setup({ el, App, props, plugin }) {
        configurePresentation(
            props.initialPage.props.brand as {
                locale?: unknown;
                theme?: unknown;
            },
        );
        router.on("navigate", (event) => {
            configurePresentation(
                event.detail.page.props.brand as {
                    locale?: unknown;
                    theme?: unknown;
                },
            );
        });
        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(materialStateLayers)
            .directive("focus", {
                mounted(
                    el: HTMLElement,
                    binding: DirectiveBinding<boolean | undefined>,
                ) {
                    if (binding.value !== false) el.focus();
                },
            });
        app.config.globalProperties.$t = t;
        app.mount(el);
    },
    progress: { color: "var(--md-sys-color-primary)" },
});
