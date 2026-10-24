# Modular CRM delivery status

Branch: `codex/modular-crm-implementation`, based on `3278c2e` (the existing deployed B-logo baseline). Local release candidate: shared host/core 1.1.0, core contract1, Customer Service/Executive/Operations1.0.0. Local implementation only: no pushes, deployment, real messages, integrations or customer imports are authorized by this request.

The complete seven-checkpoint build is **incomplete**. Current checkpoint: **1 and 2 complete; Checkpoint 3 next**. Do not infer later module completion from this document or navigation placeholders; unbuilt modules are not registered or displayed.

## Baseline, before edits

- `php artisan test --compact`: passed, 171 PostgreSQL tests / 2,499 assertions.
- `npm run check`: passed.
- `npm run typecheck`: passed.
- `npm run build`: passed.
- `docker-compose --profile full config --quiet`: passed.
- Original requirements DOCX: unavailable; preserved source scope in user prompt is the implementation source.

## Checkpoint 1 working changes

Existing Sales retained and extended with needs/estimated/final values, closing/loss details, typed dated authored communication, quotation metadata/states linked to retained document versions or links, and filtered overview. Generic source stages added without overwriting historical labels. Shared CSV import/export adds validated previews, explicit duplicate decisions and accessible formula-safe exports. Customer Service adds actual requests, feedback, history/reopen, document links, filters/overview and generic owner reminders, without Operations.

Module lifecycle adds independent capability validation, route/query guards, explicit optional-module permission grants, and a Sales-disabled workspace. Generic reminders are revalidated at delivery/list/read and do not store sensitive preview text. All data remains on disablement.

Checkpoint 1 integrated verification:

- `php artisan test --compact`: passed, 234 PostgreSQL tests / 3,529 assertions.
- Full Pint and PHPStan (512 MB): passed, 0 static errors.
- Formatting, TypeScript, production build: passed.
- Node clipboard security tests: passed, 2 tests.
- Complete Playwright desktop/mobile regression: passed, 40 cases / 3.1 minutes before the final company-note addition; the affected contact/company desktop/mobile workflows passed again afterward (2 cases / 19.8 seconds).
- Additive first-build Alpha/Beta fixture update and database/private-file restore rehearsal: passed; explicitly not a historical release upgrade.
- Physical optional-package absence build: passed; startup and production assets without either optional package, plus 24 Foundation tests / 293 assertions.
- Container image build: passed, mirrored Core and Customer Service copies; local `/up` and `/login` returned 200 on port 18091. Temporary smoke container removed.

Company-authored notes are now included in the shared intake/profile, scoped history, CSV import and guarded fixtures; final backend/static checks and affected desktop/mobile workflows passed. Earlier concurrent test commands collided on `base_test`; that run was discarded and the suite reran serially. An identity audit test caught empty creation defaults being recorded as entered fields; creation now omits empty defaults while updates retain cleared fields.

## Checkpoint 2 working changes

Optional Executive Management adds persisted management tasks, goal links, manual measurable goals, dated routine completions and a scoped overview. Goal values use decimal(18,4) strings in one explicit unit. Shared work and routine services preserve historical lead tasks, enforce typed module/status/permission boundaries, reject stale edits, retain completion/checklist history and invalidate obsolete owner reminders. Routines never generate their own next dates.

Optional Administrative Operations adds shared Customer/Supplier classifications, request completion/reopening, exact supplier orders/partial receipts and outstanding history, routines/checklists, overview filters, advanced tables and bounded CSV. Orders remain distinct from expenses/payments. Both modules work with Sales and Finance disabled and without requiring one another or Service. New optional/shared sensitive grants are explicit; identity responsibility never grants related-record access.

Shared controls now serve Service and Operations requests as well as Executive tasks. The core adds reusable CSV/document-link handling, current permission checks for classifications, query-state encoding and decimal-safe table display. Shared contact navigation returns to the workspace when Sales is unavailable. Neutral/purple global controls and existing brands are retained.

Checkpoint 2 integrated verification:

- `php artisan test --compact`: passed, 281 PostgreSQL tests / 4,369 assertions, including the final Sales-disabled shared-contact navigation checks.
- Executive acceptance: 10 backend cases included in the complete suite; desktop/mobile persisted workflows passed, 2 cases / 18.5 seconds.
- Operations acceptance: 13 backend cases included in the complete suite, including Upcoming filters and retained receipt-date consistency; desktop/mobile workflows passed, 2 cases / 33.9 seconds.
- Existing complete desktop/mobile browser regression: passed, 40 cases / 3.0 minutes. Screenshots visually checked for new desktop/mobile forms and advanced tables.
- Full PHPStan: passed, 0 errors. Types, Pint, frontend formatting and production build passed. Affected contact/company desktop/mobile workflows passed again after the final navigation change, 2 cases / 19.9 seconds.
- Node clipboard and exact decimal display: passed, 5 tests.
- Core-only build with all four optional packages physically removed: PHP discovery/assets and Foundation24/293 passed.
- Additive Alpha/Beta first-build database/private-file restore rehearsal: passed. This remains a synthetic first-build rehearsal, not a historical release upgrade.
- Final container build/startup and Compose configuration: passed; /up and /login returned 200, correct Alpha brand, Core/Service/Executive/Operations are mirrored package copies. Temporary smoke container removed.

## Reversible implementation decisions

- Spreadsheet transfer supports bounded UTF-8 CSV, not XLS/XLSX.
- Customer Service feedback: consistent 1–5 scale (1 lowest, 5 highest).
- Supporting Service documents use HTTPS links rather than duplicate upload storage; existing private Sales proposals remain unchanged.
- Synthetic generic configurations default currency to USD; real installations must choose their own single currency before financial entry. No live currency was changed.
- Historical deals with unknown closing/final values are labelled unknown, not silently assigned fabricated values.
- Dates, reminders, next actions and routine next occurrences are manual; no automatic recurring record generation or external synchronization.

## Remaining checkpoints

3. Shared employee references, restricted HR, and separately selectable Finance collections/expenses/payroll preparation.
4. Knowledge and **Marketing module**, optional cross-module links and no live integrations.
5. Assets, assignments/returns and maintenance.
6. Selected Nonprofit capabilities standalone and with Finance/Operations/Assets.
7. Cross-module/absent-package regression, desktop/mobile workflows, installation isolation and controlled additive update rehearsal.

Next runnable action: implement Checkpoint 3 minimal shared employee references, restricted HR, and separately selectable Finance collections/expenses/payroll preparation. Preserve the completed checkpoints; no push or remote deployment. Preserve legacy lead tasks; no fake lead rows or automatic recurrence.
