# Module operations

Run commands from the canonical Base CRM repository or its immutable release directory. Real environment/manifests/storage/credentials remain outside Git. Back up the database, private storage and manifest before controlled updates. This work is local only; do not run these examples against a customer installation without its own deployment authorization.

## Installation and enablement

Installed code is controlled by locked Composer dependencies and approved release metadata. Keep optional package versions compatible with the declared core contract; do not upload arbitrary PHP or activate unknown code through the UI. Composer/provider discovery and Vite discover only installed packages. The service-details example is a client extension, not the Customer Service business module.

A deployment manifest enables business functions independently:

```json
{
  "id": "example-client",
  "name": "Example CRM",
  "logo": "/brands/base-b.svg",
  "favicon": "/brands/base-b.svg",
  "locale": "en",
  "timezone": "Asia/Nicosia",
  "currency": "USD",
  "modules": ["customer-service"],
  "capabilities": {"sales": true},
  "module_labels": {"customer-service": "Customer Service"}
}
```

Use `CRM_MANIFEST` to point at a private deployment-controlled manifest. Do not edit shared core to customize another client's brand, labels or enabled modules. Hebrew source aliases belong in labels/localization metadata, not record keys or forced UI language. Existing Alpha/Beta remain different fictional brands and vocabularies; Service is off by default in both.

Enabling a sub-capability requires a registered CapabilityProvider declaration plus `"module:capability": true` under `capabilities`. Only select capabilities that actually ship. Setting Sales false hides and denies lead/pipeline endpoints and contributions while leaving shared identities and other authorized modules available. Removing a required dependency/capability fails validation; it is not silently disabled underneath a dependent module.

Apply controlled database changes and validate before traffic:

```sh
composer install --no-interaction
php artisan migrate --force
php artisan crm:install
php artisan crm:diagnose
npm ci
npm run build
```

Migrations run through these console/deployment commands, never web requests. `crm:install` inserts missing defaults and registered permission definitions; it never renames client stages or resets intentionally revoked grants. Optional-module permissions require explicit provisioning; enabling a module is not permission provisioning.

For an existing active synthetic account:

```sh
php artisan crm:permissions owner@alpha.example.test \
  --grant=customer-service.view.own \
  --grant=customer-service.edit.own \
  --grant=customer-service.create
```

Use exact installed permission names. Revoke a direct grant with `--revoke=...`. A permission inherited from a role must be changed on that role through controlled administration; the command refuses to pretend it has removed inherited access. Creating logins or external access is not implied by employee reference creation.

## Disablement and reminders

Remove an optional module from the manifest (and any enabled dependent capability selections). Validate the release/profile before activation. Data, history and documents remain retained. Direct requests, mutations, exports, document routes, relation selectors and generic reminder sources must deny access while unavailable. Current record policies are rechecked when queued jobs execute and when notifications are previewed/read. Obsolete versions/statuses/owners are suppressed. Read status does not finish work.

The existing scheduler invokes `crm:remind`, now also checking enabled module ReminderSource contributions. Run local queue workers for the correct installation environment. Frequencies are descriptive; no automatic routine/task generator is included.

## Recovery and verification

Prefer rollback to a compatible immutable code release plus the preserved manifest. Additive database updates retain records; do not drop tables on disablement or silently restore over newer writes. Follow the existing [backup/restore runbook](installations-and-upgrades.md). Rehearsals use disposable scratch databases and guarded fictional accounts only.

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

Backend tests reject any database except isolated PostgreSQL `base_test`. Browser flows use dedicated fictional accounts and guarded cleanup. Never import real customer CSV in verification. Diagnostics omit credentials and sensitive previews. Current actual outcomes, limitations and next actions are in [module-status.md](module-status.md); functional coverage is in [requirements-matrix.md](requirements-matrix.md).


## Executive and Operations profiles (Checkpoint 2)

The optional packages `awove/executive` and `awove/operations` use core 1.1 and contract 1. Select `executive` or `operations` in a private manifest's `modules` list; neither requires Customer Service or Finance. Sales may be explicitly disabled in a pure administrative profile. All registered migrations still run through controlled console commands; disabled tables are retained.

Grant only the required `executive.*` or `operations.*` own/all/create/edit/assign/archive/export permissions to approved staff. A shared task/routine owner must already be active and have view access to its module; assigning ownership does not provision that access. An unchanged inactive owner remains a historical reference. Concurrent important edits reject a stale version and require reload.

Operations directory use also requires explicit shared `identities.classifications.view` or `identities.classifications.manage`, plus the independent underlying identity scope. Grant `identities.view.all` only where broad directory reading is intended; it does not grant editing. `identities.classifications.assign` controls assigning another responsible person. Classification responsibility never opens unrelated Sales, Service, HR or financial records. New contacts/companies continue through the shared Add new contact flow, with the existing duplicate warning and identity-create permission.

Routine completions are dated and retained; schedule the next date manually in the completion form or a later edit. Leaving it empty stops the next reminder until a new date is supplied. Reminder read status does not complete a task or occurrence. Cancelled requests stop reminders without a completion date. Supplier order receipts track deliveries only and never create expenses, payments or stock transactions.
