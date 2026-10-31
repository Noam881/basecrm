# Asset Management

Optional module `assets` (`awove/assets` 1.0.0), compatible with Core `^1.1` and contract 1. Hebrew source alias: **ניהול נכסים**. English UI labels remain the default. Enable the installed package through the installation manifest; enablement does not grant permissions. It works with Core employee references and identities while Sales, HR, Operations and Finance are disabled.

## Register and terminology

The register persists a unique, case-insensitive Asset ID, name, type, location, responsible CRM staff member, notes, optional purchase date and serial number. IDs remain stable. Freeform type and location support generic equipment, property and other assets without specialist property workflows.

`module_labels.assets` controls the module page/navigation title (for example, `Equipment`). `assets_statuses` and `assets_maintenance_statuses` may replace display labels for known stable keys; values must be non-empty text of at most 100 characters. Unknown keys do not change the workflow. Saved records retain their internal keys:

- Assets: `available`, `in-use`, `under-maintenance`, `retired`.
- Maintenance: `planned`, `in-progress`, `completed`.

Configuration is deployment controlled. Defaults are applied at runtime and do not rewrite stored client labels or records.

## Assignments and state

An asset has one current assignment, enforced under its row lock and by a partial unique database index. A new assignment selects an active Core employee reference, an authorized current Customer classification, or a location. Employee references require no login, contact or HR profile. Contextual Customer/Supplier classification uses existing Core identities and explicit Core grants; it creates no duplicate directory.

An assignment may start from Available or In use when no current assignment exists. Manual In use without a recorded assignment is also supported. Assignment targets and actual assignment dates are retained. While an assignment is open, its expected return date and notes may be edited with both parent and assignment versions. Return records retain the actual date and condition; returned assignments are read-only. Historical inactive employee/classification references remain stored, and their summaries disappear when current identity permissions no longer allow access.

Maintenance can occur on an assigned asset. Starting In progress sets the asset to Under maintenance and leaves the assignment open. Returning an asset keeps Under maintenance while another action is in progress; otherwise it becomes Available. Completion records the actual completion date and explicitly chooses a state consistent with current facts: In use for a current assignment, Available when none is open, or Under maintenance for remaining attention. Other in-progress work requires Under maintenance. A manual correction back to Planned is journalled; it does not silently change the asset's current state.

Retirement and asset archive require no current assignment and no uncompleted, unarchived maintenance. Abandoned Planned/In progress work can be explicitly archived without recording a fictitious completion. That action retains the work's original state/history, suppresses its reminders and changes no asset state. Asset archive retains assignments, maintenance, documents and history read-only.

## Maintenance, costs and reminders

Maintenance records an action, optional notes/supplier, responsible CRM staff member and due date. The responsible staff member must already be able to view the parent asset; assigning maintenance never grants parent access. Existing historical responsibility can be retained when that user's access later changes, but inaccessible reminder previews/delivery are suppressed.

Completion and its initial manual next date are immutable snapshots. Completed work can later update its responsible staff, description and current manual next reminder date. The next date creates no recurrence or new maintenance row. Upcoming means today through the next 30 calendar days; overdue means before today. These are reversible overview defaults, not a specialist scheduling policy. Date-only deadlines use the installation timezone; timestamp display respects the user's permitted display preferences.

Costs are optional exact decimal strings, stored only when entered. Completing work without a cost creates no monetary row and does not anchor a currency. A cost can also be recorded later or corrected after completion. Each action appends an immutable cost snapshot, retaining its actor/time/currency. The current cost is the newest snapshot; the original completion cost stays unchanged. Clearing a recorded cost is unsupported; entering zero explicitly records a real zero snapshot. Core Currency consistency is checked before every monetary write, including costs retained while the module is disabled. Original currencies are never converted or relabelled. A cost creates no Finance transaction.

Owner reminders contribute `assets/returns` and `assets/maintenance` to the shared reminder mechanism. Notices store IDs/versions only. Every child mutation increments the parent version; stale notices are rejected. Listing, previewing and delivery freshly check module enablement, active user, parent scope, current owner, archive/retirement state and effective deadline. Completed next-date reminders explicitly describe a next maintenance date; they imply no generated work.

## Permissions and privacy

- `assets.view.own` / `assets.view.all` scope every record through the parent Asset owner. `assets.edit.own` / `assets.edit.all` require that same current view scope.
- `assets.create`, `assets.assign`, `assets.archive` and `assets.export` are independent. Physical assignment/return/deadline edits require parent edit plus assign. Maintenance creation requires parent edit plus create. Assigning CRM owners additionally requires assign and validates an active eligible user.
- `assets.documents.view` / `assets.documents.manage` protect document references. Management also requires view and parent edit. Immutable HTTPS references are opened through authorized internal redirects, never raw URLs in tables or CSV. Archive retains previous references.
- `assets.costs.view` / `assets.costs.manage` protect current costs, original completion costs and cost history. Writes also require view and parent edit. Hidden costs/currency/private completion links are absent from DTOs, selectors, filters and CSV.
- Core `employees.references.view/manage` and `identities.classifications.view/manage/assign` remain separate; Assets grants none of them implicitly. Related customer/supplier options require current underlying contact/company access. Hidden IDs are redacted and retained on unrelated edits.

All mutations refresh the actor, authorize the parent, lock the parent before child rows and reject stale versions with HTTP 409. Basic history retains actor/time/event/changed field names, without copying sensitive old/new payloads. Immutable snapshots reject model updates/deletion. Disabling the module denies direct routes, table fields, exports, documents, widgets and reminders while retaining stored data.

## Public entry points

`/assets` provides Register, Assignments and Maintenance sections with shared advanced tables, column preferences, search/sort/filter and matching scoped CSV exports (maximum 5,000 filtered rows, shared formula escaping). Overview filters cover type, location, parent responsible person and status. Register date filters use purchase date, assignment filters use assignment date, and maintenance filters use its effective deadline (original due date until completion, current manual next date afterward). CSV contains authorized business fields and no document URLs.

Profile routes are `/assets/register/{id}` and `/assets/maintenance/{id}`. Services `Records`, `Assignments` and `Maintenance` own mutations; `Access` supplies fresh parent-scoped queries; `Relations` returns minimal currently authorized identity summaries. Child write payloads include `asset_version`; child edits/return/completion/archive/cost actions also include their own `version`.

Migration `2026_10_25_001200_create_assets.php` adds module-owned tables only. Installation/update commands apply it; ordinary web requests never migrate. Destructive down migration is unsupported—use a tested backup/restore. Module source, synthetic backend acceptance tests and guarded desktop/mobile workflow fixtures are maintained in this repository.

Deferred by source: depreciation, rental billing, leases, barcode scanning, stock control and automatic maintenance scheduling. No external account provisioning, financial calculations, Finance writes or automated recurrence is introduced.
