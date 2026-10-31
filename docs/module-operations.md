# Module operations

Run commands from the canonical Base CRM repository or its immutable release directory. Real environment/manifests/storage/credentials remain outside Git. Back up the database, private storage and manifest before controlled updates. This work is local only; do not run these examples against a customer installation without its own deployment authorization.

## Installation and enablement

Installed code is controlled by locked Composer dependencies and approved release metadata. Keep optional package versions compatible with the declared core contract; do not upload arbitrary PHP or activate unknown code through the UI. Composer/provider discovery and Vite discover only installed packages. The service-details example is a client extension, not the Customer Service business module.

A deployment manifest enables business functions independently:

```json
{
  "id": "example-client",
  "name": "Example CRM",
  "logo": "/brands/base-b.svg",
  "favicon": "/brands/base-b.svg",
  "locale": "en",
  "timezone": "Asia/Nicosia",
  "currency": "USD",
  "modules": ["customer-service"],
  "capabilities": {"sales": true},
  "module_labels": {"customer-service": "Customer Service"}
}
```

Use `CRM_MANIFEST` to point at a private deployment-controlled manifest. Do not edit shared core to customize another client's brand, labels or enabled modules. Hebrew source aliases belong in labels/localization metadata, not record keys or forced UI language. Existing Alpha/Beta remain different fictional brands and vocabularies; Service is off by default in both.

Enabling a sub-capability requires a registered CapabilityProvider declaration plus `"module:capability": true` under `capabilities`. Only select capabilities that actually ship. Setting Sales false hides and denies lead/pipeline endpoints and contributions while leaving shared identities and other authorized modules available. Removing a required dependency/capability fails validation; it is not silently disabled underneath a dependent module.

Apply controlled database changes and validate before traffic:

```sh
composer install --no-interaction
php artisan migrate --force
php artisan crm:install
php artisan crm:diagnose
npm ci
npm run build
```

Migrations run through these console/deployment commands, never web requests. `crm:install` inserts missing defaults and registered permission definitions; it never renames client stages or resets intentionally revoked grants. Optional-module permissions require explicit provisioning; enabling a module is not permission provisioning.

For an existing active synthetic account:

```sh
php artisan crm:permissions owner@alpha.example.test \
  --grant=customer-service.view.own \
  --grant=customer-service.edit.own \
  --grant=customer-service.create
```

Use exact installed permission names. Revoke a direct grant with `--revoke=...`. A permission inherited from a role must be changed on that role through controlled administration; the command refuses to pretend it has removed inherited access. Creating logins or external access is not implied by employee reference creation.

## Disablement and reminders

Remove an optional module from the manifest (and any enabled dependent capability selections). Validate the release/profile before activation. Data, history and documents remain retained. Direct requests, mutations, exports, document routes, relation selectors and generic reminder sources must deny access while unavailable. Current record policies are rechecked when queued jobs execute and when notifications are previewed/read. Obsolete versions/statuses/owners are suppressed. Read status does not finish work.

The existing scheduler invokes `crm:remind`, now also checking enabled module ReminderSource contributions. Run local queue workers for the correct installation environment. Frequencies are descriptive; no automatic routine/task generator is included.

## Recovery and verification

Prefer rollback to a compatible immutable code release plus the preserved manifest. Additive database updates retain records; do not drop tables on disablement or silently restore over newer writes. Follow the existing [backup/restore runbook](installations-and-upgrades.md). Rehearsals use disposable scratch databases and guarded fictional accounts only.

```sh
php artisan test --compact
vendor/bin/pint --test
vendor/bin/phpstan analyse --debug --no-progress --memory-limit=512M
npm run check
npm run typecheck
npm run build
npm run test:e2e
python3 scripts/core-only-build.py
python3 scripts/rehearse-upgrade.py
docker-compose --profile full config --quiet
```

Backend tests reject any database except isolated PostgreSQL `base_test`. Browser flows use dedicated fictional accounts and guarded cleanup. Never import real customer CSV in verification. Diagnostics omit credentials and sensitive previews. Current actual outcomes, limitations and next actions are in [module-status.md](module-status.md); functional coverage is in [requirements-matrix.md](requirements-matrix.md).


## Executive and Operations profiles (Checkpoint 2)

The optional packages `awove/executive` and `awove/operations` use core 1.1 and contract 1. Select `executive` or `operations` in a private manifest's `modules` list; neither requires Customer Service or Finance. Sales may be explicitly disabled in a pure administrative profile. All registered migrations still run through controlled console commands; disabled tables are retained.

Grant only the required `executive.*` or `operations.*` own/all/create/edit/assign/archive/export permissions to approved staff. A shared task/routine owner must already be active and have view access to its module; assigning ownership does not provision that access. An unchanged inactive owner remains a historical reference. Concurrent important edits reject a stale version and require reload.

