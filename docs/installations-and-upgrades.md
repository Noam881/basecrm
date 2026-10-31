# Installation, releases and recovery

## New installation

Build and tag an immutable host/core release plus a locked extension set. Create a private manifest and brand assets; do not put real client configuration in the shared repository. Provision an independent PostgreSQL database and least-privilege role, app key, cookie/session name, database queue/cache boundaries and private storage root. Supply secrets externally, disable debug, enforce TLS/secure cookies and keep databases off public interfaces. Use `docs/production.env.example` as a non-secret shape, not valid credentials.

Validate required modules before activation. Run migrations using a deployment credential that can change schema; a production runtime role can have narrower grants. Run `crm:install` to add missing defaults without overwriting existing statuses/settings or intentionally changed role permissions. Configure and verify the installation email transport and queue first; `production.env.example` deliberately uses a non-delivering log mailer. Then run `crm:bootstrap-admin EMAIL` and open the expiring email sign-in link. There is no hidden operator/superuser account.

Only a new, explicitly local example database can receive `seed-local.php` fixtures. Mailpit and review credentials must never enter production images. The Docker build excludes private `.env*` files (retaining the non-secret `.env.example`), local credentials, database/storage files and generated bootstrap cache files; approved manifests/brand assets are mounted externally for real clients. The image mirrors Composer path packages and includes compiled frontend assets.

## Controlled update

1. Build/test a pinned host/core version and approved module versions. Never deploy a moving `main` or `latest` application reference.
2. Capture a consistent database backup, private files, deployment manifest, env secret backup and current image identifier. Encrypt and protect backups.
3. Rehearse on staging or a pilot with the same extension set. Run compatibility validation and review pending migration changes; a disabled module still has installed code/migrations and retained data.
4. Pause writes/workers as required for consistency, run reviewed additive migrations, install missing defaults and restart workers on the pinned image.
5. Verify `/up`, email sign-in, permissions, scoped counts, reminder processing, branding and extension data. Inspect `php artisan crm:diagnose`, `migrate:status` and `queue:failed` before controlled rollout to further installations.
6. Keep a per-installation release/backup ledger. Updating one example never runs migrations on the other's database.

Image rollback and database recovery are different operations. An old image may be compatible with an additive schema; verify that explicitly. Do not blindly run `migrate:rollback`; this foundation's migrations refuse destructive down paths. For schema/data damage, restore a tested compatible database and private-file backup under an approved recovery window. Do not automatically update all clients.

## First-build rehearsal

`python3 scripts/rehearse-upgrade.py` creates newly named `base_rehearsal_*` scratch databases using local bootstrap credentials. It installs an explicitly labeled first-build pre-update schema, seeds fictional records/settings/status customizations/permissions/branding and extension data, discovers and applies all installed package additive migrations through the current checkpoint (including shared fields, work/identities, HR/Finance, Knowledge, Marketing extraction, Assets and selected Nonprofit capabilities), runs idempotent default initialization, and compares fingerprints. Missing additive fields are normalized to their exact migration defaults, so retained values and unexpected non-default writes remain detectable. The result names every additive migration and verifies each was applied. It then uses PostgreSQL 18 `pg_dump`/`pg_restore` to a second scratch database, verifies data and a synthetic private-file archive, and drops only scratch databases created in that invocation. A `finally` block drops only the exact scratch databases successfully created by that invocation, including after failures; cleanup failures report the remaining names. Older scratch databases are untouched. Do not apply this script to customer systems.

The retained fingerprint covers seeded Core records, customized settings/statuses/permissions/branding and the legacy service-details extension. The fixture does not seed historical business rows for every newly added module; their retention/disablement is covered by module acceptance tests. The recorded result is proof of this first-build fixture rehearsal, not upgrade compatibility with historical releases that do not exist. Repeat against every real supported release when release history exists.

## Background processing and failures

Run one scheduler and one or more database queue workers per installation. `crm:remind` is safe to enqueue repeatedly; task-version keys and row locks prevent duplicate delivered reminders. Deactivation/ownership changes invalidate old work. Reading a reminder never completes the task. Run `php artisan queue:failed` to inspect failed jobs and `queue:retry ID` after correcting the cause. Do not erase failed jobs to conceal failures. Stop workers cleanly/restart them after a release so old code does not continue executing.

## Account access recovery

Sign-in uses email links only. Request a fresh link from `/login` if the previous link expired or was used. The installation owner controls the email account’s security and recovery. Administrators can invite/reactivate employees and reassign work; deactivated historical users remain preserved. No public registration, password reset, authenticator enrollment or hidden support account exists. If the owner’s email is unavailable, establish installation ownership through the operator’s documented identity process before any out-of-band account email change, revoke affected sessions, and record the incident. See [email authentication](authentication.md).
