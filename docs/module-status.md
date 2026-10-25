# Modular CRM delivery status

Branch: `codex/modular-crm-implementation`, based on `3278c2e` (the existing deployed B-logo baseline). Local release candidate: shared host/core 1.1.0, core contract1, Customer Service/Executive/Operations/HR/Finance/Knowledge/Marketing1.0.0. Local implementation only: no pushes, deployment, real messages, integrations or customer imports are authorized by this request.

The complete seven-checkpoint build is **incomplete**. Current checkpoint: **Checkpoints 1–4 complete; Checkpoint 5 next**. Do not infer later module completion from this document or navigation placeholders; unbuilt modules are not registered or displayed.

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

## Checkpoint 3 working changes

Minimal shared employee references persist separately from HR and logins, with stable IDs, name/active-only projections and explicit authorized contact/login links. References do not merge by name or create accounts. The contextual employee utility supports search, versioned edit/deactivate/reactivate, history and matching CSV. Payroll accepts retained inactive employees without recruitment or login accounts.

Optional HR owns private employment/roles/managers/responsibilities, vacancies/candidates/CVs, and manual onboarding/offboarding checklists. Required work must finish; optional work can be explicitly skipped without claiming completion. Completed and abandoned cases retain history. Final-pay information and documents require separate field grants; marking work done does not provision/revoke accounts or transmit payroll.

Finance declares separately enabled/granted collections, expenses and payroll preparation. Charges and expenses retain exact immutable dated payments, retry protection, derived balances/statuses, due-date expectations and payment-date actuals. Payroll snapshots agreed manual input definitions per exact employee/period, enforces required inputs before Ready/Sent, records manual transfer dates and preserves scoped supporting documents/history. No wages are calculated and no payments or messages are sent.

Shared fixes retain dormant optional table columns, widths, pins and view queries under a fresh user lock when visible preferences change. Incoming unauthorized settings remain rejected; merged limits cannot create unusable layouts. Repeated columns in different saved views are valid. Raw private preferences no longer leak through auth.user or legacy Leads props. Displayed timestamps use the existing user timezone/deployment-lock rules. New pages use existing global buttons, spacing, tables and neutral/purple design; employee select labels remain accessible.

Checkpoint 3 integrated verification:

- `php artisan test --compact`: passed, **337 PostgreSQL tests / 5,518 assertions**, 117.6 seconds, including all final shared preference/privacy/timezone fixes.
- Focused employee references10/129, HR15/280, Finance ledger14/363, Payroll10/127 and exact money2/25 passed. Ledger14/363 also passed after its final single-snapshot read fix. Shared preferences/Inertia boundary suites passed22/566.
- Eight new persisted desktop/mobile cases passed: employee utility2, HR2 (41.7s), Finance2 (40.8s), Payroll2 (16.2s final). Screenshots visually checked; final Payroll actions use shared 40px desktop/48px mobile targets. Guarded fixture cleanup passed.
- Existing desktop/mobile regression:42 of44 passed in the full run; two mobile cases could not connect after their long-running local Service/Operations preview sessions expired. After restarting those two local previews, both affected cases passed (32.1s). Thus all44 existing cases passed across these runs; no application failure was hidden.
- Full PHPStan0 errors, Pint, frontend formatting/types and final native production build passed. Shared clipboard/exact decimal Node tests5 passed. Compose configuration passed.
- Finance-only copied build with HR and all other optional code physically removed passed discovery/assets and Payroll10/127. HR-only copied build with Finance/other optional code absent passed discovery/assets and HR15/280. Final core-only discovery/assets and Foundation24/310 passed with all six optional packages absent; evidence is in `core-only-build-result.json`.
- Additive Alpha/Beta first-build database/private-file restore rehearsal passed through migrations800/850/900/910. This is synthetic first-build evidence, not an actual historical-release upgrade.
- Final container image built and started: `/up`200, `/login`200, correct Alpha brand and mirrored Core/Service/Executive/Operations/HR/Finance package copies. Invocation-owned smoke container removed. No push or remote deployment.

## Checkpoint 4 working changes

Optional Knowledge adds typed procedures, reusable materials and training; role/category/topic search; revision/update metadata; immutable protected document references; dated renewal history with explicitly entered next dates; and separately protected system login/item references. System references hold no credential/vault fields. Current materials, upcoming/expired renewals and actual missing owners/documents/revisions/dates are scoped; no universal stale-document interval is invented. Shared instruction links freshly authorize both the existing work parent and Knowledge entry, appearing on existing work profiles without duplicating completion history.

The **Marketing module** independently adds campaign plans/budgets/materials, content planning/manual publication, nonoverlapping inclusive performance periods, exact manual spend/reported leads and weighted aggregate cost per lead. Whole-period filters never invent daily proration. Budgets, actual spend, reported leads and accessible linked CRM opportunities remain separate. Advanced tables, matching bounded CSV, history, scoped document redirects and fresh content reminders use the existing shared controls/infrastructure.

