import type { App } from "vue";

/** M3 Standard springs (mass 1): interruptible effects and spatial responses. */
const springs = {
    effects: { stiffness: 3800, dampingRatio: 1 },
    spatial: { stiffness: 1400, dampingRatio: 0.9 },
};
const selector =
    'button, a[href], summary, [role="button"], [role="menuitem"], [role="menuitemcheckbox"], [role="menuitemradio"], [role="option"], [role="checkbox"], [role="radio"]';
const reducedMotion = () =>
    window.matchMedia("(prefers-reduced-motion: reduce)").matches;

class Spring {
    value = 0;
    private velocity = 0;
    private target = 0;
    private frame = 0;
    private previous = 0;
    constructor(
        private element: HTMLElement,
        private render: (value: number) => void,
        private configuration = springs.effects,
    ) {}
    to(target: number, immediate = false) {
        this.target = target;
        if (immediate || reducedMotion()) {
            cancelAnimationFrame(this.frame);
            this.frame = 0;
            this.value = target;
            this.velocity = 0;
            this.render(target);
        } else if (!this.frame) {
            this.previous = performance.now();
            this.frame = requestAnimationFrame(this.tick);
        }
    }
    private tick = (now: number) => {
        this.frame = 0;
        if (!this.element.isConnected) return;
        let remaining = Math.min((now - this.previous) / 1000, 1 / 30);
        this.previous = now;
        const { stiffness, dampingRatio } = this.configuration;
        const damping = 2 * dampingRatio * Math.sqrt(stiffness);
        // Small fixed substeps keep the stiff effects spring stable at low frame rates.
        while (remaining > 0) {
            const dt = Math.min(remaining, 1 / 240);
            this.velocity +=
                (stiffness * (this.target - this.value) -
                    damping * this.velocity) *
                dt;
            this.value += this.velocity * dt;
            remaining -= dt;
        }
        if (
            Math.abs(this.target - this.value) < 0.0001 &&
            Math.abs(this.velocity) < 0.001
        ) {
            this.value = this.target;
            this.velocity = 0;
        } else {
            this.frame = requestAnimationFrame(this.tick);
        }
        this.render(this.value);
    };
}

type Interaction = {
    update: () => void;
    release: () => void;
    layer: HTMLElement;
};
const interactions = new WeakMap<HTMLElement, Interaction>();
const pressed = new Set<Interaction>();
const fields = new WeakSet<HTMLElement>();
let queued = false;

function enhanceControl(control: HTMLElement) {
    if (
        control.closest("[inert]") ||
        control.matches(".crm-material-select-trigger, .crm-checkbox")
    )
        return;
    control.classList.add("crm-interactive");
    const existing = interactions.get(control);
    if (existing) {
        if (existing.layer.parentElement !== control)
            control.append(existing.layer);
        existing.update();
        return;
    }
    control
        .querySelectorAll(":scope > md-ripple")
        .forEach((node) => node.remove());
    const layer = document.createElement("span");
    layer.className = "crm-state-layer";
    layer.setAttribute("aria-hidden", "true");
    control.append(layer);
    let hovering = false;
    let pressing = false;
    let radius = 0;
    let origin = [0, 0];
    const opacity = new Spring(control, (value) => {
        layer.style.opacity = String(Math.max(0, value));
    });
    const ink = new Spring(
        control,
        (value) => {
            layer.style.clipPath =
                value >= 0.999
                    ? ""
                    : `circle(${Math.max(0, value) * radius}px at ${origin[0]}px ${origin[1]}px)`;
        },
        springs.spatial,
    );
    const stateOpacity = (state: string, fallback: number) => {
        const value = getComputedStyle(control).getPropertyValue(
            `--md-sys-state-${state}-state-layer-opacity`,
        );
        return Number.parseFloat(value) || fallback;
    };
    const update = () => {
        if (reducedMotion()) ink.to(1, true);
        const disabled = control.matches(
            ':disabled, [aria-disabled="true"], [data-disabled]',
        );
        const focus =
            control.matches(":focus-visible") ||
            (!hovering && control.hasAttribute("data-highlighted"));
        opacity.to(
            disabled
                ? 0
                : pressing
                  ? stateOpacity("pressed", 0.1)
                  : focus
                    ? stateOpacity("focus", 0.1)
                    : hovering
                      ? stateOpacity("hover", 0.08)
                      : 0,
        );
    };
    const interaction: Interaction = {
        layer,
        update,
        release() {
            pressing = false;
            pressed.delete(interaction);
            update();
        },
    };
    interactions.set(control, interaction);
    control.addEventListener("pointerenter", (event) => {
        hovering = event.pointerType !== "touch";
        update();
    });
    control.addEventListener("pointerleave", () => {
        hovering = false;
        update();
    });
    control.addEventListener("focusin", update);
    control.addEventListener("focusout", () => {
        interaction.release();
    });
    control.addEventListener("pointerdown", (event) => {
        if (
            event.button !== 0 ||
            control.matches(':disabled,[aria-disabled="true"],[data-disabled]')
        )
            return;
        pressing = true;
        pressed.add(interaction);
        const box = layer.getBoundingClientRect();
        origin = [event.clientX - box.left, event.clientY - box.top];
        radius = Math.hypot(
            Math.max(origin[0], box.width - origin[0]),
            Math.max(origin[1], box.height - origin[1]),
        );
        ink.to(0, true);
        ink.to(1);
        update();
    });
    control.addEventListener("keydown", (event) => {
        if (!event.repeat && [" ", "Enter"].includes(event.key)) {
            pressing = true;
            pressed.add(interaction);
            layer.style.clipPath = "";
            update();
        }
    });
    update();
}

