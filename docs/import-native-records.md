# Ordinary native import writer

`Awove\Core\Imports\NativeRecords` writes ordinary Contacts, Companies and Leads using their existing native services, validations, Gates, tables and integrity events. It adds no schema, source transport, raw staging, user accounts, HTTP endpoints, UI or production provider configuration. No real provider/role is registered: all sources default to deny.

## Trusted adapter boundary

A reviewed server service provider must register an exact provider/role with the singleton `NativeSources` and a `NativeSourcePolicy`. Registration is not exposed through HTTP, a manifest or a caller-supplied class. The policy receives source IDs, kind, operation, field **names**, and metadata-only relationship context. It must pin approved boards/items/party roles, explicit duplicate decisions, canonical identity choices, and each originating source's contact/company/owner choice. Merely accepting every verified mapping in the same account is insufficient.

The context contains independently checked mapping ID/board/entity descriptors, explicit `actor` owner choices, the duplicate flag and reviewed currency. Reference policies additionally receive the originating source and field. Canonical alias policies receive the actual chosen descriptor. Validation and mapping/Gate checks follow policy authorization and repeat before commit. Policies authorize; they must not mutate domain data or send communications. There is no built-in monday policy or automatic source account access.

Contacts/Companies/Leads use truthful `item` source entities. Existing owner users may be referenced through verified `user` or `account` source entities (for an explicitly reviewed account fallback). The writer does not create a CRM user, impersonate a source creator or invent a source user ID. Selecting an owner requires current active/verified status, native lead-view permission and assignment permission where applicable.

## Public server-side API

- `create(actor,boundary,batchId,kind,source,input)` creates an identity or distinct opportunity. Kinds are `contacts`, `companies`, `leads`. Every distinct opportunity source ID has its own immutable Lead target; Leads cannot be consolidated.
- `apply(actor,boundary,batchId,entityMappingId,input)` updates only that actual verified native target. It accepts no target model/class/ID.
- `alias(actor,boundary,batchId,kind,source,canonicalDescriptor,requestKey)` records an explicitly reviewed Contact/Company consolidation onto a verified canonical mapping. It accepts no free native target ID and performs no field merge. It cannot alias Leads.

`source` is exactly `{board_id,entity_type,entity_id,target_role,relation_key}` under the shared bounded metadata grammar. `canonicalDescriptor` and non-null native relationship values are exactly `{mapping_id,board_id,entity_id}`. Their independently supplied IDs must match the actual verified mapping under the same boundary/provider/account; an owning source policy must authorize the exact choice. The mapping must have a retained verified native context receipt. Owner `actor` is an explicit policy-controlled choice; a free user ID is rejected.

`input` is exactly `{request_key,fields,accept_duplicate,currency}`. All request keys are UUIDs, distinct within a plan. `accept_duplicate` is a boolean explicitly reviewed by the source policy, never an automatic name-based merge. `currency` is null or a three-letter code. `fields` is a nonempty map of at most 200 supported field IDs. Each field is exactly `{available,value,request_key,previous_receipt_id}`. `available:false` requires null value and returns unresolved without inventing source data. An omitted field is not touched; explicit null is a supplied value. A new Lead requires explicit source and owner decisions, a real native stage, and at least one verified identity reference. The writer does not default an unknown source to Direct.

Supported fields are native ContactDetails (except acquisition `Contact.source`), Company attributes, native Lead identity/owner/title/stage/source/priority, all current `Sales::opportunityRules`, historical `entered_on`/`created_at`, and individually registered ordinary `custom:<key>` fields. Registered custom values use the existing manifest validation; unknown fields and reserved Marketing custom names are rejected. Marketing attribution/qualification/consent, Vault values, Finance ledger/payroll facts, source authors, notes/history, native IDs/creators, versions, archived state and updated_at are outside this writer. Native validation remains authoritative, including lengths, dates, duplicate checks, permission and referenced-record access.

## Per-field conflict contract

The main entity mapping attests only stable source context and native kind/ID. Mutable business values do not change that context fingerprint. Each supplied field has a separate stable `native-field` mapping, role relation keyed from the main source role/relation plus the exact field ID. Source and actual native target values use the same keyed, canonical destination representation so `FieldConflict` can compare their observed digests as opaque snapshots. No raw prior snapshots or customer values are persisted in the Core metadata ledger.

