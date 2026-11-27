# Durable ordinary document imports

`Awove\Core\Imports\DocumentImporter` is the Core writer for an explicitly approved ordinary client-document occurrence. It reuses the private `Documents` policy, real file validation and byte readback. This foundation does not fetch source files, expose an import endpoint, register a real source adapter, import Vault files, or certify migration completeness. Native profile uploads retain their existing API and behavior.

## Trusted source boundary

`DocumentSources` is a server-owned, default-deny registry. An owning adapter must register a `DocumentSourcePolicy` for its exact provider and source-role token during trusted service-provider setup. The policy receives the fresh actor, actual batch, verified identity/source-item mappings and validated `DocumentSource`; it must establish that this is an ordinary document source. No request option, caller-supplied boolean or generic Ledger receipt makes a Vault source eligible. No real provider is registered by this change.

An adapter must call `authorizeSource()` **before fetching bytes**, without an SQL transaction, then download only through its separately reviewed protected transport and pass the resulting `UploadedFile` to `import()`. There is no network work inside this coordinator's SQL transactions. Do not use ordinary temporary files for Vault secrets or route Vault writes through this API.

Every import requires a fresh active, email-verified actor with `imports.manage`, the current actual `Runtime` boundary, an open batch, verified mappings from the same provider/account/boundary, and the current profile update Gate. The source item must target that identity or a lead currently associated with it; a lead source also requires the lead update Gate. Update/reply attachments require the actual retained `LegacyHistory` mapping and its verified activity and parent relationships. Generic operator-attested history aliases are refused.

## API

```php
$importer->authorizeSource($actor, $boundary, $batchId, $identityMappingId, $source);
$result = $importer->import($actor, $boundary, $batchId, $identityMappingId,
    $requestUuid, $source, $uploadedFile);
$inspection = $importer->reconcile($actor, $boundary, $result['transfer_id']);
// Explicit, reviewed removal of this actor's uncommitted owned bytes only:
$cleanup = $importer->reconcile($actor, $boundary, $transferUuid, cleanup: true);
```

`$source` has exactly these keys; optional values are explicitly `null`:

| Keys | Meaning |
| --- | --- |
| `role` | Registered ordinary source-role token. |
| `entity_type`, `source_id` | `asset` or `workdoc`, and exact opaque source ID. |
| `source_parent_id`, `source_item_mapping_id` | Exact source item ID and its verified item mapping. |
| `kind` | `file-column`, `item-assets-all`, `update` or `reply`. |
| `column_id`, `source_index` | Exact file-column ID only for `file-column`; bounded occurrence index for non-history locations. |
| `update_id`, `reply_id`, `history_mapping_id` | Exact history relationship; updates/replies require the retained history mapping, replies also require the update ID. |
| `author` | Exactly `source_id` and `name`, both nullable; same bounded validation as retained legacy history. |
| `source_created_at`, `source_updated_at` | Nullable original ISO timestamps, preserved exactly without inventing an original time. |

No target document ID, path, checksum, fingerprint, successful outcome or arbitrary source body/URL is accepted. Fingerprints are computed from current mapped context and actual validated file bytes. Identity, parent, history and occurrence relationships are part of the evidence; equal filenames never identify documents.

The result contains the ordinary Ledger `receipt`, opaque `transfer_id`, nullable `document_id` and `new_transfer`. Only a verified receipt returns a document ID. The asset/workdoc upload API supports the initial immutable occurrence at revision 1; changed bytes, provenance, identity or context conflict instead of replacing accepted bytes. A reviewed future versioning capability is required for source revisions.

## Durable ownership and recovery

The additive `crm_import_document_transfers` table is the sole transfer/provenance journal. Its first transaction commits the Ledger reservation, expected byte digest/size, private source attribution and server-generated random path **before any destination bytes are written**. Calls inside an ambient transaction are refused; wrapping `import()` in another transaction would defeat durability.

The second transaction stores with exclusive creation, verifies the full bounded readback, creates the Document, verifies `Ledger.finish()` really returned `verified`, and activates the journal atomically. It rechecks actual bytes, resource authority, source mappings, open batch, runtime and the fixed reservation deadline before acceptance. Filesystem transactions have no automatic SQL retry. Ambiguous COMMIT acknowledgments never trigger blind deletion.

