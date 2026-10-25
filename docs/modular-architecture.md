# Modular CRM architecture

The application remains the existing Laravel/Vue/TypeScript/Inertia/PostgreSQL modular monolith. Each client has its own database, environment, persistent private storage and deployment manifest. There is no shared-database tenancy, module database, SaaS billing, marketplace or remote plugin uploader.

The shared host owns runtime/authentication and installed-package discovery. `awove/crm-core` owns shared identities, access primitives, settings, design system, activity, files and reminders, plus the retained historical Sales domain. `SalesModule` identifies that existing domain without moving IDs or creating another pipeline. `awove/customer-service` owns its records, policies, request/feedback forms, history, reports and reminder sources. The existing `example-service-details` remains isolated and disabled outside its explicit fictional Beta configuration. Legacy campaign attribution is now owned by the optional Marketing package, with the original Core column frozen and hidden for retention. No live integrations are added.

Installed Composer code, deployment-enabled modules/capabilities, and user permission grants are separate. `Modules::validate()` rejects missing/incompatible code, unknown sub-capabilities, missing required dependencies and circular dependencies. Optional packages register ordinary Laravel providers and implement the stable Module contract (contract 1); independently selectable sub-capabilities implement CapabilityProvider. Capabilities use stable `module:capability` identifiers. Optional counterpart links must check both availability and record authorization. No dependency on a module may be inferred from a hidden sidebar link.

Legacy profiles retain Sales by default. `capabilities.sales=false` explicitly disables its HTTP entry points, query contributions and reminder access, while the overview renders authorized workspace links instead of fabricated zero Sales metrics. Optional modules require their identifier in `modules`. A declared sub-capability additionally requires an explicit boolean selection in `capabilities`. Data and migrations remain installed when disabled; no ordinary web request runs migrations.

The HTTP `crm.module` guard precedes domain validation. Each domain must additionally scope record/relation queries and authorize mutations, exports and files. Module enablement never grants optional-module permissions to staff or existing administrator roles. Deployment operators provision exact permissions explicitly through `crm:permissions`. Existing grants are retained on repeated install. Shared identity access is not permission to read HR, payroll, candidate or participant records.

Contacts and companies share authored notes and the same identity profile composer; append-only identity activity stores authors/times/changed field names, not copied private values.

The existing lead reminder pipeline is retained. Generic WorkReminders accepts typed ReminderSource contributions from enabled modules. Queued delivery and each preview/read resolve the current source, recipient, version, due state and authorization again. The generic table retains IDs, recipient/version and read state, not a sensitive cached preview. Completion/rescheduling/archive/access loss/disablement make obsolete work invisible. Reading a notice does not complete a record. No frequency field generates future tasks or routine occurrences.

Vite resolves pages/panels from installed `vendor/awove/*/resources/js`; the Customer Service Composer package therefore participates in actual production discovery. Deployment builds mirror path packages instead of relying on development symlinks. Client extensions use existing LeadPanel/LeadTableContributor, events, navigation, domain services and new reminder contracts; do not copy core pages or patch vendor code. [Existing service-details example](../packages/awove/example-service-details/src/ServiceDetailsModule.php) remains the working extension example.

Configuration precedence remains defaults → deployment manifest → permitted persisted settings → permitted user display preferences, with deployment locks winning. Asset paths, enabled modules/capabilities, module labels and currency are deployment controlled. Generic fresh Sales stages are New, In discussion, Proposal sent, Won and Lost; stable IDs and existing renamed/history stages are retained. The fictional Alpha/Beta legacy-stage flag keeps their extra Contacted/Qualified fixtures. English display labels and Hebrew navigation aliases are distinct from internal keys. Existing neutral/purple design tokens and B branding are preserved; this implementation does not redesign the interface.

Optional packages now include Executive, Operations, HR, Finance, Knowledge and the **Marketing module**, alongside Customer Service. Their checkpoint evidence is tracked in the requirements matrix and module status. Employee references are shared independently of HR recruitment. Request/routine/task UI or completion mechanics may be shared, but each business module retains its own statuses, rules and scopes.

## Source navigation aliases

These aliases preserve the supplied Hebrew folder terminology without changing stable keys or forcing a language switch. An alias is metadata, not an enabled module or a placeholder screen.

| ID | Stable key | English label | Hebrew source alias |
|---|---|---|---|
| M01 | sales | Sales | מכירות |
| M02 | executive | Executive Management | מנכ״ל |
| M03 | hr | Human Resources | משאבי אנוש / ניהול כח אדם |
| M04 | finance | Finance and Collections | פיננסים וגבייה |
| M05 | operations | Administrative Operations | תפעול אדמיניסטרטיבי |
| M06 | customer-service | Customer Service | שירות לקוחות |
| M07 | knowledge | Knowledge Management | ניהול ידע |
| M08 | marketing | Marketing | שיווק |
| M09 | assets | Asset Management | ניהול נכסים |
| M10 | nonprofit | Nonprofit Extension | עמותה |

Registered module tables implement RecordTableContributor and reuse Catalog, Query, Preferences and DataTable; core has no imports from the business package. Each contributor supplies trusted SQL columns and defaults; the owning controller/service supplies the independently record-scoped builder. The controller must still redact model attributes and related identities before serialization: a column whitelist alone is not a universal field-redaction layer. Disabled/unauthorized table definitions, preferences and search/filter expressions are rejected. Saved personal layouts survive disablement and are filtered against currently authorized fields on return. DashboardContributor and ReminderContributor follow the same enabled-code/permission separation.