function syncField(
    control: HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement,
) {
    const label = control.labels?.[0];
    if (!label) return;
    const nestedText = label.querySelector<HTMLElement>(".crm-field-label");
    if (label.contains(control) && !nestedText) return;
    const labelText = nestedText ?? label;
    const container = label.contains(control)
        ? label
        : control.closest<HTMLElement>(".crm-field");
    if (
        !container ||
        container.querySelectorAll(
            "input:not([type=checkbox]):not([type=radio]):not([type=hidden]), textarea, select",
        ).length !== 1
    )
        return;
    container.classList.add("crm-outlined-field");
    labelText.classList.add("crm-floating-label");
    container.dataset.filled = String(
        control.value.length > 0 ||
            control instanceof HTMLSelectElement ||
            (control instanceof HTMLInputElement &&
                [
                    "date",
                    "time",
                    "datetime-local",
                    "month",
                    "week",
                    "file",
                ].includes(control.type)),
    );
    container.dataset.disabled = String(control.disabled);
    const error = container.querySelector<HTMLElement>(
        '[data-slot="input-error"]',
    );
    if (error) {
        const invalid = Boolean(error.textContent?.trim());
        if (control.getAttribute("aria-invalid") !== String(invalid))
            control.setAttribute("aria-invalid", String(invalid));
        if (control.id) {
            error.id ||= `${control.id}-error`;
            const describedBy = new Set(
                (control.getAttribute("aria-describedby") ?? "")
                    .split(" ")
                    .filter(Boolean),
            );
            describedBy.add(error.id);
            control.setAttribute(
                "aria-describedby",
                [...describedBy].join(" "),
            );
        }
    }
    container.dataset.invalid = String(
        control.getAttribute("aria-invalid") === "true",
    );
    if (!fields.has(control)) {
        fields.add(control);
        for (const event of ["input", "change", "focus", "blur"])
            control.addEventListener(event, () => syncField(control));
        control.form?.addEventListener("reset", () =>
            requestAnimationFrame(() => syncField(control)),
        );
    }
}

function enhanceDocument() {
    queued = false;
    document.querySelectorAll<HTMLElement>(selector).forEach(enhanceControl);
    document
        .querySelectorAll<
            HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement
        >("input,textarea,select")
        .forEach((control) => {
            if (
                control.matches(
                    '[type="hidden"],[type="checkbox"],[type="radio"],[type="range"],[type="color"],[autocomplete="one-time-code"],.md-table-search,.md-dialog-search,.md-page-size',
                ) ||
                control.closest(
                    '[data-slot="input-otp"], .md-table-search, .md-dialog-search, .md-page-size',
                )
            )
                return;
            syncField(control);
        });
}
function scheduleEnhancement() {
    if (!queued) {
        queued = true;
        requestAnimationFrame(enhanceDocument);
    }
}
export const materialStateLayers = {
    install(app: App) {
        app.mixin({
            mounted: scheduleEnhancement,
            updated: scheduleEnhancement,
        });
        for (const event of ["pointerup", "pointercancel", "keyup"])
            document.addEventListener(event, () =>
                [...pressed].forEach((item) => item.release()),
            );
        window.addEventListener("blur", () =>
            [...pressed].forEach((item) => item.release()),
        );
        window
            .matchMedia("(prefers-reduced-motion: reduce)")
            .addEventListener("change", scheduleEnhancement);
        new MutationObserver(scheduleEnhancement).observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: [
                "disabled",
                "aria-disabled",
                "data-disabled",
                "data-highlighted",
                "aria-invalid",
                "value",
            ],
        });
        scheduleEnhancement();
    },
};
