# Awove Base CRM

An independent, newly created CRM foundation. Nothing is imported from, connected to, or migrated from any existing CRM. This repository root is the standalone application root.

Checkpoint 1: shared host + versioned core + deployment-installed extensions + independent installation configuration. This is a local development checkpoint, not a production-readiness certification.

## Canonical source

[Noam881/basecrm on GitHub](https://github.com/Noam881/basecrm) is the source of truth for this application. All CRM source and CI live in this standalone repository. See [source and release policy](docs/source-of-truth.md). Secrets and live installation data stay outside Git.

## Open the local examples

- Alpha: http://127.0.0.1:8091 — base CRM, charcoal brand, no enabled demonstration module.
- Beta: http://127.0.0.1:8092 — grey brand, Enquiries terminology, different pipeline labels, custom field, optional service-details panel.
- Development mail: http://127.0.0.1:8028 — Mailpit only; no real messages are sent.

Run `php scripts/seed-local.php` and `php scripts/seed-local.php beta` for fictional review records. The shared catalog uses Jewish/Hebrew names written in English, including Noa Levi and Daniel Cohen, with companies such as Rimon Studio, Carmel Supply and Shaked & Co, emails on `example.test`, and fictional `+1 202-555-01xx` phone numbers. Run `php scripts/localize-fixtures.php alpha` and `php scripts/localize-fixtures.php beta` to update existing synthetic examples in place without replacing login credentials, relationships or history. Private, randomly generated review credentials are saved in `.local-fixtures.json` (ignored, mode 0600). These are employee accounts, not an administrative bypass. Do not use synthetic fixtures in production.

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
php scripts/seed-table-e2e.php alpha
php scripts/seed-table-e2e.php beta
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

Sign-in uses email links only. Enter an active account email at `/login` and click **Sign in to CRM** in the email to open the dashboard immediately. Links expire after ten minutes and work once. Passwords and authenticator challenges are unavailable. See [email sign-in and security behavior](docs/authentication.md).

```sh
php artisan crm:bootstrap-admin owner@alpha.example.test --name="Avraham Levi"
php artisan --env=beta crm:bootstrap-admin owner@beta.example.test --name="Sarah Cohen"
```

These commands create only the first administrator and send a ten-minute email sign-in link. Open it in local Mailpit to enter the CRM directly. Administrator invitations send the same sign-in link. Request a fresh link from `/login` when needed. Public registration and account deletion are unavailable; see the [operations runbook](docs/installations-and-upgrades.md).

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

## Material tables

The application uses a neutral black/grey palette with scoped pink buttons, notices and badges, plus Material 3 Standard motion physics, shared elevation levels and animated outlined fields. See the [shared design system](docs/material-design.md).

Leads, contacts, companies and follow-ups share an advanced Material 3 table with selectable/reorderable/resizable/pinnable columns, typed server-side filters, multi-sort, full-result search, per-user layouts and named views. Configured custom fields and authorized module fields are available in the column chooser. Lead tables start with contact and company names. The enquiry title is optional and hidden in the default layout; add it through Columns when useful. See [table controls and query contracts](docs/advanced-tables.md).

## Customer details and shared controls

Open a name in Contacts & companies, or use Edit contact / Edit company in a lead's Customer card, to update name, email, phone and the contact's company. Missing details can be added and existing details cleared. Saving from a lead returns to that lead. Editing follows the existing creator and identity-management permissions.

Buttons, fields, cards and form spacing share `packages/awove/crm-core/resources/css/components.css`, including host account screens and extensions. Stacked forms use a 24px field-to-action gap. Follow the shared UI conventions in `AGENTS.md` for future changes.

## Browser fixture cleanup

Browser tests clean their dedicated project accounts before and after each workflow/contact test, including failed tests. A later run also clears leftovers from an interrupted process. Names stay natural; only fictional email addresses carry unique run markers. See the [clean review demo](docs/screenshots/clean-review-demo.png).

`php scripts/clean-browser-fixtures.php alpha --legacy` previews recognized leftovers from older browser runs. Add `--apply` to remove only those synthetic rows and their dependent test records. The command checks the local database/role, refuses shared references, saves a private recovery copy before deleting, and verifies retained records are unchanged. Beta uses the same command with `beta`. Recovery copies are ignored under `storage/app/private/browser-fixture-recovery`; restore one with `php scripts/clean-browser-fixtures.php alpha --restore=/absolute/path/to/copy.json --apply`. Restore refuses to overwrite existing records.

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

`--debug` makes PHPStan run without a local worker socket, useful in restricted shells. Browser tests need both web servers, synthetic local fixtures and the dedicated table fixture accounts created by `seed-table-e2e.php` for Alpha and Beta. Chromium must be available (`npx playwright install chromium` if needed). They invoke the real reminder command and queue worker. Screenshots are in `docs/screenshots/`. Core-only checks build a temporary copy with the optional module physically removed; they do not modify this installation. Upgrade/restore checks use new scratch databases, never reset Alpha or Beta, and clean up only databases created by that invocation.

Workflow and contact-edit browser tests use only the dedicated table account for their Playwright project (desktop/mobile), never the human review account. Strict `beforeEach` and `afterEach` cleanup must match reserved test ownership and exact fictional email/run identifiers while preserving canonical fixtures and unrelated records. Customer display names stay natural English spellings such as Noa Levi; device names, timestamps and run IDs belong only in fictional `example.test` email addresses. Keep local recovery snapshots private and Git-ignored; never publish backups or credentials.

See [architecture](docs/architecture.md), [extension contract](docs/extensions.md), [installation/update/recovery runbook](docs/installations-and-upgrades.md), and [checkpoint evidence and limitations](docs/checkpoint-1.md).

To check real overlapping reminder workers against synthetic local Alpha data:

```sh
python3 scripts/check-reminder-concurrency.py
```

The command uses the dedicated Alpha desktop table account created by `seed-table-e2e.php`, records a diagnostic result, and completes and archives its fictional probe record. It never writes to the human reviewer account or another CRM database.
