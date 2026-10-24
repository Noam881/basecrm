# Customer Service (M06 · שירות לקוחות)

Optional `customer-service` module, version `1.0.0`, core contract `1`. It has no Operations dependency. Install its Composer package and enable `customer-service` in the deployment-controlled installation manifest. Installed code and enabled module availability are separate from employee permissions. Enabling does not grant staff permissions.

## Working records

`/service` provides request and feedback intake, the shared advanced request table (all request fields, permitted customer details, search, typed filters, multi-sort, selection, reorder, pin/resize, saved layouts/views), request status/owner totals, overdue actions, waiting-customer work, unassigned requests (all-read permission only), resolution-period filters, feedback requiring action, and separate request/feedback CSV exports. Request CSV exports use the same validated table search/filter/sort context; feedback exports use overview filters. All queries are scoped to the current user's module and record access. Contacts and companies come from the shared directory; module permissions do not grant access to otherwise hidden identities.

A request has a shared contact or company, subject, description, received date, manual channel, owner (optional), priority, next action, and due date. Stable statuses are `new` (New), `in-progress` (In progress), `waiting-customer` (Waiting for customer), `resolved` (Resolved), and `closed` (Closed). Optional `customer_service_statuses` manifest labels may rename these without changing keys. `module_labels.customer-service` changes the English navigation label; the Hebrew name is a documentation/localization alias.

Changes use optimistic record versions and row locks. Interactions are manually entered note/call/meeting/email/message records with an explicit occurrence instant and server-derived staff attribution. Resolution requires text and a date. Reopening through any open status retains previous resolutions, documents and append-only history, including resolutions supplied during initial creation. Archiving retains a read-only request and stops pending reminders.

Supporting documents use a permission-checked HTTPS link endpoint. Embedded URL credentials and non-HTTPS schemes are rejected. The CRM does not fetch external documents; sensitive documents must remain protected by their document provider. This module does not duplicate the existing lead-specific private-upload service or claim to upload service documents.

Feedback may link to a request for the same customer. The reversible implementation default is the documented scale **1 Very dissatisfied, 2 Dissatisfied, 3 Neutral, 4 Satisfied, 5 Very satisfied**. Written comments and date are required. Action-needed feedback requires an owner and follow-up date. Marking follow-up complete stops its reminder; reading a notification does not complete the work. Feedback changes and archives retain staff/date and before/after details in the same scoped append-only history facility. Hidden historical request/identity links are preserved during unrelated edits and never exposed through selectors or JSON IDs.

## Dates and reminders

Received, due, resolution, and feedback follow-up dates are date-only values. Work reminders become due on that calendar date in the installation timezone; there is no implied SLA or recurring task generation. Interaction instants preserve the explicitly entered UTC offset and display in the user's installation timezone.

Permission-scoped open/overdue dashboard widgets use the shared `DashboardContributor` contract; disabled modules contribute no widgets.

Service contributes `requests` and `feedback` reminder sources through core `ReminderContributor` / `ReminderSource`. Core stores only module/source/record/version/recipient keys and read state in `crm_work_reminders`, never sensitive titles or previews. `crm:remind` enqueues generic work alongside existing lead reminders. Delivery, listing, and mark-read recheck module availability, current version, owner, active account, view permission, archive/completion state and due date. Rescheduling, reassigning, completion, access loss or disablement suppress obsolete work. Unique delivery keys make retries idempotent. The shared Reminders page renders these notifications.

Disabling Service denies every module route, direct mutation, supporting-document redirect, search, export and notification preview/delivery. It preserves requests, feedback and history for re-enablement. An ordinary user cannot change deployment availability.

## Permissions

- `customer-service.view.own`: owned requests and feedback.
- `customer-service.view.all`: all module records, including unassigned queues; this does not grant private shared-identity access.
- `customer-service.create`: intake, with an accompanying view permission.
- `customer-service.edit.own` / `.edit.all`: independent mutation permissions; archived records remain read only.
- `customer-service.assign`: ownership changes and creation on behalf of another user. Active owners must have a Service view permission.
- `customer-service.export`: permission-scoped UTF-8 CSV request and feedback exports. Formula-leading values use core spreadsheet protection.

CSV imports are supported by the shared contact/company/lead workflow, not by this module's requests or feedback. No XLSX or Service import capability is implied.

## Local verification

Backend behavior is covered by `php artisan test tests/Feature/CustomerServiceTest.php` using PostgreSQL `base_test`, plus `ModuleLifecycleTest.php`. Do not run two suites against the same test database concurrently.

`tests/e2e/customer-service.spec.ts` exercises persisted request creation, manual interaction, close/reopen with retained resolution, feedback, due reminders, search, reload and mobile overflow. Run only against the local Service server on `8093` with `APP_URL=http://127.0.0.1:8093`, `--env=alpha`, and `CRM_MANIFEST=storage/app/private/service-browser-manifest.json`. `php scripts/service-browser-fixtures.php setup desktop` creates this private manifest and reserved fictional account; setup/cleanup also support `mobile`. The guarded script requires localhost PostgreSQL port `55438`, database/role `base_alpha`, exact fixture identities/ownership, and refuses protected related data. Cleanup saves a private ignored recovery snapshot before deleting only the selected synthetic rows. It does not touch review customers or real accounts.

No portal, live message capture, surveys, SLA automation, chatbot, escalation or external connection is implemented; these remain deferred by the source.