Operations directory use also requires explicit shared `identities.classifications.view` or `identities.classifications.manage`, plus the independent underlying identity scope. Grant `identities.view.all` only where broad directory reading is intended; it does not grant editing. `identities.classifications.assign` controls assigning another responsible person. Classification responsibility never opens unrelated Sales, Service, HR or financial records. New contacts/companies continue through the shared Add new contact flow, with the existing duplicate warning and identity-create permission.

Routine completions are dated and retained; schedule the next date manually in the completion form or a later edit. Leaving it empty stops the next reminder until a new date is supplied. Reminder read status does not complete a task or occurrence. Cancelled requests stop reminders without a completion date. Supplier order receipts track deliveries only and never create expenses, payments or stock transactions.


## Employee references, HR and Finance (Checkpoint 3)

Employee references are a shared utility at `/people/employees`, not an eleventh business module. Provision `employees.references.view` and, only for directory managers, `employees.references.manage`. A name creates no login, contact or employee merge. Explicit contact/login links require independent access to that record. Inactive references remain available for final payroll preparation; new asset assignments must choose active references. Private contact details, recruitment and employment information belong to HR, not the reference projection.

Select `hr` and/or `finance` in `modules`. Finance additionally requires explicit `finance:collections`, `finance:expenses` and/or `finance:payroll` capability selections. Enabling one does not expose the others. Each has exact `finance.<capability>.*` grants; payroll view/export is restricted separately from collections and expenses. HR employment/recruitment/case/document/final-pay permissions are separate too. Enabling HR never implies payroll access.

Payroll agreed inputs are configured in the deployment manifest's `payroll_inputs` list with stable `key`, `label`, `type` (decimal, money, text or date), `unit`, `scale` (0–4) and `required` fields. At most 20 definitions are accepted. Each preparation snapshots its definitions, so changed deployment configuration applies to new preparations. Pending input may be incomplete; Ready for review and Sent to payroll enforce required inputs. Sent to payroll is a manual status with a manually recorded date; no transmission occurs. Exact employee/start/end period duplicates are rejected even after archive. Overlapping but different date ranges are not silently defined as duplicates; clients must agree their pay-period convention. Supporting documents use freshly scoped HTTPS redirects. Payroll CSV includes schema snapshots and exact manual values, omits document links, and requires its own export grant.

Choose the installation's one currency before financial entry. Currency mismatch with retained installed monetary tables blocks new monetary writes and marks combined overview amounts unavailable, including retained data in disabled capabilities. Do not rewrite old transactions or add different currencies to bypass this guard. Refunds, credits, overpayments and cancellation after payment remain unresolved source rules and are rejected; no accounting behavior is fabricated.


The copied-package absence verifier can also keep a selected locked package while removing all others, without editing the working app:

```sh
python3 scripts/core-only-build.py --keep finance --filter PayrollPreparationTest
python3 scripts/core-only-build.py --keep hr --filter HrTest
python3 scripts/core-only-build.py --keep knowledge --filter="persists procedures materials and training independently"
python3 scripts/core-only-build.py --keep marketing --filter MarketingTest
```

These commands use the exclusive `base_test` slot. Do not run backend suites simultaneously. Browser fixtures use separate synthetic Alpha/Beta accounts and guarded cleanup; local preview listeners must remain running for each profile. Never reuse an occupied port belonging to another service (Payroll proof uses8100;8097 was deliberately left untouched).


## Knowledge and Marketing profiles (Checkpoint 4)

Select `knowledge` and/or `marketing` in `modules` and provision exact namespaced grants explicitly. Neither requires HR, Operations, Sales or Finance. Knowledge has independent library, renewal and system scopes, separate document viewing/management and separate system-reference viewing/editing. A system reference is an opaque password-manager item name/ID, not a credential or vault; use the public HTTPS login page without tokens or query strings. Do not put passwords or API keys in notes or references. Missing-owner/document views use actual missing fields; there is no invented stale-document interval. Renewal recording retains its dated event and accepts a manually entered next expiry/reminder, without recurrence generation.

Knowledge instruction selectors expose only enabled and authorized existing work parents. The receiving work profile independently authorizes both its own record and the instruction. Disabling Knowledge removes these links without changing the work/completion history. Do not enable HR or Operations merely to use the Knowledge library.

The **Marketing module** is independently enabled and granted for campaign/reporting, content and attribution. Campaign/reporting access does not grant Sales access. Attribution requires Marketing and Sales, the appropriate Marketing attribution permission and fresh access to the specific Lead. Existing external campaign IDs remain unchanged; select a CRM campaign separately. Knowledge/Marketing migrations1000/1100/1110 are additive and must run through controlled console updates with a backup. The extraction retains the old Lead JSON column and immutable exact snapshots; do not drop these on disablement or use a destructive down migration to roll back canonical Marketing edits.

