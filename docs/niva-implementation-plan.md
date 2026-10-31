# Niva CRM implementation specification

Niva needs one management workspace connecting quarterly plans, actual work and business results. Base CRM supplies reusable records and manual module foundations; the remaining work extends the owning modules while preserving the shared product. The first proposed implementation checkpoint is shared theme and Hebrew localization support.

## Scope and approved decisions

This specification completes the requirements review requested before application changes. The delivery sequence below is proposed scope, not a claim that the stakeholder requirements have been implemented or a commitment to a delivery date or price.

The user approved these installation requirements on 31 October 2026:

- Niva uses a green accent with neutral near-white surfaces. Purple remains the Base default.
- Niva uses a fully Hebrew interface and right-to-left layout, including navigation, forms, tables, charts, Gantt, reports and generated user-facing messages.
- All installations retain the shared Material 3 components, typography system, spacing, state layers, motion, elevation and accessibility behavior.

The stakeholder source is `CRM_Management_Handoff.html`, dated 27 October 2026, SHA-256 `705471b0603d30226da70f3c8d4d5460cc9148493e6e3390383122721e51d13e`. Its revised Gantt and review requirements supersede the corresponding original appendix wording. Other scope recommendations and measurement definitions remain subject to agreement. Its mockup is partial and contains illustrative data and simulated actions. Missing mockup screens do not cancel written requirements.

The source/code comparison uses accepted GitHub main `4c68b0d213062ca5dd62e9bed54480b04bc13e33`. The last verified deployment enables Sales; installed optional packages are distinct from enabled capabilities and explicit user permissions. No private installation manifest or stakeholder source file is copied into Git.

## Module boundaries

| Owner                      | Responsibility                                                                                                                                                          |
| -------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Core and shared host       | Authentication, shared identities, permissions, reusable work primitives, UI, theme/localization infrastructure, activity, private files and reminder delivery          |
| Sales                      | Opportunity records, stages, dated sales events, communications, proposals and customer-facing transactional journey                                                    |
| Executive                  | Goals, initiatives, milestones, quarterly Gantt, weekly planning, ideas, time planning, reviews, decisions and management presentation                                  |
| Marketing module           | Acquisition/ad/page/referrer attribution, campaign and web measurement, SEO/GEO, advertising costs, qualification/nurture sequences, marketing analysis and post drafts |
| Finance                    | Charges, expenses, payments, corrections, financial reconciliation, cash assumptions and profitability calculations                                                     |
| Customer Service and Sales | Customer contact, service feedback, retention and contract-related follow-up, through explicit optional relations                                                       |
| Integration adapters       | Provider authentication, verified incoming events, retries and reconciliation; orchestration stays with the module owning the business purpose                          |
| Installation configuration | Language, theme, enabled modules, labels, services, agreed business definitions, thresholds, templates and external account choices                                     |

One authorized identity can have multiple opportunities. One task appears in the Gantt, weekly plan and review without copies. Ordinary customer service work need not be attached to a growth goal. Commercial planning extends Executive; Nonprofit Projects retains its sector-specific ownership. The management dashboard composes authorized module data through extension contracts and shows unavailable sources explicitly. Disabling a module retains data and removes access and its active work.

## Requirements and current coverage

