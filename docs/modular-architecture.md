# Modular CRM architecture

The application remains the existing Laravel/Vue/TypeScript/Inertia/PostgreSQL modular monolith. Each client has its own database, environment, persistent private storage and deployment manifest. There is no shared-database tenancy, module database, SaaS billing, marketplace or remote plugin uploader.

The shared host owns runtime/authentication and installed-package discovery. `awove/crm-core` owns shared identities, access primitives, settings, design system, activity, files and reminders, plus the retained historical Sales domain. `SalesModule` identifies that existing domain without moving IDs or creating another pipeline. `awove/customer-service` owns its records, policies, request/feedback forms, history, reports and reminder sources. The existing `example-service-details` remains isolated and disabled outside its explicit fictional Beta configuration. Existing client-specific attribution is retained, not repurposed into generic integrations.

Installed Composer code, deployment-enabled modules/capabilities, and user permission grants are separate. `Modules::validate()` rejects missing/incompatible code, unknown sub-capabilities, missing required dependencies and circular dependencies. Optional packages register ordinary Laravel providers and implement the stable Module contract (contract 1); independently selectable sub-capabilities implement CapabilityProvider. Capabilities use stable `module:capability` identifiers. Optional counterpart links must check both availability and record authorization. No dependency on a module may be inferred from a hidden sidebar link.

Legacy profiles retain Sales by default. `capabilities.sales=false` explicitly disables its HTTP entry points, query contributions and reminder access, while the overview renders authorized workspace links instead of fabricated zero Sales metrics. Optional modules require their identifier in `modules`. A declared sub-capability additionally requires an explicit boolean selection in `capabilities`. Data and migrations remain installed when disabled; no ordinary web request runs migrations.

The HTTP `crm.module` guard precedes domain validation. Each domain must additionally scope record/relation queries and authorize mutations, exports and files. Module enablement never grants optional-module permissions to staff or existing administrator roles. Deployment operators provision exact permissions explicitly through `crm:permissions`. Existing grants are retained on repeated install. Shared identity access is not permission to read HR, payroll, candidate or participant records.

Contacts and companies share authored notes and the same identity profile composer; append-only identity activity stores authors/times/changed field names, not copied private values.

The existing lead reminder pipeline is retained. Generic WorkReminders accepts typed ReminderSource contributions from enabled modules. Queued delivery and each preview/read resolve the current source, recipient, version, due state and authorization again. The generic table retains IDs, recipient/version and read state, not a sensitive cached preview. Completion/rescheduling/archive/access loss/disablement make obsolete work invisible. Reading a notice does not complete a record. No frequency field generates future tasks or routine occurrences.

Vite resolves pages/panels from installed `vendor/awove/*/resources/js`; the Customer Service Composer package therefore participates in actual production discovery. Deployment builds mirror path packages instead of relying on development symlinks. Client extensions use existing LeadPanel/LeadTableContributor, events, navigation, domain services and new reminder contracts; do not copy core pages or patch vendor code. [Existing service-details example](../packages/awove/example-service-details/src/ServiceDetailsModule.php) remains the working extension example.

Configuration precedence remains defaults → deployment manifest → permitted persisted settings → permitted user display preferences, with deployment locks winning. Asset paths, enabled modules/capabilities, module labels and currency are deployment controlled. Generic fresh Sales stages are New, In discussion, Proposal sent, Won and Lost; stable IDs and existing renamed/history stages are retained. The fictional Alpha/Beta legacy-stage flag keeps their extra Contacted/Qualified fixtures. English display labels and Hebrew navigation aliases are distinct from internal keys. Existing neutral/purple design tokens and B branding are preserved; this implementation does not redesign the interface.

Checkpoint 2 adds optional `awove/executive` and `awove/operations`; its combined verification is tracked in the requirements matrix and module status, separately from Checkpoint 1 evidence. Marketing campaigns, content, spend/performance and attribution belong to the optional **Marketing module**; none are claimed implemented here. Employee references are shared independently of HR recruitment (Checkpoint 3 in progress). Request/routine/task UI or completion mechanics may be shared, but each business module retains its own statuses, rules and scopes.

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

Registered module tables implement RecordTableContributor and reuse Catalog, Query, Preferences and DataTable; core has no imports from the business package. Each contributor supplies trusted SQL columns and a record-scoped builder. The controller must still redact model attributes and related identities before serialization: a column whitelist alone is not a universal field-redaction layer. Disabled/unauthorized table definitions, preferences and search/filter expressions are rejected. Saved personal layouts survive disablement and are filtered against currently authorized fields on return. DashboardContributor and ReminderContributor follow the same enabled-code/permission separation.


## Shared work and contextual identities

`WorkContributor` declares typed `WorkType` and `RoutineType` descriptors. The shared WorkItems/Routines services enforce the owning module, kind, own/all/create/edit/assign/archive permissions, current active-user state and optimistic versions. Each module owns business-specific relational tables and wraps shared changes in one transaction. Historical lead-bound `crm_tasks` retain their IDs and reminder pipeline; a management task or operational request never creates a dummy lead.

A terminal status suppresses reminders; a completed status also receives a completion date. These sets differ: a cancelled operational request is terminal without claiming completion. Routines store descriptive frequency and a manually chosen next due date. Completing an occurrence locks the record, retains the occurrence date, actor and checklist snapshot, and clears the next due date unless a person supplies another. Completion history survives later checklist or schedule edits; frequency text is never interpreted as an instruction to generate records.

`IdentityRelationships` adds Customer, Supplier, Donor, Partner and Prospect classifications to the existing contact/company IDs. PostgreSQL enforces exactly one contact or company, one classification per identity/role, and retained history. Name/phone/email come from the existing directory, not a copied address book. A company main contact is independently scoped and redacted if no longer visible. Assigning responsibility does not grant identity or related-module access. Classifications have explicit shared view/manage/assign permissions; `identities.view.all` permits directory reading without identity editing. These new grants are never automatically assigned to existing roles.

`Csv` is a format-only shared parser/download utility, including UTF-8/quote/size/header checks and formula-safe headers/cells. Modules still own record scope, export bounds, field redaction and any preview/confirmation writes. `DocumentLinks` validates HTTPS links and emits private/no-referrer redirects after the owning module has freshly authorized the parent; it does not fetch files or turn a link into a public download permission.


## Employee references and restricted domain data

`EmployeeReferences` supplies only stable ID, name and active state to domain selectors. It is independent of HR recruitment, authentication and Contacts. Optional explicit links to existing contacts/accounts are unique and separately scoped. Hidden links survive ordinary redacted-null edits; creation never merges names. Domains independently authorize their private parent records and redact employee summaries when reference access is lost.

HR owns employment, recruitment, CV/document access, cases and final-pay information. Finance owns collections, expenses and payroll as independent declared capabilities. Payroll snapshots deployment-agreed input definitions per employee/period and retains exact strings, supporting-link history and versioned manual status history. No shared reference serializes HR or payroll information. `Money` reuses locked Brick Math for exact two-decimal values and aggregates; browser decimal fields use text/decimal input mode rather than number-coercing v-model bindings.


Saved table preferences remain server-owned data. Disabling a contributor or losing a field permission projects out its columns, widths, pins and view filters/sorts, while ordinary visible edits preserve dormant fragments from locked server storage only. Incoming hidden IDs/values are rejected. Shared authentication serialization omits raw display preferences, and the legacy Leads alias exposes only the current safe table projection. Explicit view deletion remains explicit. User display timezones are resolved through `Installation::display`, retaining deployment locks; operational date-only decisions remain installation-scoped.
