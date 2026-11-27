# Guided monday migration

This migration targets the existing Base CRM installation at `basics.awove.dev`. Reuse the current lead/contact/company structure; source mappings, import batches and reports must not contain plaintext credentials. The shared migration adapter owns provider access; Core owns ordinary identities, Vault owns credentials, and Marketing owns acquisition/qualification data.

## Connection checkpoint

The initial connection check runs in the existing **local Laravel console**, using the native PHP runtime documented in the root README. Its private configuration is the `.env` file in that same working directory. It does not run in the browser or on Enhance and does not write to the CRM database. A token configured here is not automatically available on the production server.

Enter the API token privately as `MONDAY_API_TOKEN` in the local, Git-ignored `.env`; do not send it in chat, pass it as a command argument, or put it in a report. The example environment contains only an empty placeholder. Keep private environment files readable only by their owner. Start a fresh console process after saving; if that environment uses Laravel's configuration cache, rebuild its cache before checking.

```sh
php artisan crm:monday-check
```

The check uses only the fixed official HTTPS endpoint and a metadata query for the token's user ID, account ID/name/slug and effective API version. It never queries boards, items, passwords, documents or updates. A successful sanitized JSON result identifies the connected account; failures report a fixed category without raw API errors, response bodies or exception messages. HTTP success with GraphQL errors is a failed connection, even if partial data is present.

Pin `MONDAY_API_VERSION=2026-10`, the supported current version verified on 27 November 2026. The check requires the returned version to match. Official references: [versioning](https://developer.monday.com/api-reference/docs/api-versioning), [authentication](https://developer.monday.com/api-reference/docs/authentication), [me](https://developer.monday.com/api-reference/reference/me), and [account](https://developer.monday.com/api-reference/reference/account).

Only after authentication succeeds, collect the user's board URLs/IDs/names. Resolve ambiguous boards and related/archived dependencies before extracting their contents. Keep all source access read-only.

## Board definition inventory

`crm:monday-inventory --account=<expected-account-ID> --board=<selected-board-ID> --report=<unique-name>` first validates the pinned API's schema and account, then reads definitions for exactly the selected IDs. Repeat `--board` for multiple boards. `--schema-only` reads schema metadata alone; `--references-only` reads dependency names, states and reported counts without columns or records. `--structure-only` traverses active item IDs and subitem lineage without names or values. Modes are mutually exclusive.

Reports are new owner-only JSON files beneath `storage/app/private/monday-migration`, outside public storage. They contain sanitized metadata, never raw settings, item values, credentials, update bodies or file contents. Failed/missing boards remain unresolved; independent successes are retained. Settings parsing allowlists relation board IDs and status/dropdown labels, preserving label IDs and blank labels. Unknown settings remain explicitly unresolved or uninspected.

Treat board `items_count` as a reported metric, not a count of distinct destination clients or complete archive coverage. The pinned schema confirms `boards(state: all)` for selected archived boards, while `items_page` lists active items only. Under `2026-10`, known inactive IDs can use `items(exclude_nonactive: false)`; there is no root `items_page` or root item `state` argument. Record unknown archived IDs as unresolved and never unarchive source data to extract it. Board/column definitions are evidence as returned, not proof of inaccessible/hidden-field coverage.

Structure traversal checks the account/version on every page, bounds pagination and response sizes, and keeps missing boards, duplicate IDs, repeated cursors and partial failures unresolved. Successful active traversal does not certify archives, updates, files or historical activity. Validate parent existence and cycles before treating lineage as a client association. Supporting-board membership must follow an explicitly agreed scope rule and exact source relationships, never similar names.

For relationship-only discovery, use `--relations-plan=<private-plan-name>` instead of another mode. The owner-only plan lives in the same private report directory and contains the expected account plus requests with string `board_id`, `item_ids` and verified board-relation `column_ids`. The reader selects only typed `linked_item_ids` and linked item/board IDs; it never requests ordinary column text/value or credential fields. Missing, inaccessible or conflicting links remain unresolved. Keep complete errors in the private report; console output is capped to a small identifier-only sample. Retry recoverable failed IDs in smaller bounded batches, retaining independent successes. No names-only matching or automatic target writes occur during discovery.

`HistoryInventory::updates()` inventories only update/reply IDs and their asset relationships for explicit item IDs on one verified board. It requires a terminal empty update page, retains independent observations and marks null fields, repeated pages, duplicate ownership and page limits unresolved. `files()` reads only approved Files columns and distinguishes uploaded assets, workdocs, external links and invalid assets, preserving opaque file IDs. Neither method reads bodies, filenames, URLs or file bytes. Empty selections never fall back to account-wide reads. Board-level updates, default item-asset scope and document contents remain explicitly uninspected; this metadata projection alone is not complete historical coverage.

## Later migration checkpoints

Inventory and mappings precede schema changes or imports. Reconcile existing destination records by reliable source identifiers; similar names are insufficient. Do not stage secrets in ordinary notes, CSV imports or unencrypted tables. Preserve Hebrew text, original credential bytes, file contents, legacy attribution and source relationships.

The current CSV importer is not a complete monday importer. It does not provide account/board/item mappings, resumable updates, archive coverage or historical attribution, and normal lead writes can trigger events. A migration path must explicitly suppress unintended customer automations and preserve subsequent CRM edits.

Core's pure `Imports\FieldConflict` helper compares established field snapshots in the same reviewed destination representation. Unchanged source data preserves CRM edits; a source change can apply only against an unchanged previously applied target, while an already matching target needs no rewrite. Conflicts and unavailable data remain unresolved, and missing fields are distinct from explicit null. Initial matching still needs an explicit policy. The helper never authorizes, writes or returns raw values; the coordinator must not advance the last-applied target baseline after preserving a human edit.

Shared Core [profile Documents](profile-documents.md) provides private ordinary attachments on existing contact/company profiles; existing proposal/contract uploads keep their separate meanings. Historical source attribution, resumable mappings and conflict handling still require the reviewed import ledger. A normal document upload alone does not preserve source provenance or complete a migration. Vault's current authorized writer requires an approved actor, linked client, explicit tier and a current session-bound unlock. Do not bypass that writer, fabricate grants or extend its five-minute deadline for batch imports. Resolve the operator/import execution model before credential extraction.

Real-data staging, test imports and full production writes require the approved protected runtime and reversible batches/backups. Never copy production encryption keys or protected credentials to a different machine to evade access controls. If later work runs on Enhance, configure `MONDAY_API_TOKEN` separately in its approved private backend environment; do not assume this local setup is deployed.

The delivery remains the full migration: preview, representative test, resumable import, final source-change reconciliation and a verified report accounting for every required record/file. A connection check or preview is only a checkpoint.