| Requirement                           | Current coverage                                                                            | Remaining scope                                                                                                                            | Delivery                                               |
| ------------------------------------- | ------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------ |
| Contacts, companies and opportunities | Implemented contact details, owners, stages, notes, dates, follow-ups and proposal versions | Client labels and distinct meeting/signature/payment lifecycle events                                                                      | Phase 1                                                |
| Repeat enquiries and lead intake      | Identity duplicate warnings and manual entry                                                | External submission IDs, idempotent intake, retained enquiry history and deliberate same-opportunity matching; never merge by name alone   | Phase 1                                                |
| Monday transfer                       | Create-only UTF-8 CSV                                                                       | Migration mapping for original IDs/dates, activities, attribution and private files; reconcile counts and source totals                    | Phase 1                                                |
| Quarterly planning                    | Flat manual goals with optional task links                                                  | Goal → initiative → milestone → task, timeline, blockers, dependencies, review dates and idea backlog                                      | Phase 1                                                |
| Weekly work and time                  | Owner, priority, due date, status and completion                                            | Planned/actual time, availability, service/client allocation and calendar overlap rules                                                    | Basic capture in Phase 1; advanced capacity in Phase 3 |
| Plan versus execution                 | Change and completion history                                                               | Original schedule, revised schedule, reasoned postponement/cancellation and retained baseline                                              | Phase 1                                                |
| Goal results                          | Manual numeric target/current value and status                                              | Unmeasured/qualitative results, source records, units, freshness, measurement dates and explicit risk rules                                | Phase 1                                                |
| Management dashboard                  | Sales totals and basic Executive summaries                                                  | Selected goals, relevant exceptions, weekly work, two trend charts and correct drilldowns                                                  | Phase 1                                                |
| Monthly and quarterly review          | No review/decision domain                                                                   | Initiative progress/result snapshots, comparable periods, decisions and links to existing or explicitly approved new work                  | Phase 1                                                |
| Marketing module campaigns            | Manual budgets, spend, reported leads and aggregate CPL                                     | Activity/cohort reports, service/source filters, paid-customer attribution and prior-period comparisons                                    | Phase 1                                                |
| Marketing module ads and web          | Manual attribution fields                                                                   | Ad metrics, site traffic, SEO, identifiable AI-origin traffic, connectors, freshness and measurement limitations                           | Phase 1, adapter-dependent                             |
| WhatsApp qualification and nurture    | Internal follow-up tasks/reminders                                                          | Approved drafts, rejection choices, welcome, inbound replies, opt-out/delivery state and allowed dispatch hours                            | Phase 1, adapter-dependent                             |
| Meetings and proposals                | Manual dated communications, uploaded proposals and internal quotation status               | Calendar/Meet, transcripts, reviewed drafts, recipient approve/questions and owner notifications                                           | Phase 1, adapter-dependent                             |
| Contract to onboarding                | No connected workflow                                                                       | All-party signature, payment-request draft approval, confirmed receipt and 90-minute kickoff                                               | Phase 1, adapter-dependent                             |
| Collections                           | Exact charges/partial payments, derived balances and due/payment-date views                 | Verified-receipt semantics, source reconciliation, corrections and Morning adapter; advanced collection presentation later                 | Events in Phase 1; advanced views in Phase 2           |
| Retention                             | Contacts, follow-ups and Service feedback foundation                                        | Last meaningful contact, renewal/end dates, departure reasons, recurring services and treatment queue                                      | Phase 2                                                |
| Cash and profitability                | Exact operational money foundation                                                          | Opening balance/assumptions, contractual expectations, service revenue/cost/time allocation, gross/net distinction and owner notional time | Phase 2                                                |
| Delegation and capacity               | Shared employee references and manual tasks                                                 | Availability, load, overrun, transferable work, training/cost/cash constraints                                                             | Phase 3                                                |
| Team confidentiality                  | Record ownership scopes and separate module grants                                          | Financial field/document/report permissions across every accessible surface, including monetary goals and time-cost data                   | Phase 1 foundation                                     |

## Existing constraints that require changes

- Authorized lead viewers currently receive estimated/final values, quotation amounts and pipeline totals. Hiding Finance navigation is insufficient for the stakeholder's staff role. Add explicit financial visibility rules and server redaction covering profiles, tables, selectors, search, filtering, sorting, CSV, documents, notices and dashboard contributions.
- Finance supports partial payments but explicitly rejects refunds, credits, overpayments and cancellation after payment. Agree VAT/correction/reconciliation rules before extending the ledger. Preserve immutable transactions and exact monetary values.
- Shared work history records changed field names, previous status and completion date, but not the previous due date. Start explicit baselines prospectively. Historical on-time performance remains unavailable unless reliable source evidence supplies the original schedule.
- Executive requires a numeric current value. A result not yet measured and a qualitative success condition need explicit model/UI support.
- Sales `closing_date` means actual closing date in current reports. Expected closing belongs in a separate field. Internal quotation acceptance is distinct from verified recipient approval, all-party signature and payment.
- Color is currently locked to the product palette, and installation locale currently permits only `en`. Niva's approved theme and Hebrew/RTL require shared infrastructure changes, not an ordinary settings edit.

Relevant source: [Executive](../packages/awove/executive/README.md), [Finance](../packages/awove/finance/README.md), [Marketing module](../packages/awove/marketing/README.md), [work history](../packages/awove/crm-core/src/Services/WorkItems.php), [Sales serialization](../packages/awove/crm-core/src/Http/CrmController.php), [table fields](../packages/awove/crm-core/src/Tables/Catalog.php), [installation settings](../packages/awove/crm-core/src/Installation.php), [CSV scope](../packages/awove/crm-core/src/Services/Spreadsheet.php).

## Phase 1 checkpoints

### 1 Shared theme and Hebrew foundation

Introduce a finite deployment-controlled semantic theme contract. Keep the existing neutral/purple theme as the default. Map Niva's proposed green `#23654E` to accessible light/dark accent roles; retain the near-white neutral canvas, neutral text, surfaces, fields, tables and navigation. Final role pairs need contrast verification rather than copying mockup CSS.