Only this journal's committed receipt and actual accepted bytes permit replay. Inflight retries return retained status and do not resume copying. A generic receipt cannot adopt an existing native Document. Concurrent callers serialize through the existing batch/mapping locks; they cannot create a second journal or overwrite its path.

Storage is the private local disk, separately bound to the canonical root, device and inode. Changing the root, remounting/replacing it, or changing the application encryption key requires reviewed reconciliation; a matching Runtime boundary alone is insufficient. Journal paths are generated below `documents/imports/<uuid>/`, files are `0600`, import directories are `0700`, public roots and symlinked owned path components are refused. Preserve the private storage root and journal together in backups. Root replacement on restore requires an explicit recovery decision; the importer does not silently rebind it.

`reconcile()` defaults to inspection, with no path/hash/provenance disclosure. A reserved attempt reports presence, byte equality, expiry and cleanup eligibility. Explicit cleanup requires current import/source/profile authority, the original operator, a journal-owned path with no Document reference and a nonverified operation. It removes only that owned uncommitted file and marks the journal cleaned. It never adopts files, removes committed documents, clears a Ledger conflict or permits automatic expired takeover. If cleanup or its COMMIT acknowledgment is interrupted, inspect again before deciding what to do. Accepted bytes are never rewritten.

## Profile provenance and validation

Existing private document profile responses add a bounded nullable `source` DTO: original author name, original timestamps and fixed source kind. A history context link is returned only after current lead view authorization and retained history integrity checks; ordinary identity viewers do not need import permission. For asset/workdoc uploads, internal source IDs, mapping IDs, hashes, raw metadata and storage paths remain hidden. Generated references deliberately expose their verified item/hierarchy IDs for traceability. Vue renders escaped text and constructs only the application's own lead/activity URL. Existing document download and profile Gates remain authoritative.

`tests/ImportDurability` deliberately runs outside `RefreshDatabase`: reservations must truly commit. It verifies both configured and actual `base_test` before rebuilding fixtures and never accesses production. Regressions cover competing processes, SIGKILL after transfer, failed/ambiguous COMMIT, late expiry/ACL/runtime/batch changes, corrupt readback, default-deny source ownership, actual retained history, replay and storage/symlink changes. Do not replace these cases with a surrounding rollback transaction.

## Generated ordinary source references

`DocumentImporter::importReference()` preserves original ordinary fields as a clearly generated TXT, not a source upload, proposal, asset or workdoc. The existing durable transfer journal and private document storage remain authoritative; no additional table is needed. `import()` refuses disguised generated-reference uploads.

The conditional source contract uses `entity_type=item`, `kind=generated-reference`, actual item IDs, `parent_item_id`, `reference_revision`, `reference_columns` and bounded `review_flags`. `source_parent_id` identifies the owning source item; `parent_item_id` is its genuine hierarchy parent. A server-verified `subitem-context` mapping may point a child item on its actual board to its existing parent Lead, without creating another opportunity or treating a priced source line as a Task. Four explicitly reviewed internal references may map to the approved existing internal Company. No name-based or missing-data fallback is provided.

A registered `ReferenceSourcePolicy` must authorize the source and exact column ownership before fetch and at each write check. No provider is registered by default. Marketing, Finance and Vault originals must never enter an ordinary Core reference. `OriginalFields` is only a bounded pure envelope: it preserves labels, types, availability and raw JSON **strings** without decoding, trimming, numeric casts or executing formulas. Observed null, JSON `null`, empty strings and unavailable fields remain distinct. The importer computes the content digest itself. Unchanged content reuses its revision; changed captures require a verified predecessor and new retained revision.

Generated metadata has a truthful source-reference badge and review flags. Listing, download and direct source metadata require current profile access **and** access to the source Lead; this prevents a shared identity from exposing another restricted opportunity's original fields. Original author/time remain distinct from the actual importer and generated document time. Temporary generated bytes use an owner-only temporary file and are removed after the existing journal consumes them. Existing asset/workdoc source contracts remain unchanged.
