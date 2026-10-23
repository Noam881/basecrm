# Material Design 3

The CRM uses the exact published **Default Light and Default Dark baseline** roles in [Material 3 baseline colour](https://m3.material.io/styles/color/static/baseline), verified on 23 October 2026. The purple primary is `#6750A4`, the light surface is `#FEF7FF`, and on-primary-container is `#4F378B`. Dark roles are equally explicit; no installation colour override, extra tint overlay or neutral-only palette replaces these values. Brand names and logos remain installation-specific.

## Shared implementation

- `packages/awove/crm-core/resources/css/tokens.css`: the complete 50-role light/dark palettes, shape scale, state opacities, motion and elevation tokens.
- `components.css`: reusable controls, outlined fields, cards, forms, menus, tooltips, sheets and dialogs, including portaled host components. Use these primitives for new UI; do not duplicate geometry or colours in a page.
- `core.css`: shell, page layout, drawer/rail and navigation. `table.css`: table structure, column controls and native table dialogs.
- `resources/js/lib/material.ts`: semantic field enhancement and interruptible interaction springs, applied after Vue updates and to dynamic portal content. DOM-owned nodes are not reparented.

Buttons use filled, outlined, tonal, elevated or text variants with shared 40px geometry and 48px compact touch targets. Outlined text fields are 56px high. Associated labels float on focus, remain floated when filled, and return when empty. Labels, border and placeholder transitions use the prescribed Web **standard easing `cubic-bezier(.2,0,0,1)` at 150ms**. Semantic label associations, supporting errors, native input behavior and password visibility controls remain accessible. Forms use a shared 24px field-to-action gap.

## Motion and state

The current [M3 motion system](https://m3.material.io/styles/motion/overview/how-it-works) distinguishes spatial springs from effects springs. This utility CRM uses the **Standard** scheme. Interactive state layers retain velocity when interrupted: effects fast has mass 1, stiffness 3800 and damping ratio 1; press expansion uses spatial fast, stiffness 1400 and damping ratio .9. A single on-colour layer represents hover 8%, focus/pressed 10%, or dragged 16%; states do not stack. Disabled content/container use 38%/12%.

Predefined drawer, menu and dialog transitions use the current [Standard Web curve approximations](https://m3.material.io/styles/motion/overview/specs): spatial `cubic-bezier(.27,1.06,.18,1)` with fast/default/slow 350/500/750ms; effects fast `.31,.94,.34,1` at 150ms and default `.34,.8,.34,1` at 200ms. Native dialogs animate both entry and exit with discrete display/overlay transitions. Reduced-motion preferences remove movement and make spring state changes immediate. Keyboard focus and forced-colour support remain visible.

## Elevation

Shared shadow tokens map levels 0–5 to **0/1/3/6/8/12dp**. Outlined cards and filled/outlined buttons rest at level 0; elevated cards/buttons and modal drawers/sheets use level 1; menus use level 2; dialogs use level 3. Filled/tonal buttons rise to level 1 on hover and elevated buttons to level 2. Plain tooltips use the inverse surface without a shadow. Modal scrims use the scrim role at 32%. Container colour is selected independently from elevation, following [applying elevation](https://m3.material.io/styles/elevation/applying-elevation); deprecated surface tint is not applied over the baseline container roles.

Roboto and Material Symbols are bundled locally. Advanced table search, typed filters, multi-sort, column visibility/order/resize/pinning, saved views and permission boundaries are preserved. Quick-view links expose the current page and use a shared active indicator.

Validation: `npm run check`, `npm run types:check`, `npm run build`, and desktop/mobile Playwright workflows. `tests/e2e/material-design.spec.ts` covers actual field states, baseline colours, button interaction states, reduced motion, dark roles and dialog elevation. Existing contact, table, workflow and reset-password checks exercise save/reload behavior.

Primary implementation references: [Standard spring tokens](https://github.com/androidx/androidx/blob/androidx-main/compose/material3/material3/src/commonMain/kotlin/androidx/compose/material3/tokens/StandardMotionTokens.kt), [state tokens](https://github.com/androidx/androidx/blob/androidx-main/compose/material3/material3/src/commonMain/kotlin/androidx/compose/material3/tokens/StateTokens.kt), [Material Web elevation geometry](https://github.com/material-components/material-web/blob/main/elevation/internal/_elevation.scss).
