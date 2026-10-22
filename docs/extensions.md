# Extension contract 1

Trusted deployment-installed PHP packages register through standard Laravel providers. They are not sandboxed third-party plugins and cannot be uploaded through the CRM.

## Public surface

- `Awove\Core\Contracts\Module`: identifier, package version, core contract version, dependencies, permissions, navigation and profile panels.
- `Modules::register`, `enabled`, `validate`, `navigation`, `panels`: installed code and enabled functionality are different. Enabled modules must be present, have contract version 1 and have enabled dependencies. Invalid required modules fail application boot before activation.
- Typed `NavigationItem(label, href, permission)` and `LeadPanel(id, label, component, endpoint, permission)` contributions. Parent policy and module permissions are independent.
- `LeadCreated(leadId, actorId)` and `LeadStatusChanged(leadId, from, to, actorId)` dispatch after successful commits. Each subscriber/queued handler must check module availability at execution time.
- `Access::leads` and the `Lead` Laravel policy are the parent read/write authority. `Services\Leads` performs core lead transactions; `Services\Tasks` manages task mutations. Consumers still provide validated, documented core fields; these trusted PHP APIs are not a malicious-code sandbox.

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

`python3 scripts/core-only-build.py` demonstrates a separate successful build with the optional package and its source directory removed entirely. `packages/awove/example-service-details/tests` proves enabled/disabled/re-enabled, parent-policy denial, module-permission denial, event gating and incompatible/missing metadata behavior.
