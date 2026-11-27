# Interactive protected Monday import

This Vault-owned adapter imports only a frozen deployment-approved source selection, using the current administrator's real HTTP Request and existing Vault authentication. It never creates a session, grants permissions, extends the fixed 300-second unlock, runs a source credential queue, copies a local token to a server, or fetches source values during installation/deployment. Ordinary Core/Marketing/Finance writers cannot route credentials through this adapter.

## Configure privately before use

Set deployment-controlled `VAULT_MONDAY_PLAN_FILE`, `VAULT_MONDAY_PLAN_SHA256` and `VAULT_MONDAY_TOKEN_FILE`. Files live outside the project/public tree, in canonical private directories with `0700` permissions; existing files must be `0600` and nonsymlinked. The plan SHA is the exact file-byte SHA256. Preserve this approved plan for retained source exports and replay verification. No real plan, source token, original record values, document bytes or recovery copy belongs in Git.

In the protected website console, run:

```sh
php artisan vault:configure-monday-import --confirm-installation=DEPLOYMENT_INSTALLATION_ID
```

The operator must enter and confirm the source token privately using hidden terminal input. There is no token argument or visible-input fallback. Configuration requires `APP_DEBUG=false`, installation confirmation and the dedicated Vault cipher. It writes only an atomic encrypted token configuration bound to the installation; it makes no source call and grants no Vault access. The local development token is not a source of production configuration. Retain the token's key version when rotating the keyring, or privately reconfigure it under the new active key before retiring the old version. Existing site backups retain their previously agreed custody arrangement.

After the reviewed migration is finished, unset the token-file configuration to hide import controls and prevent further source access. Keep the approved plan/hash available for retained authenticated source exports. Removing a source connection is an explicit operator action; no token file or backup is automatically deleted.

`ReadOnlyClient` remains unchanged. Each source call temporarily uses this decrypted request-scoped token and the pinned `2026-10` version, then restores prior configuration in `finally`. Tokens, source values, filenames, capability URLs and backend exceptions never enter import HTTP responses or metadata audit records.

## Frozen plan version 1

The plan contains only IDs, definitions and approved runtime context:

```json
{
  "version": 1,
  "installation_id": "deployment-id",
  "boundary": "64-lowercase-hex-runtime-boundary",
  "run_id": "canonical-lowercase-uuid",
  "account_id": "opaque-source-account-id",
  "api_version": "2026-10",
  "code_revision": "40-lowercase-hex-reviewed-code-revision",
  "mapping_revision": "64-lowercase-hex-mapping-revision",
  "company_mapping_id": 1,
  "boards": [{"id": "board-id", "columns": [{"id": "name", "type": "name"}, {"id": "files", "type": "file"}]}],
  "nodes": [{
    "id": "item-id", "board_id": "board-id", "parent_id": null,
    "update_ids": [], "assets": ["asset-id"],
    "asset_occurrences": [{"id": "asset-id", "column_id": "files", "index": 0}],
    "workdocs": [{"object_id": "object-id", "doc_id": "document-id", "file_id": "file-id", "column_id": "files"}]
  }]
}
```

The examples above are structural placeholders, not executable identifiers. Runtime boundary and the internal company mapping must already be verified through the shared Ledger. The company mapping must belong to the same account/provider/boundary and target an actual accessible Core company. Every imported item is explicitly Level 1 under this fixed company; callers choose neither source IDs nor target customer/tier.

Board/node/update IDs and definitions must be unique and exact. Parent IDs resolve to included top-level nodes. All board columns, including name and calculated/visibility capabilities, are included; the item name represents the name-column value. Updates belong to exactly their frozen node, with no unplanned replies/assets. Asset IDs may recur across nodes or columns; each attachment occurrence retains its exact column and zero-based source index. `asset_occurrences` may be omitted for SQL-item-only preparation, but binary processing is unavailable until it covers every included asset ID. Workdocs retain all three distinct identifiers and remain separate from uploaded assets.

Before activation, verify the deployed reviewed code revision, exact plan SHA, installation/runtime/account, internal company mapping and intended administrator permissions. The adapter contains no client-specific IDs. Actual pinned GraphQL schema metadata must support every field/argument; mocks alone do not establish API compatibility.

## Bounded item processing

The Vault page shows approved import controls only when deployment plan configuration and administrator/import permissions are present. The existing Vault unlock is required. Each actual server Request is bound to its initial real grant ID/fingerprint, actor/session and deadline; it cannot adopt a later unlock issued in another tab. Start with **Import one record** for representative verification; the next-five controls allow at most five serial requests per explicit click. Each request imports one server-selected source node. A source call will not start with 25 seconds or less remaining. Activity does not extend the grant, and any error/window change stops the batch.

