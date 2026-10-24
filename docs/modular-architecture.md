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

Ownership beyond Checkpoint 1 is described by the requirements matrix, not by empty registered modules. Marketing campaigns, content, spend/performance and attribution belong to the optional **Marketing module**; none are claimed implemented here. Employee references will be shared independently of HR recruitment. Request/routine/task UI or completion mechanics may be shared, but each business module retains its own statuses, rules and scopes.

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
