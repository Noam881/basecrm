# Modular CRM delivery status

Branch: `codex/modular-crm-implementation`, based on `3278c2e` (the existing deployed B-logo baseline). Local release candidate: shared host/Core 1.1.0, Core contract 1, and nine optional business packages at 1.0.0. Local implementation only: no pushes, deployment, real messages, integrations or customer imports are authorized by this request.

The complete seven-checkpoint local build is **complete**. All ten source modules are implemented and tested, with optional capabilities selected per installation. Current checkpoint: **Checkpoint 7 complete**. Source-deferred features and production acceptance remain outside this delivery.

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

## Delivery state

All seven checkpoints are complete locally. The final backend, complete desktop/mobile browser regression, independent package profiles, Alpha/Beta isolation/restore and container checks passed. No required implementation or verification remains for this source scope. No push or remote deployment was performed or is authorized by this request.

## Checkpoint 5 working changes

Optional Asset Management adds the generic register, stable unique Asset IDs, configurable terminology/status labels, shared employee/customer/location assignments, versioned expected-return edits, retained dated returns/condition and one-current-assignment enforcement. Maintenance may coexist with an assignment; physical state transitions respect other ongoing work. Completed and abandoned actions retain their history. Next dates are manual reminders, never recurrence generation.

Optional exact maintenance costs retain the original completion amount and append every late entry/correction separately; current and historical amounts/documents have explicit field grants. A cost creates no Finance transaction. Parent ownership, fresh identities/permissions, versions and module availability scope every child, table, CSV, document and reminder. The slice uses existing global controls, tables, history, private links and reminder infrastructure while Sales, HR, Operations and Finance are disabled.

Checkpoint 5 integrated verification:

- Complete PostgreSQL backend regression passed403 tests /6,644 assertions (279.3s); focused Assets20/355 passed22.4s, including complete CSV fields/formula defense.
- Final persisted desktop/mobile Assets workflows passed2/2 (1.3m), with strict synthetic cleanup and visually checked global spacing/compact status badges. Current185.35 and original175.35 cost history persist separately.
- Full PHPStan0 errors, fresh-cache Pint, frontend types/format/final production build, Node5 and Compose configuration passed.
- Core-only copied discovery/assets passed with all nine optional packages physically absent; Foundation+CurrencyRegistry27/327.
- Assets-only copied discovery/assets and full20/353 acceptance passed with Finance/HR/Operations and every other optional package physically removed. The first run exposed two test-only assumptions that Finance tables existed; snapshot assertions now require unchanged counts or continued schema absence. The two corrected installed-package cases passed2/46. Old56 browser regression:54 passed in the complete run (7.1m); Service and Finance desktop cases initially failed during root-owned preview restart/origin corrections. Both unchanged workflows then passed2/2 (35.3s) after fixing those local settings. All56 old +2 new distinct cases passed across these coordinated runs; this is not a claim of a single clean58-case run.
- Additive Alpha/Beta first-build migration and database/private-file backup/restore passed through Assets001200; retained synthetic data fingerprints match. This is not an upgrade from a historical release.
- Final container image ca862456be2962fcc6ffa84d5c7adcbb57e73609978ec8c8a2b0f06f6ed1f2ea built and started; /up200, /login200, Alpha brand and all ten mirrored Awove package copies verified. Invocation-owned smoke container removed. No push or remote deployment.


## Checkpoint 6 working changes

Optional Nonprofit has independently selected Programs, Fundraising and Projects capabilities. Programs owns restricted private participants, locations/groups, sessions, unique recorded attendance, scoped period summaries and feedback/actions. Minimal participant-specific channels and optional live shared Contact details remain separate; private roster data never enters general search.

Fundraising reuses shared donor/partner/prospect IDs, with contextual ownership/actions, exact pledges/restrictions and optional existing Finance Payment links. New owners need current identity scope. Actuals use payment dates and retain still-linked receipts under archived expectations; active expected pledges remain separate. No Finance records are created or changed.

Projects adds manual annual plans, optional entered immutable budget revisions, parent-scoped Core tasks/milestones, meetings/decisions and explicit Core follow-ups. Optional Operations/Assets links use existing records; whole Finance Payments are unique per active project association, with receipts/outgoings separate from budget. Hidden/disabled source coverage is unavailable, never fabricated zero/complete partial totals. Nonfinancial planning creates no monetary snapshot.