`POST /vault/api/imports/monday/next` accepts only a UUID `request_key`. It commits an opaque pending entry before source access, so an interrupted response can reuse that same key. A different request cannot silently take over pending work. The source reader validates pinned account/version, exact item/board/parent, all column IDs/types and complete expected update IDs. Raw column JSON remains its original string, preserving large numeric identifiers, nulls, empty strings and whitespace. Calculated display values, original titles/group/board definitions, subitem lineage and original update HTML/authors/timestamps remain encrypted.

Native convenience fields never replace the complete source export. Complete canonical source JSON is split without truncation into bounded hidden multiline fields, respecting native 60-field/100k-value/20k-notes/512KiB payload rules. Optional projections are removed when necessary to fit the complete export; if the complete export still does not fit, that node stays pending. Known prohibited card-security labels stop the node; a generic archival field cannot bypass that policy. Free-form content cannot be exhaustively classified.

The actual authenticated Request is cloned only to supply server-derived native `Records::create` input, retaining its real session/user/grant. Item creation, actual decrypted readback, computed keyed fingerprints, verified Ledger receipt and immutable import entry commit atomically. Late expiry/authorization/context changes roll back acceptance. Accepted replay requires the owned import entry and current decrypted native content; changed/user-edited targets conflict instead of being overwritten. Source names never become plaintext item metadata titles.

`POST /vault/api/imports/monday/items/{uuid}/source` returns a truthful `original-source-export.txt`, reconstructed from encrypted native fields only after current authorization, actual readback and a final stream-time grant check. There is no raw source body in Inertia props or background caches.

## Bounded binary processing and recovery

`POST /vault/api/imports/monday/next-file` accepts only a UUID `request_key`. Each request handles one frozen uploaded-file occurrence whose own item already has a committed verified receipt; unavailable item entries are skipped without changing the frozen scope. This permits representative node-plus-file validation before the full item import. With no accepted file candidate it returns `awaiting-items`, never a false `files-accepted` result. It revalidates the actual source item/column/index/asset and unchanged snapshotted raw file-column value before obtaining a fresh source URL. Nullable FileAssetValue creator attribution is retained; nonnullable Asset.uploaded_by is not queried. Original filename, column filename, source IDs and exact available timestamps/author are encrypted with the native attachment metadata. The capability URL is ephemeral and never retained.

The shared `AssetDownloader` accepts only a server-derived typed location, exact deployment-approved HTTPS hosts, public DNS answers pinned to the TLS connection, exact declared size and bounded native-cURL RAM. It forwards no API token/cookies/referrer, follows no redirects and has no retries. Its 20-second budget includes elapsed DNS work; an expired DNS budget starts no file request. The Vault wrapper requires more than 25 seconds before transfer, checks the real grant and parent permissions before/after source access, and enforces the existing actual MIME/extension policy and 10MiB cap. Unsupported/oversized files remain pending rather than being renamed, truncated or substituted.

`vault_import_file_transfers` is the sole durable file transfer/provenance ownership journal. After bounded RAM download, the Ledger claim and expected keyed byte/provenance evidence really commit before any filesystem write. The reserved native Files hook chooses no caller path and writes only ciphertext through exclusive creation, private permissions and fsync. Attachment/version updates, actual decrypted readback, verified receipt and journal activation commit atomically. Filesystem transactions are never automatically replayed; failures and ambiguous COMMITs never blindly delete their paths. No source plaintext temporary file is created.

Same-request accepted replay validates native ciphertext, exact decrypted bytes/metadata and current authority, without another source request. Reserved attempts are not resumed or adopted automatically. The storage root is independently bound by canonical path/device/inode; symlinked/public/changed roots fail closed. Native key rotation may change an accepted attachment reference; replay validates that strict current native path and the unchanged authenticated content while preserving the journal's immutable original ownership path.

`POST /vault/api/imports/monday/files/{transferUuid}/reconcile` defaults to inspection. Explicit `{"cleanup":true}` requires the original operator, fresh current grant/source/profile authority and an uncommitted reserved path with no Attachment reference. It removes only its owned ciphertext and keeps the Ledger quarantine. Cleanup never deletes accepted attachments, adopts paths or enables expired takeover. Pending work, conflicting versions or changed context require review; no silent recovery policy is inferred.

## Completion evidence

Status/controls expose counts, retained opaque request IDs and pending file/workdoc counts, never source values or filenames. Counts describe retained accepted receipts, not continuing current existence or a complete migration. `migration_complete` remains false. Binary uploads and workdocs are distinct: no workdoc content/export or PDF conversion is implemented by this adapter. Their three-ID relationships remain preserved and explicitly pending until a separately reviewed protected exporter supports the actual block types.

Tests use only fictional keys/data, fake source HTTP/downloads and a guarded actual `base_test` database. Durable tests deliberately run outside `RefreshDatabase` to exercise committed ownership, SIGKILL, uncertain COMMIT, late expiry/revocation, changed targets/source relationships, native key rotation and zero-byte late exports. No production token or source value is needed for these checks.
