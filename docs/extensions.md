# Extension contract 1

Trusted deployment-installed PHP packages register through standard Laravel providers. They are not sandboxed third-party plugins and cannot be uploaded through the CRM.

## Public surface

- `Awove\Core\Contracts\Module`: identifier, package version, core contract version, dependencies, permissions, navigation and profile panels.
- `Modules::register`, `enabled`, `validate`, `navigation`, `panels`: installed code and enabled functionality are different. Enabled modules must be present, have contract version 1 and have enabled dependencies. Invalid required modules fail application boot before activation.
- Typed `NavigationItem(label, href, permission, anyPermissions = [])` and `LeadPanel(id, label, component, endpoint, permission, anyPermissions = [])` contributions. Parent policy and module permissions are independent.
- `LeadCreated(leadId, actorId)` and `LeadStatusChanged(leadId, from, to, actorId)` dispatch after successful commits. Each subscriber/queued handler must check module availability at execution time.
- `Access::leads` and the `Lead` Laravel policy are the parent read/write authority. `Services\Leads` performs core lead transactions; `Services\Tasks` manages task mutations. Consumers still provide validated, documented core fields; these trusted PHP APIs are not a malicious-code sandbox.

- `CapabilityProvider::capabilities()` declares independently selected `module:capability` IDs. Explicit selection and individual grants remain separate.
- `LeadTableContributor` and `RecordTableContributor` declare authorized trusted columns; record tables additionally declare stable module-prefixed table IDs and defaults. The owning service/controller supplies its independently scoped builder. Shared Catalog/Query/Preferences/DataTable handle typed queries and safe current preference projections.
- `DashboardContributor::widgets($user)` contributes freshly scoped namespaced widgets with local links and declared permissions. Never return unavailable financial/participant data as a fabricated zero.
- `ReminderContributor::reminderSources()` supplies typed `ReminderSource` instances: `module()`, `key()`, `due()` ID/version/recipient descriptors and `current(recordId, recipientId)` freshly authorized preview or null. WorkReminders revalidates on delivery/list/read and stores no sensitive preview.
- `WorkContributor` declares typed `WorkType`/`RoutineType` descriptors; shared WorkItems/Routines own locking, versions, current actor scope and retained manual completion mechanics. A module owns additional business relations and validates them in the same transaction.
- Work descriptors accept an optional trailing `capability` key declared by their module. Existing constructors remain valid. WorkTypes validates declarations and filters disabled capabilities; WorkAccess also rechecks cached descriptors and fresh actors at use.
- Optional `WorkScopeContributor::scopeWork($user, Definition $type, Builder $query, bool $activeOnly = false): void` adds module-owned parent constraints to generic work queries. Use scoped subqueries and preserve existing predicates. WorkItems/Routines accept an optional fourth `activeOnly` query flag. Shared edits and reminder delivery use active scopes; default reads retain authorized closed-parent history. No Core dependency on optional parent models is introduced.
- `WorkReferenceContributor::workReferences($user, WorkItem|Routine $record)` supplies namespaced instruction IDs, labels and safe local links. Shared WorkReferences first freshly authorizes the parent; the provider independently authorizes each instruction. It creates no completion register.
- Shared EmployeeReferences exposes only stable ID/name/active summaries; private employment/payroll/participant authorization remains independent. Core Currency accepts trusted module-owned snapshot table registrations, including retained disabled-module data; no conversion is performed. Core Money, Csv and DocumentLinks provide exact values, format-only transfer and validated scoped redirects respectively. Domain authorization remains the caller's responsibility.

Other classes, HTTP response shapes and database details are internal. Contract 1 supports additive related tables and panels; it does not promise arbitrary controller/auth/page replacement. A compatible package declares Composer dependencies and core contract 1. Review dependency and capability metadata before upgrades.

## Demonstration package

`example-service-details` declares module `service-details`, version 1.0.0 and contract 1. Its provider registers:

- Namespaced `service_details_leads`, unique foreign key to a lead; separate migration history entry.
- `service-details.view` and `service-details.edit` permissions.
- Namespaced `/modules/service-details/leads/{lead}` routes and `service-details.*` names.
- `LeadPanel` component `ServiceDetails/Panel`, resolved from installed package `resources/js/panels` at Vite build time.
- A `LeadCreated` listener that adds an empty service record only while enabled.

Endpoints return 404 while disabled, even if a user has its permissions. Enabled endpoints additionally authorize the parent lead view/update policy and the relevant module permission. The Vue form independently hides edits without the edit permission. Disabling removes panel/navigation contributions and guards events; rows remain. Re-enable exposes the retained data. Migrations run only during deployment, never a request.

## Add a third fictional installation

1. Place a new non-secret manifest in `installations/examples/gamma/manifest.json` (real clients use private deployment-controlled paths). Choose name, local logo/favicon paths, color, terminology, IANA timezone, field definitions and enabled module IDs. Choose labels for stable status IDs through `statuses`.
2. Create a completely separate database/runtime role, app key, session cookie, cache prefix, queue database and private storage root. Point `CRM_MANIFEST` to its manifest. Do not reuse Alpha/Beta credentials.
3. For different functionality, add `packages/awove/gamma-service` with its own Composer metadata/provider and contract-1 `Module` implementation. Own the module's tables, migration prefix, namespaced permissions/routes, and `resources/js/panels/GammaService/Panel.vue`.
4. Add the approved package to a release's Composer requirements and lockfile, register typed contributions, guard every route/event/job, and write tests for disabled/unauthorized/parent-policy states. Build the image with mirrored path repositories and compile assets. The resolver discovers installed package pages/panels without editing core Vue files.
5. Run compatibility validation, migrations and missing-default initialization in staging; promote the pinned image after verification. Installation secrets/settings/storage remain outside the release. No core or host file needs a Gamma conditional.

`python3 scripts/core-only-build.py` demonstrates a separate successful build with every unkept optional package and its source directory removed entirely; `--keep` and `--filter` also prove selected standalone profiles. `packages/awove/example-service-details/tests` proves enabled/disabled/re-enabled, parent-policy denial, module-permission denial, event gating and incompatible/missing metadata behavior.

## Additive lead-table fields

Modules may additionally implement `Awove\Core\Contracts\LeadTableContributor` and return trusted `Tables\Column` definitions after checking module permissions. The core calls contributors only while enabled. See [table contracts](advanced-tables.md) for sorting/filtering behavior and permission handling.
