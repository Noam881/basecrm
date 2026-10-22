# Architecture

## Independent modular monolith

This is a new standalone Laravel host. It shares no models, users, credentials, deployment, database, fixtures, or runtime configuration with the other CRM projects in the surrounding workspace. Existing unrelated working-tree changes were preserved.

`app/` and `bootstrap/` are shared host code: official Fortify authentication, account security, Inertia bridge and runtime integration. `packages/awove/crm-core` owns CRM models, policies, access scopes, domain services, events, migrations, Vue pages, shell and design tokens. `packages/awove/example-service-details` owns its optional table, routes, permissions, event subscriber and frontend panel. Installation manifests contain data, not PHP. This is not a shared-database tenant system; there is no tenant switcher, billing or seat system.

Core and host release 1.0.0 are tracked together. Composer path repositories provide live development package links. Image builds set `COMPOSER_MIRROR_PATH_REPOS=1`, so installed package sources are copied into the image. Frontend page and panel resolution reads `vendor/awove/*` and compiles only installed package assets. No production symlink points outside the build context. Core never imports the optional module.

Domains are represented by explicit models/services rather than separate packages: identity/access (Fortify, Spatie, policies, Users service), contacts/companies, leads/statuses (Leads service), notes/activity, follow-ups (Tasks service), persistent reminders (queue job), configuration and display preferences. HTTP handlers parse requests and compose scoped responses; consequential lead/task/user rules reside in services. There is no generic repository or plugin execution framework.

## Configuration precedence

1. Core defaults from core package config.
2. Deployment installation manifest (`CRM_MANIFEST`, or fictional Alpha/Beta examples).
3. Permitted persisted administrator settings: name, color, timezone, lead terminology.
4. User display preferences (currently columns/order and optional timezone).
5. Deployment locks always win: core locks plus additional manifest `locks`.

Modules, custom-field definitions and asset paths are locked by default. Secrets stay in private environment/deployment storage. Brand color must be a six-digit hex value; assets must be local public paths; locale ships as English; timezones must be IANA identifiers. Custom fields are limited to text, number, date, select and boolean and validated server-side; no secret fields or executable settings. Pipeline state, identity and ownership stay relational. Stable status IDs and active/won/lost semantics survive display changes. Status deletion is intentionally not offered.

## Authorization and historical integrity

Administrator and Employee are permission presets. Employees view/edit owned leads by default. `leads.view.all` does not grant `leads.edit.all`. Assignment, archival, identities, settings and users have separate capabilities. Every CRM route is authenticated; privileged users must confirm MFA enrollment. Deactivation is checked against the current stored user and invalidates sessions/remember tokens. Historical users cannot be deleted; the last active administrator is protected by a transaction and PostgreSQL advisory lock.

All lead lists, dashboards, direct URLs, linked record lookups, notes, tasks, reminders and extension requests check server scopes/policies. A contact/company becomes visible through an accessible lead, or through creator/admin access; its profile data never includes unrelated leads. Duplicate warnings are scoped to visible identities and never silently merge. Shared identity editing is not exposed in this checkpoint.

Task assignment never grants record access. Assignees must be active and already able to view the parent lead. Ownership changes cancel open tasks whose assignees lose parent access. Deactivation cancels that user's remaining tasks, preserving attribution. Permissions are rechecked at reminder delivery and listing. Archive excludes records from active dashboards and prevents further edits; authorized historical read remains available.

## Persistence and concurrency

Related writes use transactions. Lead ownership/status edits and task mutations carry version numbers and reject conflicting changes with 409. Task creation/mutation locks the parent lead before task writes. Activity is append-only through model/application interfaces and stores event names, IDs, authors and timestamps, not secrets. Lead events implement `ShouldDispatchAfterCommit`.

Scheduler `crm:remind` enqueues due tasks; `DeliverReminder` locks the task, rechecks version/status/access and inserts one persistent reminder per `(task_id, task_version)`. The database unique constraint and row lock tolerate repeated/overlapping workers. Completion, cancellation, rescheduling and lost access invalidate obsolete reminders. Reading only sets `read_at`. Failed attempts retry with backoff and remain diagnosable through Laravel `failed_jobs`; `crm:diagnose` reports failures. Due instants are stored in PostgreSQL timestamp-with-time-zone columns, with explicit input offsets and UTC conversion. Browser datetime inputs use the device timezone and say so; display uses the configured/user timezone.

A follow-up is not a calendar appointment. `next_action_at` is derived from open task minimum due time; no competing free-text field exists.

## Release boundaries

Installations use immutable host/core and approved extension versions, not client branches. Their environment, real manifests/brand assets, database contents and uploaded files live outside the release image. Fictional examples are the only client examples committed here. Promote a missing reusable behavior to a reviewed core contract, then implement the client variation above it. Do not patch vendor files or copy Vue profiles into client folders.
