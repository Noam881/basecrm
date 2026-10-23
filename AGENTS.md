# Base CRM repository

This is the independent Base CRM project. Its only canonical Git remote is `https://github.com/Noam881/basecrm.git`.

- Work from this repository root and verify the remote before publishing.
- Never push CRM code or open CRM pull requests in `Noam881/awove.cloud`; it is a different project.
- Use `codex/` branches for future changes and follow `docs/source-of-truth.md`.
- Keep local environment files, credentials, databases, uploads, generated dependencies and real customer information out of Git.
- Preserve the existing installation boundaries and run the relevant checks documented in `README.md`.

## Shared UI conventions

- Keep the CRM palette neutral: black, white and greys for the canvas, surfaces, text, navigation, tables and fields. Pink shades are reserved for buttons, notices and count/status bubbles. Use the shared semantic roles in `tokens.css`; do not add page-local colour hex values or restore purple/tinted surfaces. This palette preference supersedes the earlier exact Material 3 baseline colours. Preserve Material 3 Standard motion springs/curves and shared elevation levels documented in `docs/material-design.md`. Outlined-field labels retain Web standard easing at 150ms. Use `--crm-canvas-color` for the app/auth canvas and uncontained field notches; light mode remains near-white grey `#FAFAFA`.

- Define reusable button, field, card and form appearance only in `packages/awove/crm-core/resources/css/components.css`. Layout belongs in `core.css`; table-specific structure belongs in `table.css`. Do not copy control geometry into pages, modules or dialogs.
- Reuse `crm-button` / `crm-secondary` or the host `Button` component, which delegates to those same global classes. Reuse `crm-input` / `crm-label` through the host Input/Label components, or the core's semantic native controls.
- Use `crm-form` for stacked forms, `crm-form-grid` for two-column forms, `crm-field` for label/control/help groups, `crm-form-action` for action rows, `crm-flow` for spaced content blocks, and `crm-card` for surfaces. A normal field-to-action gap is 24px; never fix touching controls with a one-off button margin.
- Core and installed extensions must use the same primitives. Change global tokens/modifiers when needed; use page-specific CSS only for genuine page layout. Preserve compact table/menu layouts, OTP internals, keyboard access and reduced-motion behavior.
- For new or changed forms, inspect desktop and mobile spacing and verify the actual save/reload flow. Demo identities use the guarded fixture catalog with Jewish/Hebrew names written in English and fictional email/phone data. Keep future synthetic names in English too.

- Lead tables present contact/company names first. Enquiry titles are optional, hidden by default and available through the column chooser; preserve existing personal column choices.

## Browser fixture isolation

- Workflow and contact-edit browser tests must use the dedicated table account for their Playwright project (desktop/mobile), never the human review account. Keep the three canonical review customers and leads intact.
- Run strict fixture cleanup in both `beforeEach` and `afterEach`. Match only reserved test-account ownership and exact fictional test-email/run identifiers; preserve unrelated records and canonical fixtures.
- Use natural Jewish/Hebrew names written in English. Never put a device name, timestamp or test run ID in a customer display name. Put run identifiers only in fictional `example.test` email addresses.
- Keep recovery snapshots private and Git-ignored. Never commit local backups, fixture credentials or live customer data.
