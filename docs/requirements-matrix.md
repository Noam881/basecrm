# Generic CRM requirements matrix

Source: user-supplied “AWOVE — MODULAR CRM IMPLEMENTATION”, preserving section 5 of *Generic CRM and Business Modules*, v1.0, 20 October 2026. The original `CRM_MVP_Module_Requirements.docx` was not found in Downloads, Desktop, Documents or attachments and could not be cross-checked. Do not attribute the prompt's engineering instructions to the unavailable document.

This matrix is a delivery ledger, not a declaration that every module is complete. Status vocabulary: **Verified existing**, **Implemented and tested**, **Partial**, **Not implemented**, **Blocked**, **Deferred by source**. Existing evidence is the pre-change PostgreSQL run (171 tests / 2,499 assertions), frontend formatting/type/build and Compose configuration. New combined verification is tracked in `module-status.md`.

| Source requirement | Implementation / existing gap | Owner | Verification | Status |
|---|---|---|---|---|
| Shared identities, contact/company creation/edit/search, multiple company contacts | `crm-core` Identities, ContactDetails, people profiles; one directory | Core | IdentityTest, ContactDetailsTest, FoundationTest | Verified existing |
| Contact duplicate warnings, never silent merge | Identity normalized email/phone/name warnings, explicit override | Core | IdentityTest, FoundationTest | Verified existing |
| Record IDs, ownership, archive, append-only activity | Existing lead/task services/policies/activity; contact/company append-only field-name audit added | Core / Sales | FoundationTest, LeadWorkflowBasicsTest, IdentityHistoryTest | Implemented and tested |
| Shared reminders, retry/permission/version guards | Existing lead reminders; generic WorkReminders / ReminderSource contributions | Core | FoundationTest, CustomerServiceTest; full PostgreSQL and desktop/mobile workflows | Implemented and tested |
| Basic spreadsheet transfer and validation | Bounded UTF-8 CSV template/export, no-write preview, user/install-bound single-use confirmation; no XLS/XLSX claim | Core | SpreadsheetTest; desktop/mobile preview/confirm/export | Implemented and tested |
| Optional module lifecycle / dependencies / capabilities | Modules registry, `crm.module`, declared sub-capabilities; legacy Sales default; explicit optional permission grants | Core | ModuleLifecycleTest, DashboardContributionTest, RecordTableContributionTest | Implemented and tested |
| Frontend discovery / installed package builds | Composer packages under vendor compiled by existing Vite glob | Shared host | Core-only mirrored build: both optional packages physically absent; Foundation24/293 passed | Partial |
| Separate installation branding/configuration | Deployment manifest, locked asset/module paths, existing Alpha/Beta isolation; currency/capability additions | Core | FoundationTest, ExtensionTest, ModuleLifecycleTest | Partial |
| Shared employee references without login/recruitment | No employee-reference model yet; do not use users as a substitute | Core | Checkpoint 3 pending | Not implemented |
| M01 contact/company opportunities and multiple opportunities/customer | Existing `crm_leads`, existing relational identities, no second pipeline | Sales | FoundationTest, SalesCompletionTest | Implemented and tested |
| M01 needs / estimated amount / closing / final value / loss reason | Additive lead columns; decimal values; unknown historical totals explicitly indicated | Sales | SalesCompletionTest | Implemented and tested |
| M01 stages and next action | Existing versioned status bubbles and follow-ups; add stable `discussion`; preserve client labels and legacy stages | Sales | LeadWorkflowBasicsTest, ModuleLifecycleTest, SalesCompletionTest | Implemented and tested |
| M01 manual calls/meetings/emails/messages | Typed dated authored communication entries, separate from existing notes | Sales | SalesCompletionTest; sales-completion.spec.ts | Implemented and tested |
| M01 quotations, states and retained accepted document | Quotation metadata/state linked to existing immutable private proposal version or safe document link | Sales | ProposalTest, SalesCompletionTest | Implemented and tested |
| M01 overview: stage/pipeline, due/overdue, won final values, owner/date | Existing dashboard plus date/owner-filtered decimal Sales overview | Sales | DashboardTest, SalesCompletionTest | Implemented and tested |
| M01 company authored notes | Additive CompanyNote, common identity profile composer; integration in progress | Core / Sales | CompanyNotesTest, SpreadsheetTest and desktop/mobile profile flow | Implemented and tested |
| M02 management tasks / goals | Not built; use shared work primitives rather than fake lead rows | Executive | Checkpoint 2 pending | Not implemented |
| M02 routines and dated completions, manual next occurrence | Not built; no automatic recurrence authorized | Executive / Core reusable routines | Checkpoint 2 pending | Not implemented |
| M02 filtered management overview | Not built | Executive | Checkpoint 2 pending | Not implemented |
| M03 employee directory, roles/manager/start/status/restricted documents | Not built; employee identity separate from login | HR / Core employee references | Checkpoint 3 pending | Not implemented |
| M03 vacancies/candidates and specified recruitment stages | Not built | HR recruitment | Checkpoint 3 pending | Not implemented |
| M03 onboarding/offboarding checklists/completion/inactive retained identity | Not built; manual access checklists must not imply provisioning | HR | Checkpoint 3 pending | Not implemented |
| M03 workforce overview | Not built | HR | Checkpoint 3 pending | Not implemented |
| M04 independent collections / expenses / payroll preparation | Capability contract added; business records not built | Finance | Checkpoint 3 pending | Not implemented |
| M04 customer charges/partial received payments/outstanding/action | Not built; fixed precision and idempotency required | Finance collections | Checkpoint 3 pending | Not implemented |
| M04 supplier expenses/partial payments/documents | Not built; orders remain distinct | Finance expenses | Checkpoint 3 pending | Not implemented |
| M04 employee/pay-period uniqueness and agreed-input export | Not built; requires employee reference, not recruitment | Finance payroll preparation | Checkpoint 3 pending | Not implemented |
| M04 expected vs actual by correct date and single currency | Currency role added; financial reporting not built | Finance | Checkpoint 3 pending | Not implemented |
| M05 shared Customer/Supplier classification/directory links | One identity directory exists; contextual classifications missing | Core / Operations | Checkpoint 2 pending | Partial |
| M05 operational requests/statuses/history/due/attachments | Shared RequestFields emerging from Service; Operations domain not built | Operations | Checkpoint 2 pending | Not implemented |
| M05 supplier orders/partial receipt/outstanding items | Not built; no implicit expense/payment | Operations | Checkpoint 2 pending | Not implemented |
| M05 routines/checklists/manual next occurrence/overview | Not built | Operations / Core reusable routines | Checkpoint 2 pending | Not implemented |
| M06 request/customer/received/channel/owner/priority/action/date/status | New `customer-service` package, persisted forms/scoped queries | Customer Service | CustomerServiceTest; desktop/mobile persisted workflow | Implemented and tested |
| M06 history/resolution/document/reopen without losing history | Authored dated history, scoped HTTPS document link, retained reopen history | Customer Service | CustomerServiceTest | Implemented and tested |
| M06 feedback with follow-up | Persisted feedback; implementation decision: 1–5 (1 lowest, 5 highest) | Customer Service | CustomerServiceTest | Implemented and tested |
| M06 overview/status/owner/customer/priority/date filters/export | Service-owned views and restricted CSV export | Customer Service | CustomerServiceTest | Implemented and tested |
| M06 works with Operations absent, disable preserves and suppresses work | No dependency on Operations; generic fresh-guarded reminder source | Customer Service / Core | CustomerServiceTest, ModuleLifecycleTest, core-only absence build; Service desktop/mobile with Operations absent | Implemented and tested |
| M07 procedures/reusable materials/training by role/topic | Not built; completion links optional | Knowledge | Checkpoint 4 pending | Not implemented |
| M07 renewals/expiry/document/reminder | Not built; no silent stale-document interval | Knowledge | Checkpoint 4 pending | Not implemented |
| M07 system login URLs/owners/password-manager references | Not built; never store secrets | Knowledge | Checkpoint 4 pending | Not implemented |
| M07 overview / missing owner/document | Not built | Knowledge | Checkpoint 4 pending | Not implemented |
| M08 campaign plan/budget/materials/status | Not built | **Marketing module** | Checkpoint 4 pending | Not implemented |
| M08 manual reporting periods/spend/leads/no overlaps/CPL | Not built; ratio is aggregate spend / reported leads; zero leads means unavailable | **Marketing module** | Checkpoint 4 pending | Not implemented |
| M08 content calendar/planned and actual publication | Not built; manual planning only | **Marketing module** | Checkpoint 4 pending | Not implemented |
| M08 optional Sales campaign link and source | Legacy attribution remains intact in core pending data-preserving extraction; no new Marketing behavior there | **Marketing module** / Sales relation | Checkpoint 4 pending | Partial |
| M08 filtered marketing overview | Not built; never combine reported leads with linked opportunities | **Marketing module** | Checkpoint 4 pending | Not implemented |
| M09 assets/register/location/status/ID/serial/documents | Not built | Assets | Checkpoint 5 pending | Not implemented |
| M09 assignments/returns/condition/history | Not built; employees from shared references | Assets | Checkpoint 5 pending | Not implemented |
| M09 maintenance/due/completion/cost/manual next date | Not built; no implicit Finance transaction | Assets | Checkpoint 5 pending | Not implemented |
| M09 filtered overview/overdue returns and maintenance | Not built | Assets | Checkpoint 5 pending | Not implemented |
| M10 restricted participants/groups/locations/programs | Not built; selected capabilities only | Nonprofit | Checkpoint 6 pending | Not implemented |
| M10 unique attendance/period/program/location summary | Not built | Nonprofit | Checkpoint 6 pending | Not implemented |
| M10 contextual donors/partners/pledges/restrictions | Not built; no duplicate address books | Nonprofit / shared identities | Checkpoint 6 pending | Not implemented |
| M10 projects/plan/tasks/budget/meetings/decisions/feedback | Not built | Nonprofit | Checkpoint 6 pending | Not implemented |
| M10 actual receipt/financial comparisons | Not built; unavailable without Finance | Nonprofit + Finance | Checkpoint 6 pending | Not implemented |
| M10 purchasing/equipment optional references | Not built; reuse Operations/Assets only when enabled | Nonprofit + Operations/Assets | Checkpoint 6 pending | Not implemented |
| Transfer folder | No migration requirement identified; no client-facing module | Operator tooling | No transfer run | Deferred by source |
| All explicit Later customization features | No signatures, account provisioning, integrations, AI, auto recurrence, accounting/tax/banking, stock, portals, marketing publishing/automation or specialist property processes added | Owning modules | Scope audit | Deferred by source |
| Cross-module combinations / additive historical upgrade rehearsal | Existing Alpha/Beta baseline evidence; new combinations and update rehearsal pending | Shared host / all modules | Checkpoint 7 pending | Partial |
