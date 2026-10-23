# Base CRM repository

This is the independent Base CRM project. Its only canonical Git remote is `https://github.com/Noam881/basecrm.git`.

- Work from this repository root and verify the remote before publishing.
- Never push CRM code or open CRM pull requests in `Noam881/awove.cloud`; it is a different project.
- Use `codex/` branches for future changes and follow `docs/source-of-truth.md`.
- Keep local environment files, credentials, databases, uploads, generated dependencies and real customer information out of Git.
- Preserve the existing installation boundaries and run the relevant checks documented in `README.md`.

## Shared UI conventions

- Define reusable button, field, card and form appearance only in `packages/awove/crm-core/resources/css/components.css`. Layout belongs in `core.css`; table-specific structure belongs in `table.css`. Do not copy control geometry into pages, modules or dialogs.
- Reuse `crm-button` / `crm-secondary` or the host `Button` component, which delegates to those same global classes. Reuse `crm-input` / `crm-label` through the host Input/Label components, or the core's semantic native controls.
- Use `crm-form` for stacked forms, `crm-form-grid` for two-column forms, `crm-field` for label/control/help groups, `crm-form-action` for action rows, `crm-flow` for spaced content blocks, and `crm-card` for surfaces. A normal field-to-action gap is 24px; never fix touching controls with a one-off button margin.
- Core and installed extensions must use the same primitives. Change global tokens/modifiers when needed; use page-specific CSS only for genuine page layout. Preserve compact table/menu layouts, OTP internals, keyboard access and reduced-motion behavior.
- For new or changed forms, inspect desktop and mobile spacing and verify the actual save/reload flow. Demo identities use the guarded Hebrew fixture catalog with fictional email/phone data.
