# Private Finance history files

Finance owns these attachments. They are files on existing `finance_history_records`/entries, not Core Documents, charges, payments, new customer profiles or Vault records. The existing optional `finance:history` capability and permissions remain authoritative. No real source provider, source download, user grant, production import or capability activation is included.

## One row owns reservation and attachment

Migration `2026_11_27_001850` adds only `finance_history_attachments`. A row is initially the durable filesystem ownership journal and becomes the actual attachment when committed. It retains its actual import operation, verified record/entry mapping IDs, runtime/storage boundaries, server-selected UUID path, native inspected filename/MIME/size/SHA and exact bounded source metadata. Storage paths, hashes, mapping IDs and raw attribution are hidden from model serialization; read routes construct an explicit permitted DTO. Rows cannot be deleted, final evidence cannot be updated, and destructive migration rollback is unsupported.

`History\Files\Importer::authorizeSource(actor,boundary,batchId,source)` must run before the owning adapter fetches any bytes, outside an SQL transaction. `import(actor,boundary,batchId,requestKey,source,UploadedFile)` also refuses an outer transaction. There is no HTTP import/upload/recovery route.

The exact source envelope is:

```text
role = finance-history-files
board_id, asset_id, item_mapping_id, item_id, entry_mapping_id
kind = file-column | item-assets-all | update | reply
column_id, source_index, update_id, reply_id
author = {source_id, name}
source_created_at, source_updated_at
```

Only file-column has a non-null column ID. File-column/item-assets-all have a nonnegative source index and a verified facts entry for that same source item. Update/reply occurrences have a null index and their actual verified Financial update/reply entry. A reply must prove the stored same-record parent update and exact update/reply source IDs. Independent board/item IDs must equal the actual verified mappings. A free Finance record ID, caller path, URL, checksum, target model, successful state or access/recovery flag is rejected.

Both actual retained history record and entry fingerprints, current Finance scope and every associated Core identity are checked. The source mapping must already identify `finance.history-record` with role `finance-history-record`; entry mappings must identify `finance.history-entry`. Core/Vault mappings cannot stand in for Financial history. A server-owned `SourcePolicy` must be registered with the singleton `History\Files\Sources` for the exact provider. It pins approved Financial board/items/asset membership, file-field ownership and source lineage. There is no default provider, broad fallback, manifest registration or source-user creation.

Source author strings and original timestamps remain exact. A strict `YYYY-MM-DD` value remains date-only with derived `date` precision in the safe DTO; it is not assigned midnight or a timezone. Exact RFC3339 offset/fraction strings remain timestamp strings. Actual import time belongs to the importing operator's receipt and committed row. Preserve the same original entry mapping for a replay of an unchanged asset occurrence; do not invent a fresh occurrence because another unrelated facts field changed.

## Durable two-phase write

1. Native Core `Documents::inspectFile` validates the actual local upload bytes using the existing format/MIME/filename and100MiB limit. It creates no Core Document or shared upload. Source authorization precedes inspection/fetching.
2. The first SQL transaction reserves the real Ledger operation and commits the Finance journal row with its generated owned path. No filesystem write runs before that durable reservation.
3. A second SQL transaction reloads current actor/import/Finance authority, actual history source proofs, open batch and Runtime, then exclusively creates that path. The Finance storage helper follows the existing Core journal's private-local-root, no-public-root, no-symlink and0700/0600 rules. It reuses no arbitrary caller path and does not widen Core's document/storage APIs.
4. Bounded byte count and SHA readback must match the native inspected upload. Final committed state and actual verified Ledger finish commit atomically. Source context, current Finance/all-linked-Core access, policy, runtime, byte readback and deadline are rechecked after receipt callbacks.

There are no automatic transaction retries around filesystem writes and no deletion in an error handler. A crash, rollback or uncertain COMMIT leaves a durable row owning its bytes. A COMMIT acknowledgement failure may mean the attachment is already committed; inspect before retrying. Repeated/pre-existing claimed requests are status-only, never takeover/resume. Expired reservations are not renewed. A verified replay must find its own retained journal, actual current Financial parent/access and exact stored bytes; it does not adopt a bare receipt, repair bytes or create a second file per linked client.

`reconcile(actor,boundary,transferId)` is inspection only. Explicit `cleanup:true` may retire only the importing actor's still-reserved, unreferenced attempt, with current import/Finance/source authority and open batch. Committed Finance rows, verified outcomes or another document reference prevent cleanup. A different administrator can inspect an accessible attempt but cannot take over its write or delete its bytes. Cleaned rows and failure receipts remain retained. Reconciliation is not an automatic recovery or ownership-transfer mechanism.

## Read-only product surface

Files appear on the existing Finance history detail page and each history record in the existing identity Finance panel. Shared Material controls/spacing and English/Hebrew labels are reused. The table/profile path does not copy Financial payloads into Core or multiply facts/files per client.

`GET /finance/history/{id}/files` and `GET /finance/history/{id}/files/{uuid}/download` require the same current Finance history view scope and all-linked-Core identity access as the record itself. An ordinary identity viewer without Finance access cannot list or download these files. Retained reads do not require import permission or an open historical batch, but actual history/entry/file receipt proof and current Runtime remain mandatory. Module disablement blocks forced requests. Downloads verify the actual opened bytes, use attachment/octet-stream/no-sniff and private/no-store headers, and recheck authority before returning the stream. No source URL is stored, returned or followed.

The current migration inventory contains seven known Financial assets. The user approved retaining the ambiguous ancillary quote and its PDF as unassigned Financial history with review flagged. This capability registers no source policy and invents no client association. Approved unassigned source records remain unassigned; one stored Financial fact is not duplicated for plural Lead/profile paths. The future reviewed adapter may use the existing application asset downloader only after exact authorization; no transport or network access occurs in this writer.

## Validation

`tests/ImportDurability/FinanceAttachmentTest.php` deliberately runs outside RefreshDatabase, guards the isolated `base_test` database and actually commits reservation transactions. It covers exact bytes/attribution, hidden DTOs, date-only precision, replay/cross-batch readback, no Core Documents/business duplication, default deny and outer-transaction refusal, rollback/orphan inspection, own-only explicit cleanup, uncertain COMMIT, expiry, late authority/runtime/batch/policy/byte changes, current Finance/all-associated-Core ACL and exact reply parent evidence. Fixtures are fictional; no source/provider/production access or customer files are used.