Core work descriptors now accept optional declared capability keys. WorkAccess refreshes actors and rechecks cached descriptors; an optional WorkScopeContributor applies current parent subqueries to shared reads/edits/instructions/reminders. Closed history remains readable while active-parent scope suppresses edits/notices. Existing constructor signatures remain valid and Core imports no optional models.

Checkpoint 6 integrated verification:

- Full PostgreSQL regression passed462 tests /7,427 assertions (326.3s), before the final table-default/archive-list/cells corrections. The final connected copied profile then passed61/897 (44.6s): all55 current Nonprofit cases plus6 shared capability/parent-scope cases, with Finance/Operations/Assets kept and every unrelated optional package physically absent.
- Standalone copied Nonprofit discovery/production assets passed with all other optional packages physically absent:40 passed /674 assertions (26.3s),3 explicitly skipped counterpart-only integrations. This includes all18 Programs,15 standalone Projects, one persisted standalone Fundraising pledge case and6 shared-work cases. It does not claim all19 Fundraising cases ran without Finance; the full connected profile covers them.
- Desktop/mobile persisted workflows passed6/6: Fundraising2/2 (28.2s), final Programs2/2 (1.0m), Projects2/2 (1.3m). Strict synthetic before/after cleanup passed. Final screenshots visually checked on both sizes. Native select/Completion date test locators were corrected to actual accessible labels; business assertions remained intact.
- Review found and fixed useful first-use Program table defaults, archived-parent historical lists/CSV, and Project serializers losing trusted table cells; final copied tests and populated browser tables cover these fixes. A shared crm-back class fixes mobile backlink overlap; final production assets and Programs recapture include it. No page-local CSS was added.
- Full PHPStan0, fresh-cache Pint, final frontend types/format/production build, Node5 and Compose configuration passed.
- Exact additive Alpha migrations001300/1310/1320 passed with the entire existing Lead-row fingerprint unchanged. Alpha/Beta synthetic first-build additive-update and database/private-file restore rehearsal passed; this is not an upgrade from a historical release.
- Core-only copied discovery/assets passed with all ten optional packages physically absent:50/523 including Foundation, Currency and shared capability/parent/reference/routine coverage. Final container and full cross-module verification subsequently passed in Checkpoint7 below. No push/deployment, real messages, live integrations or customer imports.


## Checkpoint 7 complete — final integrated verification

The final full PostgreSQL regression passed **464/464 tests, 7,539 assertions, 311.033 seconds**, after all final Programs/Projects fixes and the compatible Mime patch. The complete Playwright run passed **64/64 cases in 10.7 minutes**: all 32 desktop and all 32 mobile cases, with no skips or filtered reruns. Persisted forms, advanced tables/layouts, documents, manual reminders, email-only sign-in, scoped module workflows, neutral Material controls/motion and Alpha/Beta isolation are included. Strict synthetic before/after cleanup passed. Current screenshots were reviewed and retained privately; generated historical screenshot changes were restored.

| Final command/check | Outcome |
|---|---|
| `php artisan test --compact` | Passed: 464 tests / 7,539 assertions |
| Full `npm run test:e2e` (workers: 1, both projects) | Passed: 64 cases / 10.7 minutes |
| Full PHPStan, 512MB | Passed: 0 errors |
| Fresh-cache Pint | Passed |
| `npm run typecheck`, `npm run check`, `npm run build` | Passed |
| Node clipboard/exact-decimal checks | Passed: 5 tests, 0 skips |
| `composer audit --locked --format=json` | Passed: 0 advisories, 0 abandoned packages |
| Alpha/Beta additive first-build update and database/private-file restore | Passed: retained fingerprints match |
| Compose full configuration, image build and Alpha/Beta startup | Passed: /up 200, /login 200, correct independent brands |

The final four copied profiles passed serially. Each physically removed all nine other optional packages (including the isolated example extension), passed PHP package discovery, built fresh frontend assets, verified absent routes/bundles and ran its PostgreSQL acceptance suite:

| Kept package / backend filter | Tests | Assertions | Backend duration | Skips |
|---|---:|---:|---:|---:|
| Customer Service / `CustomerServiceTest` | 17 | 368 | 5.588s | 0 |
| Finance / `PayrollPreparationTest` | 10 | 127 | 3.815s | 0 |
| Marketing / `MarketingTest` | 16 | 335 | 6.316s | 0 |
| Assets / `AssetsTest` | 20 | 353 | 10.161s | 0 |

