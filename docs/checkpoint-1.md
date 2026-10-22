# Checkpoint 1 — independent Base CRM

Verified locally on 22 October 2026. Application root: `base-crm/`. Branch: `codex/base-crm-foundation`. This describes the initial local verification, before GitHub publication was requested. Publication uses a separate branch based on GitHub `main`; see `source-of-truth.md`. No remote deployment, production migration, real mail or live customer connection was made. Existing unrelated changes and CRM applications were preserved.

## Working behavior

The persisted workflow is complete: Fortify sign-in → company/contact → owned lead → status change → authored note → follow-up → scheduler/database queue reminder → read reminder without completing work → complete follow-up → changed dashboard counts. Browser checks perform this at desktop and mobile sizes against PostgreSQL 18.

Core includes scoped list/profile/identity/task/reminder/dashboard access, optimistic lead/task versions, append-only activity through application interfaces, archive behavior, admin invitations/deactivation/reassignment, last-admin protection, enforced administrator MFA enrollment, actual TOTP challenge verification, expiring one-use password onboarding and no public registration/account deletion.

Alpha and Beta use the same core source with separate database roles/databases, app keys, sessions, cache/queue boundaries and private storage roots. Their fictional employee review passwords are independent and randomly generated. Beta adds terminology/status-label changes, a select custom field and the optional service-details package. Extension data survives disable/re-enable; missing/incompatible modules fail clearly. Core-only PHP tests and production assets also pass with extension source and Composer dependency physically removed in a temporary copy.

## Exact verification commands and results

| Command | Result |
| --- | --- |
| `php artisan test --compact` | **Passed: 64 tests, 396 assertions**, PostgreSQL 18.6 (`base_test`), 12.287s in final full run. |
| `vendor/bin/pint --test` | Passed after formatting PHP, including package and synthetic verification scripts. |
| `vendor/bin/phpstan analyse --debug --no-progress --memory-limit=512M` | Passed, zero errors; level 5 covers host and both package source directories, with module migrations included for model inference. |
| `npm run check` | Passed, Prettier checks host and package resources. |
| `npm run typecheck` | Passed, Vue/TypeScript. |
| `npm run build` | Passed, Vite production assets; installed package pages and panel compiled. |
| `npm run test:e2e` | **Passed: 4 tests**, desktop/mobile main workflow and Beta extension, final run 22.0s. Screenshots saved below. |
| `python3 scripts/core-only-build.py` | Passed: optional source/dependency absent, mirrored PHP package discovery, **23 core tests / 258 assertions**, frontend production build and module-route absence. See JSON result. |
| `python3 scripts/check-reminder-concurrency.py` | Passed: **2 real workers, 20 identical queued jobs, 1 delivered reminder**. Probe uses synthetic local Alpha data only. |
| `python3 scripts/rehearse-upgrade.py` | Passed for Alpha and Beta: real additive migration, preserved data/configuration/permissions/extension fingerprints, PostgreSQL custom-format dump/restore and synthetic private-file archive restoration. |
| `docker-compose --profile full config --quiet` | Passed. |
| `docker-compose --profile full build alpha` | Passed; final image built as `99168ea6bd02` at verification. Subsequent builds can have different IDs. |
| Image filesystem check (`docker run --rm awove-base-crm-alpha php -r …`) | Passed: core installed as copied files, no `.env` or `.local-fixtures.json` in image. |
| Local production-mode image smoke on port 8094 | Passed: `/up` and `/login` HTTP 200; Alpha branding served with `APP_ENV=production`, `APP_DEBUG=false`, synthetic database and mail logging. Temporary smoke container stopped afterward. |
| `composer validate --no-check-all` | Passed schema/lock validation. `composer validate --strict` exits 1 only for warnings about intentional exact host package pins; these pins are the release model, not unresolved dependency errors. |
| `composer update awove/crm-core awove/example-service-details --no-interaction --no-scripts` | Passed, lockfile synchronized; no PHP security advisories reported. |
| `npm install` after removing vulnerable starter bundled tooling | Passed, audit reported zero vulnerabilities. |

Earlier checks found and corrected: starter Laravel/Inertia version mismatch, inactive attribute defaults on newly created user models, Beta web process env propagation, generated package-cache contamination in the temporary extension-free build, extracted user-service import/guard mistakes, stale starter assertions for removed routes, and browser assertions/labels. Final results above supersede those failed development runs; no failed check is presented as passed.

## Review screenshots

- [Desktop overview](screenshots/desktop-dashboard.png)
- [Mobile overview](screenshots/mobile-dashboard.png)
- [Desktop lead workflow](screenshots/desktop-lead.png)
- [Mobile lead workflow](screenshots/mobile-lead.png)
- [Desktop Beta extension](screenshots/desktop-beta-extension.png)
- [Mobile Beta extension](screenshots/mobile-beta-extension.png)

JSON evidence: `core-only-build-result.json`, `upgrade-rehearsal-results.json`, `reminder-concurrency-result.json`. Playwright traces/report are local ignored artifacts; screenshot assets show only fictional records.

## Access and operational limits

Alpha: http://127.0.0.1:8091. Beta: http://127.0.0.1:8092. Private employee review credentials are in ignored `.local-fixtures.json`. First-admin accounts `owner@alpha.example.test` and `owner@beta.example.test` have been bootstrapped locally; their time-limited onboarding links are in Mailpit at http://127.0.0.1:8028. Re-request a reset link from the login screen if expired. MFA must be enrolled/confirmed before privileged use.

The validated runtime is native PHP/Node with isolated PostgreSQL/Mailpit containers; the container image has also been built and smoke-tested. The full multi-process Compose profile is provided and configuration-validated, but was not brought up concurrently on the same ports as the native preview. Production web-serving performance, remote Coolify/Hetzner deployment, external S3 credentials, outbound real mail and a real pilot are **not verified**. The image currently uses PHP's local development HTTP server; replace and accept a production HTTP runtime before customer deployment. Container base images use version tags; production release automation should pin platform image digests and publish a release ledger.

English is shipped. Layout uses logical inline properties and flexible responsive grids; additional language catalogs and complete translated-language/RTL acceptance are not supplied. No claim is made that every future language is supported. Native example logs/compiled views share host runtime infrastructure; uploaded/private storage roots and persistent state are installation-separated, and full Compose has separate storage volumes. Real deployments must use separate installation runtime directories/images as documented.

Identity forms create and search companies/contacts, warn on visible normalized duplicates and preserve separate records when explicitly requested. Shared identity editing, comprehensive permission-role editing, visual custom-field builders, saved-view management and bulk actions are not exposed. Lead pickers currently offer up to 200 scoped contacts/companies; searchable remote identity pickers are a scaling follow-up.

## Deferred scope

Secure document upload/download, CSV import/export, fuller saved views/bulk operations and any pilot-required calendar connector remain deferred. Private filesystem/S3 readiness is not a document vault. Calendar synchronization, WhatsApp/telephony, AI agents, invoicing/payments, digital signatures, native mobile apps, multiple pipelines and visual automations are not implemented. There are no UI placeholders for these features.

The additive rehearsal uses an explicitly labeled first-build pre-update fixture; it cannot prove historical-release compatibility. Full production release governance, monitored recovery targets and real customer acceptance remain future work. Checkpoint 1 stops here for review.