Manually enter each report's inclusive start/end dates, spend and reported leads. Periods for one campaign may not overlap. Overview/report filters include complete report periods; they do not prorate reports across a partially overlapping window. Cost per lead is computed from total spend / total reported leads and remains empty for zero leads. Budgets, actual spend, reported leads and accessible linked CRM opportunities remain separate. Publication is manual; no ad account or publishing integration is connected.

Choose one currency before monetary data entry. Installed providers register their retained currency snapshots even while disabled. A mismatch requires restoring the agreed deployment currency, not rewriting records or inventing conversions. Readable historical rows retain their own currency; nonmonetary receipt/archive history remains possible where its owning workflow allows it.


## Assets profile (Checkpoint 5)

Select `assets` in the private manifest's `modules` list and explicitly disable Sales if it is unnecessary. Assets requires Core 1.1/contract 1 and no other business package. Apply additive migration `2026_10_25_001200_create_assets.php` through the controlled backup/migrate/install process above; disablement retains every table and destructive down migration is unsupported. Do not use a normal web request to install schema. Domain/backend checks and frontend type/format/build have passed; final desktop/mobile acceptance is still pending and is recorded in [module-status.md](module-status.md).

Deployment terminology can use `module_labels.assets` (for example, Equipment), `assets_statuses` and `assets_maintenance_statuses`. Only known status keys receive display-label replacements; labels must be non-empty text of at most 100 characters. Stable asset keys are `available`, `in-use`, `under-maintenance`, `retired`; maintenance keys are `planned`, `in-progress`, `completed`. Configuration does not remap historical records. Keep the Hebrew source alias ניהול נכסים as localization metadata rather than forcing a UI-language change.

Provision exact `assets.view.own`/`assets.view.all`, `assets.edit.own`/`assets.edit.all`, `assets.create`, `assets.assign`, `assets.archive` and `assets.export` grants as appropriate. Every child inherits the Asset's current responsible-staff scope. Assignment, return and open deadline edits require parent edit plus assign. A maintenance assignee must already view that particular asset; assigning responsibility never grants it. Optional `assets.documents.view/manage` and `assets.costs.view/manage` are separate, and management also requires view and parent edit. Core employee-reference and identity-classification grants remain independent. Give broad shared identity reading only when intended; contextual Customer/Supplier classification still requires current access to its underlying Contact/Company.

Create a register record with unique Asset ID, name, type, location and responsible staff, plus the optional purchase date/serial and notes. Register location is explicitly maintained; an assignment location is a retained destination and does not automatically overwrite it. An assignment can start from Available or In use when none is current; select an active employee reference, authorized Customer classification or location. No HR or login creation is needed. Open expected return dates/notes can be changed; actual assignment targets/dates and completed return date/condition remain retained. A stale parent or child version requires reload rather than overwriting another edit.

Maintenance can be performed while equipment stays assigned. In progress sets Under maintenance. Return keeps Under maintenance while in-progress work remains, otherwise Available. Completion records an actual date and explicitly selects a current state consistent with assignments/other work. A manual In use register state without an assignment is allowed. Retiring or archiving an asset requires returning current assignments and completing or explicitly archiving unresolved maintenance. Archive abandoned maintenance instead of marking work completed when it was not done; its prior state/history remains and the asset state does not change automatically.

Enter an optional cost as an exact decimal string in the displayed currency. Completion without cost creates no cost row or currency anchor. A completed action may receive its first late cost or a corrected cost; each is an immutable snapshot, and the latest supplies current cost. Original completion cost/currency and every earlier snapshot remain retained. Clearing a recorded cost is unsupported; an explicit zero is a recorded zero, not a deletion. A retained-currency mismatch blocks monetary writes—restore the agreed deployment currency, never relabel old values. Costs create no Finance charges, expenses or payments.

The next due date is manual. Completion retains its initial next date; later edits can reschedule or clear the current reminder without creating another maintenance action. Return notices go to the Asset owner; maintenance notices go to the assigned staff member only while that person still has parent access. Shared preview/delivery checks suppress stale versions, returned assignments, archived/retired assets, archived maintenance, inaccessible owners and disabled-module work. Reading a notice does not complete or return anything. Upcoming means today through 30 days ahead; overdue means before today, using installation date-only timezone rules.

Use `/assets` for Register, Assignments and Maintenance. Tables support the shared column chooser/layouts/search/advanced filters/sorts. Date filters use purchase date for the register, assignment date for assignments, and the effective maintenance deadline (due date until completion, manual next date afterward). Matching scoped CSV is limited to 5,000 filtered records and formula-escapes free text. It includes register responsible staff/notes, assignment notes and maintenance description. Private document URLs are excluded; cost/currency appear only with cost-view permission. Historical inactive/hidden identities retain their links without appearing in unauthorized search or exports.

Source-deferred features remain unimplemented: depreciation, rental billing, leases, barcode scanning, stock control and automatic maintenance scheduling. No external provisioning, financial transaction or automatic recurrence is triggered by this module.
