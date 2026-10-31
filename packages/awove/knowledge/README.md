# Knowledge Management

Optional package `awove/knowledge` 1.0.0, module ID `knowledge`, core `^1.1`, contract 1. Source navigation alias: **ניהול ידע**. The interface uses English labels. No Sales, HR, Operations, Executive or Finance dependency is required.

## Records and manual workflows

- **Library** holds typed procedures, reusable materials and training. Search title/topic, category and relevant role, plus shared advanced table search, sorts and field filters. Procedures use stable Draft / Current / Archived states, current version or revision date, and document references. A Current procedure requires a version or revision date. Materials require purpose and use classification. Training requires its intended role. Obsolete records are archived with stable IDs and history.
- **Renewals** track a license/insurance name, provider, owner, expiry, optional explicit reminder date, supporting documents and notes. Reversible implementation defaults are Pending / In progress / Renewed, configurable through `knowledge_renewal_statuses`. Record renewal is a versioned dated action retaining the previous expiry, completion date, optional summary and explicitly entered next expiry. A next reminder is optional and requires a next expiry. No recurrence is generated. Leaving next expiry blank leaves a retained Renewed record; it stops reminders. Renewal history cannot be overwritten or deleted.
- **System references** hold a system name, access owner, public HTTPS login page and password-manager item name or opaque item ID. URL user information, query strings/fragments, credential-labelled values and common token formats are rejected. There are no password, key, credential, sync or vault fields. The public login redirect is authorized on every request.

Library status label overrides use `knowledge_library_statuses`. Defaults and stable IDs are never reseeded over client labels. Dates use the installation timezone for due/expired classification; the shared user display timezone formats timestamps.

## Scope, documents and safe references

Every section has explicit `knowledge.{library,renewals,systems}.{view.own,view.all,create,edit.own,edit.all,assign,archive,export}` permissions. Module enablement grants none automatically. Direct service writes reload the active user and check the current record version. Own scope requires ownership; view-all can include unassigned records. Only assignment permission permits choosing another eligible active owner or leaving ownership blank. Existing inactive owners are retained during unrelated edits.

Document metadata and links additionally require `knowledge.documents.view`; adding/archiving requires `.manage` and edit access to the parent. Stored HTTPS references are immutable: add a replacement and archive the original. Archived links remain retained and readable to authorized parent/document viewers. URLs are never table search fields, CSV cells or raw document DTO fields. Authorized profiles receive internal redirect links only.

System login/item fields additionally require `knowledge.systems.references.view`; edits also require `.edit`. Without these grants the fields, redirect, history field names and values are absent; ordinary name/owner edits retain hidden existing values. System CSV exports contain only ID, name and owner, and generic search never searches item references or login URLs.

## Instructions linked to existing work

Knowledge owns `knowledge_instructions`, with one typed FK to an existing Core WorkItem or Routine and a separate Knowledge entry FK. No substitute work or completion record is created. Choosing a target uses enabled WorkTypes and the owning Core service's fresh scoped query. Adding uses both current entry and target versions. Duplicate active links are rejected; removing archives the relation, and re-linking retains the same relation ID/version history.

The shared `WorkReferenceContributor` contract contributes current, unarchived and authorized library entries to existing Executive task/routine, Operations request/routine and HR checklist profiles. Core independently authorizes the parent. Knowledge independently authorizes the entry. Hidden/disabled parent relations are omitted without returning their IDs, labels or counts, and remain stored through unrelated entry edits. Draft/Archived entries contribute no instructions. Actual completion stays in the owning work module. The Knowledge profile offers only accessible work links; each target keeps its existing permissions and lifecycle.

## Overview, reminders and CSV

Overview counts are permission scoped: current materials, library records missing an owner, upcoming renewals in the **next 30 calendar days**, expired renewals, and actual missing library documents/revisions. Missing-document metrics/filters require document-view permission. Missing means no active reference, no procedure version/revision date, or no material/training updated date. No document age or stale interval is invented. Periods use updated document dates for library records and expiry dates for renewals. System references have no artificial due date. Owner, kind, role/category and period filters persist through table operations and scoped CSV exports.

Explicit renewal reminders use the shared Core reminder queue. Previews are reconstructed from current version, owner, active account, grants, status and module enablement. Unassigned, completed, archived, revoked or disabled work supplies no reminder preview. CSV export reuses Core strict formula escaping and current scoped filters, preflights 5,000 records, and excludes document URLs, system item/login references and renewal notes. No import engine, external provisioning, credential vault, AI, financial dependency or recurrence is introduced.

## Local verification

`tests/Feature/KnowledgeTest.php` covers persisted metadata, version conflicts, scopes/assignment, fresh grants, private immutable links, rollback, safe references, dated renewal history, reminder revocation, actual missing metadata, filtered/formula-safe CSV, typed instruction retention and module disablement.

`tests/e2e/knowledge.spec.ts` uses only reserved `knowledge-{desktop,mobile}@alpha.example.test` accounts and the guarded `scripts/knowledge-browser-fixtures.php`. Dedicated local port: **8101**. Before and after each test, cleanup verifies exact ownership/names, actors, document origins and relation boundaries, writes a private recovery snapshot, then removes only those fictional fixture rows. It never resets the database or touches review accounts. The workflow verifies actual create/edit/reload, authorized document redirect, retained renewal dates, safe system reference, filtered download, and instruction navigation through an existing Executive routine on desktop/mobile.

The migration `2026_10_25_001000_create_knowledge.php` is additive and controlled by installation/update commands, never a web request. Disablement preserves all rows, documents, history and relations. Destructive rollback is unsupported; restore a tested backup.