Add Hebrew localization and direction support to the host and enabled module surfaces. Keep English supported. Translate user-facing labels, validation/errors, empty states, menus, dialogs, table controls, dates, numbers, notifications, email sign-in and relevant report/export headings. Keep stable internal identifiers, provider IDs and machine-oriented values unchanged. Preserve email-only authentication. Verify mixed Hebrew/English names, email addresses, phone numbers, amounts and URLs; directional icons, pinned columns and the Gantt must work in RTL. Do not automatically translate authored record content, change the configured currency or convert/relabel retained amounts.

Acceptance: default English/purple and Niva Hebrew/green configurations work independently on desktop/mobile; shared controls, focus, reduced motion and existing advanced tables/save/reload behavior remain intact. Retain the collapsible sidebar, profile in sidebar, simple page title and no redundant header slogans.

### 2 Permissions and truthful source records

Add the staff financial-visibility boundary before enabling team access. Define the customer/opportunity/service dimensions and capture dated manual lifecycle events before reporting on them. Records must distinguish lead receipt, meeting scheduled/held, proposal sent/approved, all-party signature, charge and verified payment. Retain actual event time, recording time, actor/source and correction history. Module ownership and optional relations continue to apply; Sales must not become a second payment ledger.

Add original/current schedules and change reasons for planned work, plus basic planned/actual time capture. Define controlled service references before time and financial reporting rely on them. Establish a reviewed migration map preserving original source dates and IDs.

Acceptance: an authorized staff user sees permitted customer/work data but no restricted monetary values or proposal documents exposing them; repeated submissions do not create duplicate opportunities; a repeat sale can create a new opportunity; date changes preserve the baseline. Manual source records can support later reports while connectors are unavailable.

### 3 Quarterly and weekly planning

Extend Executive with initiatives, milestones and shared task relations. Show up to three selected initiatives by default, including future initiatives; this is a focus setting, not a hard cap. An initiative has a business goal, desired result, start/end dates, one to three success measures or a qualitative condition, measurement/review date, owner, planned time, optional budget, next step and blockers/dependencies.

Open initiative and milestone details progressively. Add quarter/week/ideas views of the same records. Define milestone completion explicitly, including any required deliverable approval. Retain original/revised dates and changes. Rescheduling must show its impact before changing dependent dates; first delivery can use forms instead of drag controls.

Acceptance: one task update is reflected everywhere; future work and unscheduled ideas are not overdue; ordinary service work remains valid without a growth goal; milestone progress never claims a business outcome was achieved. Advanced team capacity remains Phase 3.

### 4 Results and periodic decisions

Build Executive's management view with up to four selected goals, plan/execution, important exceptions, weekly priorities and two primary trend charts. Add the separate metrics view and monthly/quarterly reviews. Each result exposes its unit, period, source, last update, relevant filters and authorized source records. A review links back to the same initiative/Gantt record and retains the result as of its cutoff.

Manual verified sources from checkpoint 2 may supply results; absent sources remain unavailable. A decision records its evidence, chosen action, responsible user, due date and review date. It links existing work or creates a task only after an explicit decision. AI recommendations remain reviewable suggestions.

Acceptance: drilldowns open the correct goal/source; future or unmeasured results are labelled correctly; risk follows an agreed rule; decisions do not duplicate tasks. All relevant components honor the selected filters or visibly state their different scope.

### 5 Connected journey and Marketing module measurement

Implement adapters incrementally against the manual events and records established earlier. The requested journey is intake → qualification/booking → meeting → reviewed proposal → recipient approval/questions → contract → all-party signature → payment-request draft approval → verified receipt → 90-minute kickoff. Preserve every human gate.

Marketing module owns acquisition integration, source history, campaign/ad/page data, nurture and marketing reports. Classify WhatsApp by business purpose; shared delivery infrastructure may also serve transactional Sales. Provider messages/callbacks require authenticated ingestion, durable replay protection, idempotency, retries, visible failures and reconciliation. A verified provider connection is a prerequisite to live delivery, not to a clearly marked manual workflow.

Acceptance: one provider event/retry creates one outcome; invalid/stale approvals do not send; follow-ups approved outside Sunday–Thursday 09:00–15:00 wait for the next permitted window in `Asia/Jerusalem`, rechecked at dispatch/retry. Immediate welcome is a separately configured anytime rule. Signatures do not imply payment; partial receipt does not imply full payment. External document issuance remains a deliberate human action.

## Measurement rules

