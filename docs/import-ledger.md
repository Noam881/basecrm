# Shared import metadata ledger

`Awove\Core\Imports\Ledger` is a shared Core foundation for guarded metadata reservations and retained operator-attested receipts. It is not an importer and does not call business writers. A `verified` receipt does not certify that a destination exists, that customer permissions were checked, that source coverage is complete, or that a migration succeeded.

The user's agreed migration scope and mapping remain authoritative. Provider extraction belongs to its adapter; ordinary client writes remain Core, financial writes Finance, acquisition/qualification writes Marketing, and credentials Vault. This ledger does not enable modules, add grants, suppress automations, store source provenance, provide encrypted staging, or establish recovery/backup guarantees.

## API and metadata limits

Every service call requires a fresh active user with a verified email and the existing `imports.manage` permission. Business writers must independently enforce their owning module and customer authorization; a reservation is never authorization or a Vault unlock.

| Method | Contract |
| --- | --- |
| `boundary(actor)` | Returns a keyed fingerprint of installation ID, environment, configured origin/connection, and actual PostgreSQL database/role/schema. Actual database/role must match the configured connection. It is independent of release paths; configuration or application encryption-key changes require a reviewed boundary transition. Connection details and credentials are not returned. |
| `start(actor, boundary, metadata)` | Metadata keys: `batch_key` UUID, `provider`, `source_account`, `mapping_revision`, `code_revision`, `plan_revision`. Replaying the same batch UUID with identical context retains the original batch/operator. Changed context is refused. |
| `claim(actor, boundary, batchId, source, metadata)` | Source keys: `board_id`, `entity_type`, `entity_id`, `target_role`, `relation_key`. Claim keys: `request_key` UUID, `revision`, `source_fingerprint`, `target_fingerprint` (nullable), `previous_receipt_id` (nullable). Returns a reservation, retained receipt, conflict, or `busy` while another reservation is inflight. |
| `finish(actor, boundary, operationId, requestKey, outcome)` | A `verified` outcome has `state`, qualified `target_type` such as `core.contact`, opaque `target_id`, and `result_fingerprint`. A `failed`/`conflict` outcome has only `state` and an allowlisted `error_code`. No destination is read or changed by this method. |
| `pause(actor, boundary, batchId, paused = true)` | Pauses/resumes metadata reservations and acceptance of inflight outcomes. It does not pause schedulers, notifications, or any business module. |
| `progress(actor, boundary, batchId)` | Returns derived receipt counts, replay/overdue-reservation counts, and touched mappings. `is_migration_completion_proof` is always false. There is no seal/completion API. |

Opaque account/board/entity/relationship/target IDs are 1–255 ASCII letters, digits, `_` or `-`, preserving leading zeros and case. Installation IDs are limited to 64 characters, provider tokens to 32, and entity/role/qualified-target-type tokens to 64. URLs, emails, paths, control characters, overlong values and unknown keys are rejected rather than rewritten or truncated. Mapping/plan/source/target fingerprints are lowercase 64-character hex digests; code revisions are 40 or 64 lowercase hex characters. Source/target fingerprints must be keyed digests supplied by reviewed adapters. Revisions are integers 1–1,000,000.

No names, raw customer payloads, before/after snapshots, URLs, document bytes, credentials or Vault secrets belong here. Ordinary legacy provenance requires a separate protected capability. Vault secret staging must never use the Core ledger.

## Identity, retry and retention rules

The mapping key includes boundary, provider, account, board, entity kind/ID, target role and explicit relationship occurrence. One source record can map to a contact, company and lead through distinct roles. A shared asset can have several explicit attachment occurrences. Composite occurrence identifiers should be keyed digests; filenames and similar names never establish identity.

Batch context, source identity and an accepted target identity are immutable. Terminal operation receipts are retained. Initial claims start at revision 1; advancing a verified mapping requires the next revision, the current verified receipt ID and an unchanged observed target fingerprint. Source/target/context disagreements produce conflicts rather than overwrites. Matching cross-batch fingerprints can produce a retained replay receipt without another destination write. Replaying an old request returns its historical receipt, not a statement that it is the latest target revision.

A reservation lasts 300 seconds and does not extend on retry. This duration is unrelated to authentication or Vault unlocks. One inflight reservation per mapping is enforced under row locks and a database unique index. An expired reservation becomes `reconciliation_required`; expired, failed or conflicted work blocks new reservations pending a separately reviewed reconciliation mechanism. There is no automatic takeover, lease renewal or assumption that a crashed destination write did not happen.

Actual `finish` submissions retain a bounded, keyed fingerprint of their validated metadata intent. Identical retries return the same retained outcome, including internally generated conflicts or expiry outcomes; changed submissions are refused. The digest contains no raw source payload and is hidden from normal model serialization.

If `claim()` expired/refused an operation before any `finish` submission, its terminal conflict/reconciliation receipt has no finish-intent digest. A later `finish()` only reads back that status: it does not accept the proposed outcome, add an intent, bind a target or turn the operation into verified work. A paused batch also cannot accept an inflight outcome; resuming it does not reset the reservation deadline.

The ledger's additive migration creates only `crm_import_batches`, `crm_import_mappings` and `crm_import_operations`; it does not modify lead fields. Destructive automatic rollback is refused. The separate [ordinary document importer](import-documents.md) provides a committed transfer/provenance journal and atomic Core writer/receipt integration for registered ordinary sources; it does not authorize Vault files or perform source fetching. Future production imports still require reviewed mappings and source adapters, protected provenance/staging, persistent automation suppression, recovery preparation, representative validation and final reconciliation.
