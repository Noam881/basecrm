# Enhance production deployment

Use the native GitHub integration for `Noam881/basecrm`, with the reviewed branch/commit selected explicitly. Record its commit SHA in the installation release ledger. The repository root is the application root; the website document root must resolve to `current/public`. Enhance owns the `current` symlink and activates a prepared release only after its hook succeeds. The application Dockerfile is for local validation and uses a development HTTP server.

For this website the home is `/var/www/4221a676-c7bc-4764-a592-d42e9e05a83c`. Each native release is a direct child of `deploys`, using the full commit SHA as the directory name: `deploys/<full-commit-SHA>`. Run the hook as the website user with PHP 8.5, Composer, Node and npm on `PATH`. PHP needs PostgreSQL support (`pdo_pgsql`), intl and mbstring plus the locked Composer requirements; CI uses PostgreSQL 18.6 and Node 26. A PostgreSQL 16.15 compatibility run also passed all 90 backend tests / 1,012 assertions. The same PHP executable must be available to Composer and Vite's Artisan/Wayfinder build step.

Enhance's native GitHub deployment prepares NVM and Node before the configured hook; the deployment log confirms that setup step. The hook explicitly sources the website user's `SITE_HOME/.nvm/nvm.sh` and selects installed Node 26 before checking its tools. It never installs a runtime itself. If Node 26 is missing, provision it with Enhance's official `install_nvm_and_node.sh` workflow under the website user, explicitly source that nvm script, and run `nvm install 26`. Embedded PHP guards use `php -r` so they also work in isolated website containers without `/dev/stdin`.

## One-time private preparation

Prepare these outside Git and outside the public document root:

```text
SITE_HOME/.basecrm/.env
SITE_HOME/.basecrm/manifest.json
SITE_HOME/.basecrm/storage/
```

The website build/PHP user must be able to read the environment/manifest and write storage. Restrict the private directory and environment file appropriately (normally directory 0700, files 0600 for one website user). The hook preserves application data and refuses unexpected existing `.env` or storage locations; it retains the fresh Git storage placeholders as `.storage-template`. `bootstrap/cache` remains release-local and writable.

Use [the non-secret production environment shape](production.env.example). Set production mode, disable debug, use the real HTTPS `APP_URL`, a unique persistent application key, a dedicated PostgreSQL database/role, secure encrypted database sessions, a unique cookie/cache prefix, and database queues. Supply explicit `DB_HOST`, `DB_PORT`, `DB_DATABASE` and `DB_USERNAME` values; omit `DB_URL`. Before migrating, the hook compares the effective connection to those private values and refuses URL/server overrides without printing credentials. Generate the key once during private provisioning; changing it invalidates encrypted MFA and other encrypted data. The hook checks literal deployment guard values, so supply explicit values for `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_KEY`, `CRM_INSTALLATION`, `CRM_MANIFEST` and `DB_CONNECTION` rather than dotenv substitutions.

Use a stable PostgreSQL application login separate from Enhance's website operating-system login. Enhance can rotate the OS-linked PostgreSQL password when the website container restarts, so copying that password from `.pgpass` into the application's private `.env` will break later connections. Reserve `.pgpass` for administrative housekeeping and provision the application's persistent credentials independently. The application login must have `NOSUPERUSER`, `NOCREATEDB` and `NOCREATEROLE`, access only to its installation database, and the schema/table permissions required for the reviewed migrations. Scope any ownership changes to that installation's database and schema; do not change unrelated website databases or cluster roles.

Set `CRM_MANIFEST` to the absolute `SITE_HOME/.basecrm/manifest.json` path. Its `id` must match `CRM_INSTALLATION` (for example `basics`); keep locale `en`, an approved IANA timezone and neutral branding. Modules/custom fields must match the installed release. Local logo/favicon paths must exist under `public`. Never copy Alpha/Beta credentials, databases or fixture files into this installation.

The persistent local/public disks resolve to `storage/installations/<installation>/{private,public}`. The hook also creates framework cache/session/view and log directories. Do not expose the private disk or run `storage:link` without reviewing its target: the host overrides the public disk root, while Laravel's default link targets `storage/app/public`. Current CRM functionality does not need that public link.

## Native release hook

Run from Enhance's new release directory before activation:

```sh
bash scripts/deploy-enhance.sh /var/www/4221a676-c7bc-4764-a592-d42e9e05a83c
```

The optional second argument selects a release directory explicitly. The script validates the release path, required files, production guards and platform before building. It installs locked Composer dependencies without development packages, mirrors local path packages, installs npm build dependencies even under production `NODE_ENV`, and compiles `public/build`. Generated public assets receive read/search permissions for the web server (`chmod -R a+rX public/build`), which may run under a different user from PHP/build. The private environment, storage and caches retain restricted permissions. It then checks effective Laravel configuration, runs migrations and idempotent `crm:install`, caches application configuration/routes/views, and prints non-secret diagnostics. It does not change `current`, create accounts, seed records or regenerate keys.

Before updating an existing installation, take the database/private-file/configuration backup and coordinate writes and workers as described in [installation and recovery](installations-and-upgrades.md). A failed build leaves the active release unchanged. A later failure can follow an additive migration; never assume switching source undoes database changes or run destructive rollback commands automatically.

## Activation and account setup

After Enhance activates the release, refresh/restart the website PHP container in Enhance to clear FPM realpath/opcache state. A `current` symlink change can otherwise leave PHP using configuration from the previous release. Verify that the stable application database login still works after this refresh, then check the HTTP-to-HTTPS redirect, HTTPS `/up`, `/login`, generated assets, branding and secure HTTP-only cookies. Restart supervised workers through the newly active `current/artisan` using `php artisan queue:restart`; the supervisor must restart the process so it resolves the new release. Run one scheduler (`schedule:run` every minute) and one or more database queue workers for this installation, with its stable private environment. Inspect `crm:diagnose`, `migrate:status` and `queue:failed`.

Configure the intended production mail transport before creating the first administrator. The default log transport does not deliver onboarding email. Bootstrap once with `crm:bootstrap-admin` for the verified owner address, then complete its expiring password link and enforced MFA enrollment. The command creates the account before sending mail, so if mail fails, repair the transport and use the normal password-reset flow for that existing administrator; do not repeatedly bootstrap accounts. Routine releases never bootstrap users. Production password rules require a strong password and include a compromised-password check.

This guide and hook prepare the application; successful deployment additionally requires the actual native runtime, private PostgreSQL connectivity, TLS, mail, monitored workers/scheduler and tested backups. No fixture/provisioning/browser-cleanup script belongs in this live deployment.