- Count identified opportunities, not every enquiry or follow-up meeting. A new sale to the same identity remains distinct. A new paying customer uses the first verified receipt; returning customers are labelled separately.
- Separate event activity in a period from conversion of an acquisition cohort. State the cohort cutoff/maturity window; count distinct opportunities reaching each stage.
- Keep signed commitments, billed amounts, outstanding balances, verified cash, open pipeline, recurring contractual value and recurring-service receipts as separate measures.
- Compare full periods or matching elapsed periods explicitly. Show absolute money differences and percentage changes; conversion changes use percentage points. Zero denominators yield unavailable ratios.
- Keep planned budget and actual spend separate. Specify whether CPL/CAC includes media only or also labor/production. Unknown spend cannot yield a zero-cost acquisition claim.
- Marketing lead conversion is leads divided by landing-page visits when measurable; link CTR is link clicks divided by impressions. Reach needs an appropriate deduplicated period aggregate. GEO includes only identifiable traffic and states its measurement limits.
- A chart has one defined source/basis, unit, update time and filter scope. Missing, disconnected, stale and unmeasured are explicit states. No fabricated zeros, forecasts or allocations fill gaps.
- Task performance uses completion against the original plan for work whose original due date has arrived, with the revised plan shown separately. Postponement/cancellation is not completion. Business outcome attainment remains a separate result.

## Later phases

Phase 2 adds retention/renewal treatment, customer concentration, advanced collections, contractual forecast, controlled opening balances/cash assumptions and client/service profitability. Capture time and needed lifecycle data in Phase 1. Define direct/indirect costs, owner notional time, VAT and corrections with the person responsible for the financial figures before calculations. Gross profit is never presented as net profit.

Phase 3 adds team availability, actual versus planned load, delegation/training/cost scenarios and advanced management AI. Hiring/delegation suggestions depend on hours, cost, training and cash assumptions and remain human decisions. AI cannot create binding work, issue documents or send follow-ups by itself.

## Decisions and external prerequisites

These are scoped decisions, not reasons to delay independent theme/localization work. Preserve existing tools until a replacement is explicitly chosen.

| Topic                         | Required definition or access                                                                                                                 | Blocks                                        |
| ----------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------- |
| Services and business metrics | Service list; active/ended customer; signed-deal evidence; first verified payment; recurring-service basis; initiative success and risk rules | Final event mapping and business reports      |
| Financial policy              | Currency and VAT basis, correction/refund policy, allocation, actual receipt verification and kickoff threshold for partial payments          | Financial workflow and profitability          |
| Migration                     | Monday export/sample mapping, original event dates, files, source identifiers and reconciliation totals                                       | Production import                             |
| WhatsApp                      | Actual Business account/provider eligibility, sender access, opt-in/template requirements, language/tone and send policy                      | Live welcome, replies and approved follow-ups |
| Booking and calendar          | Provider, calendar, booking service, discovery duration, availability and reminder rules                                                      | Calendar/Meet connection                      |
| Meeting recorder              | Exact Timeless product/account and API/export access                                                                                          | Transcript/recording import                   |
| Signature and Morning         | Accounts/API capabilities, templates, signer roles, document types and approval mapping                                                       | External contract/payment-document steps      |
| Marketing module sources      | WordPress forms, Meta accounts and web/search analytics access; agreed attribution/cohort model                                               | Automated acquisition and measured dashboards |
| AI                            | Scope/provider, data policy and approved templates                                                                                            | Generated drafts and management suggestions   |

Provider capability, setup, recurring costs and implementation estimates require actual account/API assessment. No subscription purchase, tool replacement, bank connection or production message is implied by this specification.

## Delivery gates

For each implementation checkpoint, review a feature-branch PR in `Noam881/basecrm`, run checks appropriate to the change and demonstrate persisted desktop/mobile behavior. Exercise Base defaults and the Niva profile, permitted/denied roles, enabled/disabled counterpart modules, migrations and retained IDs/settings/layouts. Use fictional isolated fixtures, not live customer records. Add meaningful contract/regression checks for the new behavior rather than tests duplicating implementation details.

Before connected production release, reconcile migrated and imported totals, test replay/dispatch/approval boundaries and confirm the installation manifest and explicit grants. Deploy only an accepted commit with a backup and recovery path. The requirements review and this plan do not mark any remaining feature complete or authorize automatic live integration activation.

Related documentation: [source of truth](source-of-truth.md), [architecture](modular-architecture.md), [generic requirements baseline](requirements-matrix.md), [Material 3 system](material-design.md), [requested Sales integration journey](niva-sales-workflow.md). This client specification extends the generic baseline; the latter's completed checkpoints do not establish completion of this new scope.