Final copied-profile JSONs are retained in this directory. Together with the Checkpoint 6 Core-only 50/523, standalone Nonprofit 40/674 (three explicit counterpart-only skips), connected Nonprofit 61/897 and earlier HR/Knowledge absence builds, these verify the required independent combinations. Counts from overlapping suites are not added to the full 464-case regression.

The locked Symfony Mime 8.1.7 component had a medium dependency advisory. Compatible 8.1.10 is the only changed external package version; frameworks and other external versions remain unchanged. The [Symfony advisory](https://symfony.com/blog/cve-2026-45070-email-header-injection-via-non-token-characters-in-mime-parameter-names) identifies the affected/fixed releases. The final audit, backend, email-sign-in browser flows and container checks passed with the patch. Shared history supplies friendly Owner/Due date/Completion date labels and the existing Project field-label contract; no new page styling or domain redesign was introduced.

The first full 64-case browser attempt stopped after discovering an unregistered local `vendor/awove/assets 2` symlink duplicating the canonical Assets pages. Composer registered only one Assets package. The exact duplicate link was verified and removed, assets rebuilt, and the duplicate resolver guard retained. That partial run had 6 passed, 1 failed, 1 interrupted and 56 not run, with strict cleanup plus exact interrupted Knowledge cleanup. The subsequent unfiltered run started from the beginning and passed all 64 cases.

The first Service source-copy attempt was interrupted before database access because the old basename ignore pattern did not exclude generated `public/build`. The verifier now excludes that exact root directory, preserves legitimate source `build` directories and asserts generated assets are absent before fresh builds. Python syntax and focused ignore checks passed; all four profiles then completed from clean copies. This interruption was not a failing backend test. Assets test setup omits Finance capability keys when that package is absent; no business assertion was removed.

Container image `sha256:afa23edb2bdd1657dc7b0f5b5446beb37bfe7d05fe7fb632376c8eb58facc93f` (`awove-base-crm-cp7-alpha:20261031`) passed separate Alpha/Beta smoke checks. Both use Core 1.1.0 / contract 1 and all 11 mirrored Awove package directories; package Composer hashes match source, no private environment, fixture-credential or installation data files are included, and Symfony Mime 8.1.10 was verified in the image. Invocation-owned smoke containers were removed and port 18091 released. Later edits were verification tooling and documentation, not runtime package behavior.

Detailed logs, screenshots/traces and exact interrupted-run evidence remain ignored under `storage/app/private/acceptance-evidence/checkpoint7-{final,browser,profiles,container}-20261031` with directories 0700 / files 0600. No production deployment, real message, live integration, customer import or push was run.

## Handoff boundaries

All ten source modules use the retained shared host/Core and independent installation boundary. Sales remains the default; optional business packages are selected per installation and sensitive grants are explicit. Marketing planning, reporting and attribution belong to the **Marketing module**. No real external account, message, import or remotely deployed installation was changed by this request.

- The original `CRM_MVP_Module_Requirements.docx` was unavailable. Section 5 of the supplied implementation prompt is the functional source; original-document cross-check was not run.
- Spreadsheet support is bounded UTF-8 CSV, not XLS/XLSX. Supported protected HTTPS document links supplement the existing private Sales proposal storage.
- Work entry, next occurrences, campaign performance, attendance, payment records and payroll inputs are manual. Source-deferred integrations, AI, automatic publishing/recurrence, donation receipting, accounting/tax/payroll execution and specialist property workflows remain out of scope.
- One configured currency is enforced across retained installed monetary snapshots. Refunds, credits, overpayments and cancellation after payment remain unsupported source decisions; they are rejected rather than invented. Nonprofit actuals require current Finance access, with incomplete/hidden coverage unavailable.
- Update/restore evidence uses a synthetic first-build pre-update fixture, not an actual historical release. Retained Core/customization/legacy-extension fingerprints and module retention tests cover their respective boundaries.
- Containers were built and smoke-tested locally. The existing built-in PHP HTTP server is a development runtime; production web runtime, real email delivery and deployment acceptance remain separate authorized operational work.

For another client, configure its independent environment/private manifest, branding/terminology and selected modules, then run controlled installation/default initialization and explicit permission provisioning as described in [module operations](module-operations.md#installation-and-enablement). Shared Core source stays unchanged. The [requirements matrix](requirements-matrix.md) maps every source section to its implementation/tests, and [modular architecture](modular-architecture.md) documents the public extension boundaries.
