# Finance source history

`finance:history` is an optional, read-only Finance capability for retained financial references that cannot truthfully satisfy native collections, expenses or payroll requirements. Native financial services remain the primary destination whenever the source can satisfy their contracts. History creates no charges, invoices, payments, deadlines, balances, payroll amounts, reminders or outbound communications. Core customer profiles show only a Finance-owned panel; financial payloads never enter Core Activity or Documents.

## Storage and permissions

The additive migration creates three Finance tables: `finance_history_records` (immutable kind, initial owner and placement), `finance_history_identities` (plural explicit source mapping/Contact-or-Company associations), and `finance_history_entries` (immutable facts, updates, replies and revisions). A financial fact exists once even when multiple customers reference it. Each association has exactly one native identity FK. No implicit company or guessed customer is created.

Kinds are `collection`, `income`, `bonus`, `monthly-hours`, `ancillary-quote`, and `reference`. `linked` requires at least one verified source-item→Core identity mapping; the independently supplied source board/item IDs must match it. `internal` and `unresolved` require no associations. The initial placement, owner and associations are immutable; changing them needs a separately reviewed access/reconciliation procedure.

The capability defaults off and grants nobody access. Readers need a fresh active verified account plus `finance.history.view.own` (initial owner only) or `finance.history.view.all`. Linked records additionally require current Core access to **every** associated identity, including on profile-panel queries. Unresolved placement is visible only to view-all readers. Internal records use Finance ownership scope. The importer additionally requires fresh `imports.manage` and `finance.history.import`. There is no write HTTP endpoint or ordinary UI editor.

## SQL importer contract

`Awove\Finance\History\Import::create(actor,boundary,batchId,input,payload)` creates a source record, explicit associations, its initial facts entry and actual shared Ledger receipts in one SQL transaction. Input is exactly:

- `board_id`, `source_id`, supported `kind`, nullable `owner_id`, `placement`;
- `associations`: up to 100 `{mapping_id,source_board_id,source_item_id}` records;
- distinct UUID `request_key` (record shell) and `entry_request_key` (initial facts).

`append(actor,boundary,batchId,recordMappingId,input,payload)` adds immutable history to the actual retained `finance.history-record` target, not an arbitrary supplied Finance ID. Its exact input is `entry_kind` (`facts|update|reply`), `source_id`, independent `source_parent_id`, integer `revision`, UUID `request_key`, nullable `previous_receipt_id`, and nullable `reply_to_mapping_id`. Facts use the parent item's source ID. Replies reference a verified update on the same financial record; their normalized original update parent cannot change between revisions. Revisions start at one, are contiguous, and require the previous verified occurrence receipt under the same mapping/code/plan revisions.

Every occurrence receives its own stable source family/revision relation key, while its Ledger revision remains one. Source edits append a new entry instead of remapping an accepted target or overwriting user data. Same-request and cross-batch replay compare the actual retained payload and target fingerprints, computed internally using keyed HMACs. Caller-supplied digests are not accepted. Current runtime/database boundary, open batch, fresh actor permissions and all associated identity access are checked around writes. Family advisory locks and locked mappings serialize retries. Normal security and integrity events remain enabled.

The business savepoint and actual receipt commit together. A newly observed expired reservation rolls back the new SQL rows before retaining reconciliation status. A pre-existing claimed request is status-only: no blind takeover after a crash. Unresolved operations require operator reconciliation; this capability does not implement recovery, reversal or importer completion proof. No source/network/file I/O occurs inside these transactions.

## Exact source content and safe readers

Payload is exactly `label`, `facts`, `body_html`, `author`, `source_created_at`, `source_updated_at`. Author is the original `{source_id,name}`, independently nullable; source timestamps preserve exact RFC3339 strings, fractional precision and offsets. Unknown author/time remain unknown. The actual importing actor/time come separately from the verified Ledger receipt; no user accounts or invented original dates are created.

Each fact is exactly `{key,label,value_kind,availability,raw_json,unit,currency}`. `raw_json` is an untouched JSON **string**, validated without parsing numbers through PHP floats. This preserves large integers, decimal precision, trailing zeroes, nested numeric JSON and source types. `availability` distinguishes `present` (including JSON null), `missing`, and `unavailable`. `value_kind` is one of `money`, `decimal`, `hours`, `count`, `percent`, `text`, `date`, `timestamp`, `status`, `link`, `reference`, `formula-output`, `json`. Optional uppercase currency is permitted only on money; an absent currency remains unknown. Nothing evaluates formulas, performs currency conversion or changes the installation's native currency.

Limits: label 500 characters; 100 facts; fact label 255 characters; unit 40; each raw value and combined raw JSON at most 1 MiB; JSON depth 32. Body HTML/text independently use Core LegacyPayload's explicit 1 MiB and parser limits. Unsupported or oversized content fails validation for explicit migration resolution; no stored content is silently truncated. The adapter must select reviewed ordinary financial fields: this is not a raw board dump or a Vault secret staging area.

Original HTML is retained only server-side. Finance readers serialize escaped plaintext and safe derived JSON display strings; they never serialize HTML, raw snapshots, storage paths or private Ledger fingerprints. Top-level JSON strings alone are decoded for display; numeric/object/array lexemes remain exact strings. Fact previews and body text share an 8 KiB per-entry content budget, ten entries per cursor page; a currently authorized single-entry reader exposes complete retained content. Original source times remain available in `<time datetime/title>` independently from localized display. Initial source history should be appended oldest-first so the CRM's descending insertion timeline is meaningful. Original source media presence is informational and does not attest transferred file bytes.

## Boundaries and rollout

This first capability includes SQL records/readers only. Finance file transfers, their private storage and durable rollback/crash journal require a separate Finance-owned implementation. Unsupported Files UI is hidden. No source file is claimed migrated by these SQL receipts.

The additive `up()` leaves native Core/Finance rows untouched. `down()` refuses destructive rollback. Plan a tested target backup and explicit mapping review before actual migration. Deployment must create permissions explicitly and opt into `finance:history`; enabling a module does not grant permissions. Synthetic tests use only guarded `base_test`; browser QA uses reserved Alpha project accounts and exact `finance-history-{desktop|mobile}-{13digits}` namespaces, with Finance cleanup before ordinary fixture cleanup. Canonical/reviewer/Beta data are excluded.