## Shared work and contextual identities

`WorkContributor` declares typed `WorkType` and `RoutineType` descriptors. The shared WorkItems/Routines services enforce the owning module, kind, own/all/create/edit/assign/archive permissions, current active-user state and optimistic versions. Each module owns business-specific relational tables and wraps shared changes in one transaction. Historical lead-bound `crm_tasks` retain their IDs and reminder pipeline; a management task or operational request never creates a dummy lead.

A terminal status suppresses reminders; a completed status also receives a completion date. These sets differ: a cancelled operational request is terminal without claiming completion. Routines store descriptive frequency and a manually chosen next due date. Completing an occurrence locks the record, retains the occurrence date, actor and checklist snapshot, and clears the next due date unless a person supplies another. Completion history survives later checklist or schedule edits; frequency text is never interpreted as an instruction to generate records.

`IdentityRelationships` adds Customer, Supplier, Donor, Partner and Prospect classifications to the existing contact/company IDs. PostgreSQL enforces exactly one contact or company, one classification per identity/role, and retained history. Name/phone/email come from the existing directory, not a copied address book. A company main contact is independently scoped and redacted if no longer visible. Assigning responsibility does not grant identity or related-module access. Classifications have explicit shared view/manage/assign permissions; `identities.view.all` permits directory reading without identity editing. These new grants are never automatically assigned to existing roles.

`Csv` is a format-only shared parser/download utility, including UTF-8/quote/size/header checks and formula-safe headers/cells. Modules still own record scope, export bounds, field redaction and any preview/confirmation writes. `DocumentLinks` validates HTTPS links and emits private/no-referrer redirects after the owning module has freshly authorized the parent; it does not fetch files or turn a link into a public download permission.


## Employee references and restricted domain data

`EmployeeReferences` supplies only stable ID, name and active state to domain selectors. It is independent of HR recruitment, authentication and Contacts. Optional explicit links to existing contacts/accounts are unique and separately scoped. Hidden links survive ordinary redacted-null edits; creation never merges names. Domains independently authorize their private parent records and redact employee summaries when reference access is lost.

HR owns employment, recruitment, CV/document access, cases and final-pay information. Finance owns collections, expenses and payroll as independent declared capabilities. Payroll snapshots deployment-agreed input definitions per employee/period and retains exact strings, supporting-link history and versioned manual status history. No shared reference serializes HR or payroll information. `Money` reuses locked Brick Math for exact two-decimal values and aggregates; browser decimal fields use text/decimal input mode rather than number-coercing v-model bindings.


Saved table preferences remain server-owned data. Disabling a contributor or losing a field permission projects out its columns, widths, pins and view filters/sorts, while ordinary visible edits preserve dormant fragments from locked server storage only. Incoming hidden IDs/values are rejected. Shared authentication serialization omits raw display preferences, and the legacy Leads alias exposes only the current safe table projection. Explicit view deletion remains explicit. User display timezones are resolved through `Installation::display`, retaining deployment locks; operational date-only decisions remain installation-scoped.


## Knowledge instructions and optional Marketing attribution

Knowledge owns procedures, reusable materials, training, renewals, system references and typed instruction relations. `WorkReferenceContributor` provides only namespaced IDs, labels and local links. `WorkReferences` freshly reauthorizes the enabled Core work parent before asking enabled installed contributors; Knowledge separately authorizes each instruction. Five existing work profiles render the same small list. Completion stays with the owning HR, Operations or Executive workflow; Knowledge creates no second completion register.

Lead panels retain their existing constructor contract and can supply alternative namespaced permissions as an optional sixth argument. A primary permission or one declared alternative allows a panel after fresh actor/module checks. Core projects only panel ID, label, component and endpoint; the module endpoint must still freshly authorize the Lead and domain permissions. This supports separate Marketing attribution viewing/editing without copying the Lead page.

The **Marketing module** owns campaign plans, manual period reports, content planning and lead acquisition attribution. Marketing has no dependency on Finance or Sales. Only the optional Lead panel/table relation requires both Marketing and Sales plus current Lead access. Core generic lead mutations reject attribution input; the frozen `crm_leads.attribution` column is hidden from Core serialization and no longer used as the live source. The additive extraction copies exact JSONB and original Lead version/timestamps into immutable Marketing snapshots and its new canonical store. Legacy external campaign IDs remain external strings; the optional CRM campaign foreign key is separate. Existing `attribution:*` table IDs are retained for saved layouts, and dormant layout fragments survive module disablement.

Campaign budgets and manually reported actual spend remain separate. Reporting periods may not overlap for the same campaign; campaign locking and optimistic versions prevent conflicting entries. Summaries include whole reporting periods, never invented daily allocations. Aggregate cost per lead uses total spend divided by total reported leads, including spend from zero-lead reports; a zero denominator has no amount. Linked accessible CRM opportunities are a distinct measure and never added to reported leads. Content status changes and publication dates are manual.

`Currency` is a small Core registry of trusted installed module-owned tables containing historical currency snapshots. Providers register these tables even when their capability is disabled; absent tables before migration are skipped. Retained rows, including archived rows, must match the deployment's single currency before new monetary writes or combined monetary reports. The guard returns only a generic mismatch indication, without disclosing amounts or record counts. It never converts or rewrites snapshots and has no Finance dependency. Finance's existing wrapper delegates to it; Marketing and Operations use the same registry. Historical Sales values without an original currency snapshot are not retroactively assigned one.
