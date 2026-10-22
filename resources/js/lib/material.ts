import type { App } from "vue";
const selector =
    "button, .crm-button, .crm-secondary, .crm-sidebar nav a, .crm-sidebar-bottom a, .crm-tabs a, .crm-stage-row, .crm-back, .crm-pagination a";
function attachStateLayers(root: unknown) {
    if (!(root instanceof HTMLElement)) return;
    for (const control of [
        root,
        ...root.querySelectorAll<HTMLElement>(selector),
    ]) {
        if (!control.matches(selector)) continue;
        let ripple = control.querySelector("md-ripple");
        if (!ripple) {
            ripple = document.createElement("md-ripple");
            ripple.setAttribute("aria-hidden", "true");
            control.appendChild(ripple);
        }
        ripple.toggleAttribute("disabled", control.matches(":disabled"));
    }
}
export const materialStateLayers = {
    install(app: App) {
        app.mixin({
            mounted() {
                attachStateLayers(this.$el);
            },
            updated() {
                attachStateLayers(this.$el);
            },
        });
    },
};
