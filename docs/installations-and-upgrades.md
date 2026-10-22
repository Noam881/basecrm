# Installation, releases and recovery

## New installation

Build and tag an immutable host/core release plus a locked extension set. Create a private manifest and brand assets; do not put real client configuration in the shared repository. Provision an independent PostgreSQL database and least-privilege role, app key, cookie/session name, database queue/cache boundaries and private storage root. Supply secrets externally, disable debug, enforce TLS/secure cookies and keep databases off public interfaces. Use `docs/production.env.example` as a non-secret shape, not valid credentials.

Validate required modules before activation. Run migrations using a deployment credential that can change schema; a production runtime role can have narrower grants. Run `crm:install` to add missing defaults without overwriting existing statuses/settings or intentionally changed role permissions. Run `crm:bootstrap-admin EMAIL`, complete the expiring onboarding link and enroll/confirm MFA. There is no hidden operator/superuser account.

Only a new, explicitly local example database can receive `seed-local.php` fixtures. Mailpit and review credentials must never enter production images. The Docker build excludes `.env*`, local credentials, database/storage files and generated bootstrap cache files; approved manifests/brand assets are mounted externally for real clients. The image mirrors Composer path packages and includes compiled frontend assets.

## Controlled update

1. Build/test a pinned host/core version and approved module versions. Never deploy a moving `main` or `latest` application reference.
2. Capture a consistent database backup, private files, deployment manifest, env secret backup and current image identifier. Encrypt and protect backups.
3. Rehearse on staging or a pilot with the same extension set. Run compatibility validation and review pending migration changes; a disabled module still has installed code/migrations and retained data.
4. Pause writes/workers as required for consistency, run reviewed additive migrations, install missing defaults and restart workers on the pinned image.
5. Verify `/up`, sign-in/MFA, permissions, scoped counts, reminder processing, branding and extension data. Inspect `php artisan crm:diagnose`, `migrate:status` and `queue:failed` before controlled rollout to further installations.
6. Keep a per-installation release/backup ledger. Updating one example never runs migrations on the other's database.

Image rollback and database recovery are different operations. An old image may be compatible with an additive schema; verify that explicitly. Do not blindly run `migrate:rollback`; this foundation's migrations refuse destructive down paths. For schema/data damage, restore a tested compatible database and private-file backup under an approved recovery window. Do not automatically update all clients.

## First-build rehearsal

`python3 scripts/rehearse-upgrade.py` creates newly named `base_rehearsal_*` scratch databases using local bootstrap credentials. It installs an explicitly labeled pre-update schema that omits the actual `000200` additive migration, seeds fictional records/settings/status customizations/permissions/branding and extension data, applies the real nullable external-reference change, runs idempotent default initialization, and compares fingerprints. It then uses PostgreSQL 18 `pg_dump`/`pg_restore` to a second scratch database, verifies data and a synthetic private-file archive, and drops only scratch databases created in that invocation. Exceptions leave scratch databases for diagnosis; do not apply this script to customer systems.

The recorded result is proof of this first-build fixture rehearsal, not upgrade compatibility with historical releases that do not exist. Repeat against every real supported release when release history exists.

## Background processing and failures

Run one scheduler and one or more database queue workers per installation. `crm:remind` is safe to enqueue repeatedly; task-version keys and row locks prevent duplicate delivered reminders. Deactivation/ownership changes invalidate old work. Reading a reminder never completes the task. Run `php artisan queue:failed` to inspect failed jobs and `queue:retry ID` after correcting the cause. Do not erase failed jobs to conceal failures. Stop workers cleanly/restart them after a release so old code does not continue executing.

## MFA and account recovery

Users first use a private Fortify recovery code through the two-factor challenge, then replace the authenticator and regenerate/store recovery codes from Account security. Password recovery is an expiring 60-minute link and does not bypass MFA. Administrators can invite/reactivate employees and reassign work; deactivated historical users remain preserved.

If every administrator loses all authenticators and recovery codes, treat this as an out-of-band recovery incident: establish installation ownership through the operator's documented identity process; make an encrypted backup; have an authorized database operator revoke affected sessions/remember tokens and clear only that verified administrator's encrypted MFA enrollment fields; record the recovery in the deployment incident log. On next sign-in the privileged-use gate forces fresh enrollment before CRM access. This is a documented emergency database operation, not a built-in support login, HTTP bypass or hidden account. It has not been performed against a real account.

## Local full Compose

The `full` profile runs Alpha/Beta web, workers and schedulers on separate volumes. Alpha's web port is 8091; Beta's is 8092. Stop the native web servers before using these ports. PostgreSQL and Mailpit ports stay loopback-only. Run `docker-compose --profile full run --rm alpha php artisan migrate --force` and the corresponding Beta command when initializing an empty container installation, then `crm:install` and administrator bootstrap. Neither worker nor web startup runs migrations automatically.

The current image uses PHP's development HTTP server for repeatable local validation. Coolify/Hetzner preparation is configuration and packaging only; no remote provisioning/deployment or production web-runtime acceptance is claimed. Before a pilot, use a supported production PHP HTTP runtime/reverse proxy, TLS, production secrets, private database networking, tested storage grants and monitored workers/backups.
