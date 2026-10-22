# Awove Base CRM

An independent, newly created CRM foundation. Nothing is imported from, connected to, or migrated from any existing CRM. This application lives in `base-crm/` only because the current Git workspace contains other projects; this directory is its standalone application root.

Checkpoint 1: shared host + versioned core + deployment-installed extensions + independent installation configuration. This is a local development checkpoint, not a production-readiness certification.

## Canonical source

[Noam881/awove.cloud on GitHub](https://github.com/Noam881/awove.cloud) is the source of truth for this application. CRM source lives in `base-crm/`, independently of the repository-root website. See [source and release policy](docs/source-of-truth.md). Secrets and live installation data stay outside Git.

## Open the local examples

- Alpha: http://127.0.0.1:8091 — base CRM, charcoal brand, no enabled demonstration module.
- Beta: http://127.0.0.1:8092 — grey brand, Enquiries terminology, different pipeline labels, custom field, optional service-details panel.
- Development mail: http://127.0.0.1:8028 — Mailpit only; no real messages are sent.

Run `php scripts/seed-local.php` and `php scripts/seed-local.php beta` for fictional review records. Private, randomly generated review credentials are saved in `.local-fixtures.json` (ignored, mode 0600). These are employee accounts, not an administrative bypass. Do not use synthetic fixtures in production.

## Selected stack

PHP 8.5; Laravel 13.38.0; official Laravel Vue starter source pinned at `d282e817c6c2fa1bd475f7c42ea785ccfc67d0ab`; Vue 3 / TypeScript / Inertia 3.5.2; Tailwind 4 / official starter shadcn-vue components; Fortify 1.41.0; Spatie Permission 8.3.2; PostgreSQL 18.6; Pest 4; Playwright. Exact resolved PHP/JS dependencies are in `composer.lock` and `package-lock.json`.

The Composer-tagged starter is outdated. The current official starter was downloaded at the pinned revision above. Its bundled Vite Plus 0.3.0 dependency had audit findings; this build uses standard Vite 8 with Prettier instead. No prerelease dependency constraints were added. See [official starter documentation](https://laravel.com/starter-kits) and [Spatie compatibility](https://spatie.be/docs/laravel-permission/v8/prerequisites).

## Local setup (native PHP / Node, containerized PostgreSQL / Mailpit)

Requirements: PHP 8.5 with PDO PostgreSQL, intl, mbstring, XML and zip; Composer 2; Node 26; Docker and Compose; PostgreSQL 18 command-line tools; Python 3. On this Mac, PostgreSQL tools are at `/opt/homebrew/opt/libpq@18/bin`. Set `CRM_POSTGRES_BIN` for other locations.

From this directory:

```sh
composer install
npm ci
python3 scripts/configure-local.py
docker-compose up -d postgres mailpit
python3 scripts/provision-local.py
php artisan migrate --force
php artisan crm:install
php artisan --env=beta migrate --force
php artisan --env=beta crm:install
npm run build
php scripts/seed-local.php
php scripts/seed-local.php beta
```

`configure-local.py` refuses to overwrite existing installation keys. `provision-local.py` provisions the fresh local example databases only. Do not repeat provisioning against an initialized volume. The database is bound to loopback; Alpha, Beta and tests use separate, non-superuser database roles.

Run these processes separately:

```sh
php artisan serve --host=127.0.0.1 --port=8091 --no-reload
APP_ENV=beta php artisan serve --host=127.0.0.1 --port=8092 --no-reload
php artisan queue:work --tries=3 --sleep=1
php artisan schedule:work
APP_ENV=beta php artisan queue:work --tries=3 --sleep=1
APP_ENV=beta php artisan schedule:work
```

**Beta web startup must propagate `APP_ENV=beta` to the PHP child process.** `artisan --env=beta serve` alone was found insufficient in this runtime. CLI one-shot commands can use `--env=beta`. The two env files contain separate database credentials, app keys, session cookie names, cache prefixes, and installation identities. Uploaded/private storage is rooted by installation. The full Compose profile additionally provides separate storage volumes per installation.

## First administrator

```sh
php artisan crm:bootstrap-admin owner@alpha.example.test --name="Alpha Owner"
php artisan --env=beta crm:bootstrap-admin owner@beta.example.test --name="Beta Owner"
```

These commands create only the first administrator, using an unknown random password. Open the reset/onboarding link in local Mailpit within 60 minutes, set a password, sign in, confirm your password on Account security, and enroll/confirm MFA before using the CRM. Public registration and account deletion are unavailable. Administrator invitations use the same expiring reset mechanism. Recovery uses Fortify recovery codes, stored privately by the account holder; see the [operations runbook](docs/installations-and-upgrades.md).

## Repeatable container build

```sh
docker-compose --profile full config --quiet
docker-compose --profile full build alpha
```

After native servers are stopped, the full local stack can run with:

```sh
docker-compose --profile full up -d
```

Run migrations and `crm:install` through deployment commands before opening a fresh installation. Worker/scheduler containers share their installation's private volume. This development image uses PHP's built-in server; deploy behind a tested production PHP web runtime before a real pilot. No infrastructure has been provisioned. [Production environment example](docs/production.env.example) keeps debug, Mailpit and fixtures out of production.

## Verification

All backend integration tests use the isolated `base_test` PostgreSQL database. A test boot guard rejects any other database. Never point these tests at a customer database.

```sh
php artisan test --compact
vendor/bin/pint --test
vendor/bin/phpstan analyse --debug --no-progress --memory-limit=512M
npm run check
npm run typecheck
npm run build
npm run test:e2e
python3 scripts/core-only-build.py
python3 scripts/rehearse-upgrade.py
docker-compose --profile full config --quiet
```

`--debug` makes PHPStan run without a local worker socket, useful in restricted shells. Browser tests need both web servers and synthetic local fixtures. Chromium must be available (`npx playwright install chromium` if needed). They invoke the real reminder command and queue worker. Screenshots are in `docs/screenshots/`. Core-only checks build a temporary copy with the optional module physically removed; they do not modify this installation. Upgrade/restore checks use new scratch databases, never reset Alpha or Beta, and clean up only databases created by that invocation.

See [architecture](docs/architecture.md), [extension contract](docs/extensions.md), [installation/update/recovery runbook](docs/installations-and-upgrades.md), and [checkpoint evidence and limitations](docs/checkpoint-1.md).

To check real overlapping reminder workers against synthetic local Alpha data:

```sh
python3 scripts/check-reminder-concurrency.py
```

The command records a diagnostic result, completes and archives its fictional probe record, and never runs against another CRM database.
