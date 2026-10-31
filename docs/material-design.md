# Material Design 3

The CRM follows Material Design 3 component geometry, typography, interaction states, motion and elevation with a neutral product palette. Black, white and greys define the canvas, surfaces, text, navigation, tables and form fields in both light and dark modes. The Base default reserves purple shades for buttons, notices and count/status bubbles. Installations may select the shared green accent preset; neutral surface roles remain identical. This user preference supersedes the earlier pink accent preference and exact [Material 3 baseline colour](https://m3.material.io/styles/color/static/baseline) palette. All colours come from shared semantic tokens; pages must not introduce their own tints. The light application canvas remains near-white grey `#FAFAFA` through `--crm-canvas-color`. Brand names and logos remain installation-specific.

Accent roles are explicit and shared: light mode uses `#6750A4` with white text, and `#EADDFF` containers with `#21005D` text. Dark mode uses `#D0BCFF` with `#381E72` text, and `#4F378B` containers with `#EADDFF` text. These accents do not tint navigation, tables, fields or page surfaces.

## Installation themes and Hebrew

Deployment manifests select `theme: "purple" | "green"` and `locale: "en" | "he"`. Both values are validated and deployment-locked; a persisted settings row or user preference cannot replace them. Omitted values retain Base's purple/English defaults. Legacy free-form `color` is still ignored. The selected theme changes only shared accent roles, not component geometry or neutral canvas/surface/field/table/navigation roles.

| Green theme | Accent    | On accent | Container | On container |
| ----------- | --------- | --------- | --------- | ------------ |
| Light       | `#23654E` | `#FFFFFF` | `#E7F1EB` | `#173B2B`    |
| Dark        | `#8DD2B1` | `#093825` | `#234D3A` | `#C7ECD8`    |

These paired roles have contrast ratios of at least 6.9:1. Dark appearance remains independent of the installation theme. The green preset implements Niva's approved direction while purple remains the Base default.

Hebrew sets the initial document `lang="he"` and `dir="rtl"` before application rendering. The frontend retains those values on navigation. Locally bundled Noto Sans Hebrew supplies Hebrew glyphs; default English retains Roboto. Shared outlined fields, dialogs, menus, drawer, tables, logical pinning/resizing and directional icons use the same Material system. Email, URL, phone and exact decimal inputs keep their appropriate reading direction. Interface language never converts currencies, rewrites record names/content or changes stable status IDs.

English source messages and Hebrew catalogs belong to their packages. The host loads only installed package catalogs. Translate explicit interface text and metadata; never traverse domain payloads or translate arbitrary authored names, notes, saved-view names or custom field labels. Backend errors, validation and sign-in email use the installation locale as well.

The fictional [Hebrew profile](../installations/examples/hebrew/manifest.json) demonstrates the presentation independently of real installation settings. To exercise it against the existing isolated Alpha fixture database, keep Alpha running on 8091 and start a second local preview:

```sh
CRM_MANIFEST="$PWD/installations/examples/hebrew/manifest.json" APP_URL=http://127.0.0.1:8107 php artisan serve --host=127.0.0.1 --port=8107 --no-reload
```

Do not run `crm:install` or provisioning for this shared local preview. `tests/e2e/localization.spec.ts` uses reserved Alpha table accounts and strict fixture cleanup to verify Hebrew/green, persisted forms and return to English/purple. An actual Niva deployment keeps its own database and private manifest; the shared database above is only a local test arrangement.

## Shared implementation

- `packages/awove/crm-core/resources/css/tokens.css`: the shared neutral light/dark roles, scoped purple accent roles, shape scale, state opacities, motion and elevation tokens.
- `components.css`: reusable controls, outlined fields, cards, forms, menus, tooltips, sheets and dialogs, including portaled host components. Use these primitives for new UI; do not duplicate geometry or colours in a page.
- `core.css`: shell, page layout, drawer/rail and navigation. `table.css`: table structure, column controls and native table dialogs.
- `resources/js/lib/material.ts`: semantic field enhancement and interruptible interaction springs, applied after Vue updates and to dynamic portal content. DOM-owned nodes are not reparented.

Buttons use filled, outlined, tonal, elevated or text variants with shared 40px geometry and 48px compact touch targets. Outlined text fields are 56px high. Associated labels float on focus, remain floated when filled, and return when empty. Labels, border and placeholder transitions use the prescribed Web **standard easing `cubic-bezier(.2,0,0,1)` at 150ms**. Semantic label associations, supporting errors, native input behavior remain accessible. Forms use a shared 24px field-to-action gap.

## Motion and state

The current [M3 motion system](https://m3.material.io/styles/motion/overview/how-it-works) distinguishes spatial springs from effects springs. This utility CRM uses the **Standard** scheme. Interactive state layers retain velocity when interrupted: effects fast has mass 1, stiffness 3800 and damping ratio 1; press expansion uses spatial fast, stiffness 1400 and damping ratio .9. A single on-colour layer represents hover 8%, focus/pressed 10%, or dragged 16%; states do not stack. Disabled content/container use 38%/12%.

Predefined drawer, menu and dialog transitions use the current [Standard Web curve approximations](https://m3.material.io/styles/motion/overview/specs): spatial `cubic-bezier(.27,1.06,.18,1)` with fast/default/slow 350/500/750ms; effects fast `.31,.94,.34,1` at 150ms and default `.34,.8,.34,1` at 200ms. Native dialogs animate both entry and exit with discrete display/overlay transitions. Reduced-motion preferences remove movement and make spring state changes immediate. Keyboard focus and forced-colour support remain visible.

## Elevation

Shared shadow tokens map levels 0–5 to **0/1/3/6/8/12dp**. Outlined cards and filled/outlined buttons rest at level 0; elevated cards/buttons and modal drawers/sheets use level 1; menus use level 2; dialogs use level 3. Filled/tonal buttons rise to level 1 on hover and elevated buttons to level 2. Plain tooltips use the inverse surface without a shadow. Modal scrims use the scrim role at 32%. Container colour is selected independently from elevation, following [applying elevation](https://m3.material.io/styles/elevation/applying-elevation); deprecated surface tint is not applied over the neutral container roles.

Roboto and Material Symbols are bundled locally. Advanced table search, typed filters, multi-sort, column visibility/order/resize/pinning, saved views and permission boundaries are preserved. Quick-view links expose the current page and use a shared active indicator.

Validation: `npm run check`, `npm run types:check`, `npm run build`, and desktop/mobile Playwright workflows. `tests/e2e/material-design.spec.ts` covers neutral field and surface colours, scoped purple accents, actual field states, button interaction states, reduced motion, dark roles and dialog elevation. Existing contact, table, workflow and email sign-in checks exercise save/reload behavior.

Primary implementation references: [Standard spring tokens](https://github.com/androidx/androidx/blob/androidx-main/compose/material3/material3/src/commonMain/kotlin/androidx/compose/material3/tokens/StandardMotionTokens.kt), [state tokens](https://github.com/androidx/androidx/blob/androidx-main/compose/material3/material3/src/commonMain/kotlin/androidx/compose/material3/tokens/StateTokens.kt), [Material Web elevation geometry](https://github.com/material-components/material-web/blob/main/elevation/internal/_elevation.scss).