A fresh object and all supplied initial field baselines commit together. For existing objects, an unmapped field may establish a baseline only when the actual native value already exactly equals reviewed source. A differing value requires explicit reconciliation; there is no blind empty-field fill or initial overwrite.

For established fields:

- Unchanged source preserves the actual CRM value, including human edits, without adding a field receipt or advancing the applied baseline.
- Changed source applies only when current target equals the last actually applied target, the expected previous verified receipt ID matches, and mapping/code/plan revisions match.
- Changed source plus a changed CRM target is a retained conflict. This includes a target already matching the new source: the writer supplies its **actual** observed hash and respects Ledger's target-drift refusal rather than spoofing an old observation.
- A stale expected receipt, altered retry or mismatched context fails. Matching verified request replay reads the actual target; if the CRM has since changed, the value is preserved and reported `unchanged_source`, never overwritten or claimed newly applied.

Requested changed fields are planned together. If one conflicts, other ready changes are not applied. Application uses only chosen changed fields, merging custom fields against current native data so an unrelated human edit is never copied back from old source. Native normalization that would alter an unplanned dependent business field requires an explicit expanded plan; this avoids quietly clearing an untracked secondary-phone label, for example. Actual readback must match before and after final field receipts. Native string stage keys remain strings (including numeric-looking stage keys); FK relationship IDs use native integer semantics.

Core money requires exact string/integer input, existing `Money` normalization, an explicitly matching reviewed source/installation currency, and retained currency consistency. Currency is rechecked at commit; no conversion or USD/ILS relabeling occurs. Registered custom scalars follow their actual native JSON representation. Native source freshness and semantic field ownership remain the reviewed adapter's responsibility; the Core writer does not query an external source.

## Dates and attribution

Ordinary direct Lead service create/update still reject `entered_on`, `created_at` and `updated_at`. HTTP keeps its established contract: entered_on is rejected; readonly created_at/updated_at inputs are ignored while legitimate validated edits apply. The internal historical hooks require a fresh active verified `imports.manage` actor and native create/update authorization. Only entered_on (nullable strict DATE) and exact RFC3339 created_at are accepted. Fractional source creation timestamps are floored to the existing native schema's whole-second precision, not rounded or replaced with a date-only midnight. There is no historical updated_at write. Business entry dates retain date-only semantics.

The actual importing operator remains native created_by and audit actor; native audit times remain current. Exact original source timestamps, offsets/fractions, authors and update/reply history must be retained separately through the existing authorized LegacyHistory capability. This writer alone does not claim that provenance/history/files are migrated.

## Transactions, holds and replay

Fresh actor/import permission, enabled Sales capability, actual Runtime boundary/database identity, open batch and current target/related record Gates are checked around writes. Source-family advisory locks serialize source retries; native row locks and versioned services protect actual fields. Main targets stay immutable. A repeated or expired claimed request is status-only and never authorizes adoption of an uncertain result.

Metadata reservation groups live outside the business savepoint but inside the outer transaction. New native writes, new-Lead holds and actual finish receipts share a business savepoint. Existing-resource holds are placed before owning row locks and remain in the outer transaction; they can remain held after a safe no-write refusal, protecting old tasks pending review. If a finish is not verified, business writes/finish receipts roll back while original reservation clocks remain retained; expired reservations become reconciliation-required without extending their deadline. A pre-existing inflight claim is not taken over. No source/network/file I/O runs in these SQL transactions.

Before changing an existing Lead, its persistent import hold is placed **before** native owning-row locks. Existing identity mutations hold and authorize all actual dependent Leads before identity locks, then recheck the dependency set. New Leads receive their hold before the outer commit and before their after-commit LeadCreated event can run. Current held state is asserted before committing. Holds do not expire or auto-release. Existing integrity/security events and ordinary reminders are not globally disabled; their existing enqueue/delivery guards suppress held resources, including already queued tasks.

Results expose states and actual receipt/target IDs, not source payloads. `verified` concerns this concrete SQL plan and its explicit field results, not completion of an entire migration. `unchanged_source` is intentional preservation, not proof that current CRM equals source. `conflict`, `not_applied`, `claimed`, `busy`, `reconciliation_required` and `unresolved` require explicit coordination/review. This capability implements neither destructive reversal nor a file journal/recovery guarantee. Actual migration still requires reviewed mappings, target backup, owning module writers, provenance and file transfer checks.
