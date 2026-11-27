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

## Later migration checkpoints

Inventory and mappings precede schema changes or imports. Reconcile existing destination records by reliable source identifiers; similar names are insufficient. Do not stage secrets in ordinary notes, CSV imports or unencrypted tables. Preserve Hebrew text, original credential bytes, file contents, legacy attribution and source relationships.

The current CSV importer is not a complete monday importer. It does not provide account/board/item mappings, resumable updates, archive coverage or historical attribution, and normal lead writes can trigger events. A migration path must explicitly suppress unintended customer automations and preserve subsequent CRM edits.

Generic client-profile Documents and legacy-author attribution require focused capability decisions before real imports; existing proposal/contract uploads are not a general document destination. Vault's current authorized writer requires an approved actor, linked client, explicit tier and a current session-bound unlock. Do not bypass that writer, fabricate grants or extend its five-minute deadline for batch imports. Resolve the operator/import execution model before credential extraction.

Real-data staging, test imports and full production writes require the approved protected runtime and reversible batches/backups. Never copy production encryption keys or protected credentials to a different machine to evade access controls. If later work runs on Enhance, configure `MONDAY_API_TOKEN` separately in its approved private backend environment; do not assume this local setup is deployed.

The delivery remains the full migration: preview, representative test, resumable import, final source-change reconciliation and a verified report accounting for every required record/file. A connection check or preview is only a checkpoint.