The additive attribution extraction preserves original Lead IDs, versions/timestamps and exact raw JSONB in immutable snapshots while retaining the frozen hidden Core column. Canonical Marketing edits patch only changed fields in PostgreSQL, preserving unknown numeric/object values exactly. Legacy external campaign IDs never become CRM foreign keys; linked_campaign_id is separate. Generic source remains Core/Sales; attribution panel/table IDs and dormant layouts remain stable through disablement and permission loss.

A small shared Currency registry guards retained installed Finance, Marketing and Operations snapshot tables even while their capabilities are disabled. It returns a generic mismatch indication, never converts values and imports no optional package. Operations monetary agreement changes are blocked on mismatch while physical receipts/history remain available with original row currencies. LeadPanel retains its existing arguments and supports optional alternative permissions. The new work-reference contract projects only safe labels/local links after fresh scopes.

Checkpoint 4 integrated verification:

- Full PostgreSQL regression passed383 tests /6,287 assertions (174.7s). Final Knowledge-focused rerun after the browser-discovered current-owner default passed17/332; its controller/default and accessible field-label corrections are covered by final desktop/mobile workflows.
- Marketing physically standalone backend suite passed16/335, including exact JSONB extraction/patch preservation, period overlap, zero/weighted CPL, filtered totals, hidden relations and disabled surfaces. Shared/Operations/Currency focus passed26/522, including retained disabled/archived monetary snapshots and allowed nonmonetary receipts.
- New persisted desktop/mobile Knowledge2/2 (24.5s) and Marketing2/2 (21.8s) passed. Strict synthetic fixture cleanup passed. Final desktop/mobile screenshots were visually checked; new forms and history use global gaps/buttons and current-actor ownership defaults.
- Full PHPStan0 errors, Pint, frontend types/formatting/final native production build passed. Node clipboard/decimal tests5 and Compose configuration passed.
- Core-only copied discovery/assets with all eight optional packages physically absent passed Foundation + CurrencyRegistry27/327. Knowledge-only copied discovery/assets and its persisted library acceptance case passed1/32; optional work-link tests are not claimed run in that profile. Marketing-only copied discovery/assets and all16/335 passed with Finance/other optional code absent.
- Guarded local Alpha migrations1000/1100/1110 passed and every original Lead row stayed unchanged. Alpha/Beta additive first-build database/private-file restore rehearsal passed through these migrations; this is not an actual historical-release upgrade.
- Final local container image46ed1822fb73 built and started: /up200, /login200, correct Alpha brand, all nine installed package directories mirrored copies including Knowledge/Marketing. Invocation-owned smoke container removed. No push, live integration or remote deployment.
- Existing52-case desktop/mobile regression first finished46/52 (12.4m). Traces showed a desktop ERR_NETWORK_IO_SUSPENDED, initial Service fixture PostgreSQL08006 timeout and long browser execution gaps during the other timed-out checks; Operations had already saved successfully. All six unchanged cases then passed under a command-scoped idle-sleep guard (desktop1/1,1.3s; mobile5/5,1.0m). Thus all52 existing plus4 new cases passed across these coordinated runs; this is not claimed as one clean56-case run. No product assertions were weakened.

## Reversible implementation decisions

- Spreadsheet transfer supports bounded UTF-8 CSV, not XLS/XLSX.
- Knowledge renewals use Pending / In progress / Renewed defaults and a30-calendar-day upcoming view. Next expiry/reminder dates are explicit manual entries; document age is never inferred stale.
- Marketing periods are inclusive and must fit wholly inside date filters; no proration. Aggregate CPL rounds half-up once to2 decimals. Manual Published content requires its recorded actual date and HTTPS publication link.
- Customer Service feedback: consistent 1–5 scale (1 lowest, 5 highest).
- Supporting Service documents use HTTPS links rather than duplicate upload storage; existing private Sales proposals remain unchanged.
- Synthetic generic configurations default currency to USD; real installations must choose their own single currency before financial entry. No live currency was changed.
- Historical deals with unknown closing/final values are labelled unknown, not silently assigned fabricated values.
- Dates, reminders, next actions and routine next occurrences are manual; no automatic recurring record generation or external synchronization.

## Remaining checkpoints

5. Assets, assignments/returns and maintenance.
6. Selected Nonprofit capabilities standalone and with Finance/Operations/Assets.
7. Cross-module/absent-package regression, desktop/mobile workflows, installation isolation and controlled additive update rehearsal.

Next runnable action: implement Checkpoint 5 Asset Management, including employee/customer/location assignments, retained returns, maintenance and history. Preserve the completed checkpoints; no push or remote deployment. Preserve legacy lead tasks; no fake lead rows or automatic recurrence.
