# Nonprofit Projects

The `nonprofit:projects` capability provides project and annual planning without enabling Programs, Fundraising, Finance, Operations, Assets, Sales or HR. It uses existing CRM user accounts for responsible staff and Core WorkItems for project tasks, milestones and meeting follow-ups. It creates no replacement purchasing, equipment or financial registers.

## Records and shared work

Projects contain a name, objective, responsible owner, start/end dates and a stable status. Defaults are Planned, In progress, On hold, Completed and Cancelled. Installation `nonprofit_project_statuses` overrides display labels without changing keys or existing records. Work kinds are `project-tasks` and `project-milestones`; default statuses are Not started, In progress, Blocked, Completed and Cancelled. Only `done` claims a completion date. `nonprofit_project_work_statuses` customizes labels.

Related work requires both the current Project scope and Core work ownership/edit permissions. A work owner must already be able to view that specific Project; assignment grants no access. The module contributes its parent scope to Core, so generic work queries, Knowledge instruction links and reminder delivery cannot bypass the parent. Generic unlinked Nonprofit work is unqueryable. Closed/archived projects retain readable work and history, while active-only scope blocks child edits and reminders. Completing a Project never silently completes a task or milestone. An unarchived Project can be explicitly reopened. On-hold work remains manually managed.

Meeting summaries have a date, decisions and an optional explicitly created Core follow-up task. The follow-up stores its owner/due date/status in Core rather than duplicating them in the meeting. Meeting corrections and work changes require both parent and child versions. Hidden follow-ups remain associated but expose no work ID, title or owner. Document links use Core HTTPS validation and scoped local redirects; archive retains the link and audit history.

## Planned budget and actuals

Projects without an entered budget remain nonmonetary. Entered amounts create append-only `nonprofit_project_budgets` snapshots with non-null currency and actor. A revised amount, including zero, retains earlier snapshots. The latest authorized snapshot supplies `planned_budget` and currency; restricted users receive neither amount nor budget history. Snapshot/history update and delete are rejected. Core Currency registers this snapshot table, including retained rows after capability disablement, and prevents incompatible new monetary changes. Ordinary nonfinancial planning can continue during a currency conflict.

Actual incoming receipts and outgoing supplier payments are independent, exact fixed-precision totals. A Project may explicitly associate a whole existing Finance Payment. No charge, expense, receipt, payment, asset assignment, purchase or posting is created by a link. There are no partial allocations: one Payment has at most one active Project association. A separate pledge association serves a different purpose and may coexist. Removing a Project association retains its history and leaves the Finance Payment unchanged.

Incoming actuals use `finance:collections`; outgoing actuals use `finance:expenses`. Every projection and selector rechecks the corresponding Finance record scope using a fresh active actor. Missing/disabled capability, lost access to any linked source, or incompatible retained currency makes that stream unavailable with a null total. It never substitutes an unpaid ledger total, fabricated zero or misleading partial sum. Available streams with no linked payments correctly show zero. Date filters apply to Payment `paid_on` dates. Budget, incoming and outgoing remain separate; no net, allocation, accounting variance, tax or receipting policy is inferred.

## Optional resources and access

Equipment links select existing authorized Assets. Purchasing links select existing authorized Operations supplier orders. Options and follow links require both Projects access and fresh source-module access; new links lock and revalidate their source. Source disablement or access loss redacts target IDs/labels/URLs, while retaining the association. There are no required Composer dependencies on these optional business packages, and no optional-table foreign keys that would break an installation without them.

Permissions use `nonprofit.projects`: `view.own`, `view.all`, `create`, `edit.own`, `edit.all`, `assign`, `archive`, `export`, `budgets.view`, `budgets.manage`, `documents.view` and `documents.manage`. All-only roles work independently of own roles. Monetary association writes require budget view/manage as well as current Finance visibility; they confer no Finance mutation permission. The installer declares permissions without automatically granting them to existing optional-module roles.

## Interface, tables and exports

`/nonprofit/projects` presents Projects, Tasks and Milestones through the shared advanced table/query/preferences facilities. Search, configurable columns, sorting, filters, dates, layouts and CSV export use the same authorized context. Layouts remain dormant while the capability is disabled. Project and work profiles reuse the global neutral/purple Material fields, buttons, cards, history and instruction links. No page CSS or alternate grid is introduced.

Exports are UTF-8 CSV through Core `Csv`, with formula protection and a 5,000-row limit. They retain the current table filters and include only authorized budget columns. No Excel workbook support is implied.

Migration `2026_10_31_001320_create_nonprofit_projects.php` is additive. Destructive rollback is unsupported; restore a tested backup under the shared runbook.

## Verification and local proof

The main `NonprofitProjectsTest` suite exercises standalone plans, exact budget history, scopes, versions, Core work and meetings, generic reminders, closed history, safe document redirects, advanced exports, stale actors and real Finance/Operations/Assets connections. The three optional-source cases are explicitly grouped `projects-integrations`; a physically copied standalone profile can run `--exclude-group=projects-integrations`, truthfully covering 15 standalone cases rather than claiming integration coverage without those packages.

The browser workflow uses only the dedicated `nonprofit-projects-{desktop,mobile}@alpha.example.test` accounts, the exact guarded local Alpha database on port 55438 and local Mailpit. `scripts/nonprofit-projects-browser-fixtures.php` verifies the environment, serializes its reserved graph with an advisory lock, refuses unrelated ownership/foreign references and snapshots recovery data privately before cleanup. It first disables optional sources to prove independent planning, then creates fictional source records through the existing domain services to prove explicit associations. `connect`/`disconnect` affect only its private preview manifest. This fixture requires the full synthetic local source schema; it is never a migration/import tool for live data.

`tests/e2e/nonprofit-projects.spec.ts` checks real create/save/reload, budget revision, typed work and explicit meeting follow-up, completion/reminders, resource/payment links, disabled-source retention/reactivation, private document redirect, filtered CSV and read-only closed work on desktop/mobile. Run it serially under command-scoped `caffeinate` after the coordinated production build. Evidence uses current-date `/private/tmp` paths and private file permissions. No browser test sends external messages or follows the mock document host.

The final desktop/mobile workflow passed both cases on 2026-10-31. Four profile screenshots and the verification logs are retained in the ignored private directory `storage/app/private/acceptance-evidence/nonprofit-projects-20261031` (directory 0700, files 0600). The first browser attempt found a test locator that did not account for the shared completion-date helper text; the corrected locator passed the full unchanged workflow.
