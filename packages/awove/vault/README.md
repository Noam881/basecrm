# Vault / כספת

Optional package `awove/vault`, module `vault`, extension contract 1. Installed code, enabled module, provisioned keys, granted access and completed enrollment are separate states. Real-secret use requires the hardening, provisioning and security assessment below. CRM email-only sign-in is unchanged. Installation-specific owner decisions are recorded in [Vault activation decisions](../../../docs/vault-activation-decisions.md).

## Existing-system integration

- A customer is an existing `crm_contacts` or `crm_companies` identity, not a lead. Each `vault_items` row has exactly one restricted foreign key to those tables; no Clients table or duplicated customer secrets are created. Central and customer-profile lists query the same rows.
- Reads use the existing `IdentityPolicy::view`; changes also require `IdentityPolicy::update`. The current identity policy permits an identity's creator, users with `identities.manage` / `identities.view.all`, or users with an accessible associated lead to view the identity. That last rule is existing CRM behavior, not an assumption that Lead and Contact are equivalent. Vault action permissions, item level and valid authentication remain independent requirements.
- The additive Core `IdentityPanelContributor` / `IdentityPanel` contract composes authorized metadata-only panels on contact/company profiles. Generic `ExtensionPanel` supports a pre-resolved customer endpoint; existing Lead panels continue unchanged. Shared DataTable accepts string or numeric IDs and an optional metadata loader for embedded JSON tables.
- `VaultModule` contributes navigation, the customer panel and `vault.items` table columns. No Sales dependency is required. Installed, enabled, granted and enrolled are separate states. Disabling hides contributions and denies routes/services; rows and encrypted files remain retained.
- Normal CRM authentication continues through the existing email magic link, database session and active-user middleware. Vault authentication is deployment-selected: the Base default uses a separate password, fresh email and TOTP; the approved `email_riddle` mode uses fresh email and a shared riddle passphrase for explicitly approved CRM administrators. Neither mode activates the unused CRM password/MFA fields.
- Attachments use the installation's private local disk. Only ciphertext and encrypted original filename metadata persist. No preview, OCR, remote service connection, wallet transaction or public download URL exists.

## Data and authorization

Migrations `2026_11_26_001600_create_vault_data.php` and `2026_11_26_001610_create_vault_auth.php` add the namespaced item/file/audit/member/challenge/unlock/recovery tables. Parent deletion is restricted rather than cascading Vault items. Item/file deletion is logical; ciphertext is retained for recovery. A retention and independently reviewed permanent-erasure process must be agreed before real-secret use. Migrations refuse destructive rollback.

Additive migration `2026_11_26_001620_add_vault_email_riddle_mode.php` adds installation-scoped shared verifier settings, explicit administrator approval and challenge/grant mode markers. It preserves existing passwords, TOTP seeds, permissions and Vault content.

Permissions are `vault.list`, `read`, `create`, `update`, `delete`, `level1`, `change-level`, `transfer`, `files.upload`, `files.download`, `files.delete`, and `admin` (all prefixed `vault.`). No optional permissions are automatically granted by installation. Bootstrap explicitly grants Vault permissions to its selected first administrator; it does not widen that user's underlying customer permissions.

Level 1 is more restricted: it requires `vault.admin` or explicit `vault.level1` in addition to the requested action and customer policy. Level 2 follows customer policy plus the independent Vault action. Even administrators need a current unlock to read or change content. A view permission does not grant writes; changing level or customer requires its separate permission. Both old and new customers are checked on transfer.

Titles, customer references, type, level, authors/versions and times are metadata. Titles must never contain passwords, full card numbers or private keys. Custom field labels, values, notes, usernames, URLs, card details, keys, recovery codes and attached bytes/names are encrypted. Search, counts, sorting and pagination operate on authorized metadata only. Known prohibited card-field labels are rejected, but free text cannot be reliably classified; do not store CVV/CVC, card PIN or magnetic-stripe data anywhere, including notes and attachments. Encryption alone is not PCI approval: assess applicable PCI obligations before storing real card numbers. See [PCI SSC guidance](https://www.pcisecuritystandards.org/faqs/1319/).

## Controlled activation recipe — not performed for a real installation

### Approved administrator email-and-riddle setup

For the simplified initial installation, set deployment-controlled `VAULT_AUTH_MODE=email_riddle`. The Base default remains `password_email_totp`. This mode is a deliberate shared-passphrase policy: CRM sign-in → dedicated fresh Vault email confirmation → the configured riddle passphrase → 300 seconds. It requires no personal Vault password, TOTP enrollment or personal recovery codes. Every request additionally requires the fresh exact CRM `Administrator` role and `vault.admin`; individual action permissions, customer policies and item levels remain enforced.

Apply migration001620 and initialize installed permissions through the normal controlled deployment. Prepare the dedicated AEAD keyring in persistent private storage outside the project/public tree, for example `SITE_HOME/.basecrm/vault-keys/keyring.json`, with mode 0600 and an owner-only parent directory. Set its private `VAULT_KEY_FILE` path. This approved setup keeps the keyring in the existing private website backup and does not require a separate encrypted backup, external key directory or local key export. Ordinary backup access remains protected; a matching keyring is still necessary for restoring ciphertext.

While the module remains disabled, explicitly grant each approved existing administrator the full Vault permission set using `crm:permissions`, retaining their underlying customer scope. Use a protected interactive operator console:

```sh
php artisan vault:configure-riddle --confirm-installation=installation-id
php artisan vault:approve-riddle-admin owner@example.test --confirm-installation=installation-id
php artisan vault:approve-riddle-admin additional-admin@example.test --confirm-installation=installation-id
```

The first command privately prompts for the shared passphrase twice and asks for its public question. Passphrases require 3–1024 characters and retain exact whitespace; only their salted Argon2id hash is stored. No answer is accepted as a command argument, printed or shipped in source. Approval requires an existing active, verified CRM `Administrator` with all Vault actions and `identities.manage`; it grants no permissions. These commands require the exact installation confirmation and work while Vault is disabled. Enable the module only after settings, approvals and existing backup/runtime checks are ready. No web visitor can configure the riddle, grant themselves approval or reset it through email. An operator changes it through the same protected console; changing it cancels every installation challenge/grant and queues a secret-free security notice.

The mode is distinct from the fictional development question below and is available in production only through explicit deployment selection. The UI and notices identify email-and-riddle authentication accurately. Before changing authentication mode on an existing installation, stop traffic and invalidate outstanding authentication through a controlled operator maintenance procedure; do not use configuration rollback to restore previous proof. The shared answer and mode are included in proof fingerprints, so mismatched or changed policy cannot authenticate an existing challenge.

### Default individual password, email and TOTP setup

Use the locked release and existing framework versions:

```sh
composer install --no-interaction
php artisan migrate --force
php artisan crm:install
npm ci
npm run build
php artisan crm:diagnose
```

Add `vault` to the private installation manifest's `modules` list. Keep its existing unique installation ID, database, session, branding and other module choices. English/purple remain Base defaults; select `locale: he`, `theme: green` through deployment presets for a Hebrew/RTL installation. Module registration does not enable it in existing manifests.

For 10 MB document uploads, set PHP `upload_max_filesize` to at least 10M and `post_max_size` above that limit; lower host limits remain effective.

Prepare an operator-controlled directory outside the repository/public root, owned by the PHP service/deployment account and accessible only to that account. Generate keys directly to a protected file without printing their values:

```sh
php artisan vault:keyring /run/secrets/basecrm/vault-keyring.json --key-version=v1
```

Set `VAULT_KEY_FILE=/run/secrets/basecrm/vault-keyring.json` in private deployment configuration. Keep the key file mode 0600 and its parent directory restricted. The default strong-mode operational recipe uses an independently protected key backup; the approved initial email-and-riddle backup policy is described above. Never use `APP_KEY`, an emailed token, TOTP code or a security answer as an encryption key. A missing, malformed or unavailable version fails closed.

The example `/run/secrets` path requires an actual persistent secret mount. Verify survival across container restart/recreation; do not put the sole key in an ephemeral container directory. The explicitly simplified Basics profile uses a protected persistent installation directory outside all releases and includes it in the existing private website backup, accepting combined key/data backup custody for now; see its owner decisions.

From a trusted operator console, explicitly select an existing active, email-verified CRM account and the exact manifest installation ID:

```sh
php artisan vault:bootstrap owner@example.test --confirm-installation=installation-id
```

The one-time setup invitation is shown once to the operator. Deliver it through a separately verified channel, not ordinary email-only recovery. The named user signs into the CRM, enters the invitation and a new personal Vault password, then confirms its authenticator TOTP before activation. A pending first bootstrap can be reissued only for that same original unconfirmed account; it cannot replace previously confirmed factors or claim another account. Additional users require an unlocked Vault administrator's explicit approval, or the protected console process:

```sh
php artisan vault:approve member@example.test --operator=admin@example.test --confirm-installation=installation-id
php artisan vault:reset-factors member@example.test --operator=admin@example.test --confirm-installation=installation-id
```

Those console operations prompt privately for an enrolled administrator's Vault password and fresh unused TOTP. Approval is distinct from action permission grants; provision exact permissions with the existing `crm:permissions` tool. Factor replacement revokes challenges/grants and notifies the target without secret values. Losing the sole administrator's password requires an independently verified infrastructure/operator recovery procedure; no automated email-only reset is provided. Agree and rehearse that operator identity/recovery policy before real use.

### Read-only activation preflight

Run as the actual PHP service/deployment user, using the installation's private configuration:

```sh
php artisan vault:preflight --confirm-installation=installation-id --minimum-admins=2
```

The optional administrator minimum defaults to one; two is the approved initial profile for `basics.awove.dev`, not a global authorization rule. A qualifying full administrator is active and verified, holds every Vault action permission and has `identities.manage` for customer updates. Strong mode requires completed individual enrollment; email-and-riddle requires explicit operator approval and the exact CRM `Administrator` role, with no personal password/TOTP requirement. `vault.admin` alone is insufficient. Keep the item-level selector unchanged.

The command is read-only and prints only JSON booleans. Exit zero / `automated_checks_passed: true` means its configuration checks passed, including a persistent installation-database limiter store. It does not send email, start workers, inspect backups, test restoration, grant permissions or enable the module. Delivery, ordinary backup, restore rehearsal, proxy/TLS/monitoring and post-snapshot authority reconciliation remain explicitly unverified; record that evidence privately. `separate_key_backup_required` is false in the approved email-and-riddle mode, so an independent encrypted backup is not an activation gate. Configuration checks never certify backup contents, mail delivery or security.

## Authentication and 300-second unlock

In the Base default mode, the sequence is CRM session → Vault password → fresh email confirmation → TOTP (or one-time recovery code). Passwords require 12–1024 characters and preserve exact spaces. Ten randomly generated recovery codes are displayed once and stored as Argon2id hashes. A recovery code replaces only TOTP, not the password or fresh email step. The email-and-riddle mode instead follows the administrator-only sequence above.

Email tokens are random, hashed in the database, session/user/installation/mode/factor-bound, single-use and valid for 600 seconds from challenge initiation. Resend cancels the old token without extending the challenge lifetime. Tokens live in URL fragments: GET/HEAD cannot approve them, and confirmation requires an authenticated CSRF-protected POST in the initiating session. Links cannot sign in another user or transfer the unlock to a different browser. Email providers necessarily handle the message; disable click tracking, link rewriting and analytics for these messages. Synchronous delivery avoids storing usable tokens in queued jobs. Only an approved direct delivery transport is accepted in production; log/array, unknown and composite transports are rejected. Isolated local testing uses Mailpit, and backend tests use the non-delivering array transport.

Password attempts are rate-limited, as are email sends and factor attempts. TOTP counters and recovery-code use are updated under database locks. The enrollment confirmation's counter is consumed, so immediate first unlock requires the next unused authenticator code.

A server grant expires exactly 300 seconds after all factors complete. Requests, typing, copying and editing never extend it. Every sensitive action rechecks current identity/action/level/factor/session state. Supported application permission/role changes and user activation/email/verification changes synchronously advance a security generation and revoke grants/challenges across the affected user's memberships; restoring a permission cannot revive prior authentication. Vault enables the maintained Spatie permission events while installed. Administrative tools must use those model/permission services; direct SQL repairs bypass application events and require controlled invalidation before traffic resumes. Logout and session changes prevent reuse. The browser's monotonic countdown clears forms, values and DOM on expiry/lock/page lifecycle transitions, rejects late responses and does not persist sensitive drafts or useful unlock tokens in browser storage. Previously copied, saved or photographed information cannot be recalled.

HTTP administrator approval/reset transactions recheck the original unlock deadline and administrator authority before committing. Factor-reset notices contain no invitation or Vault values and use the existing database queue; keep its worker running and monitor delivery failures. Protected console operations send their notice directly. Dedicated email challenges remain synchronous and never put usable tokens in queued jobs.

The separate fictional-only development question for strong-mode tests is off by default. It requires both `VAULT_DEV_QUESTION=true` and `VAULT_FICTIONAL_ONLY=true`, local/testing environment and an actual guarded local example/test database and role. Production/staging/pilot use of that development shortcut is rejected. A trusted local `vault:dev-question` command prompts for a per-user test answer and stores only its hash. No shared answer is shipped. This test shortcut only replaces the TOTP step; the password and fresh email remain required. A prominent fictional-test-mode notice is displayed. It is not the deployment-selected production email-and-riddle mode.

For an explicitly fictional local Alpha installation only, enable both flags in its private local configuration and run `php artisan vault:dev-question owner@example.test --confirm-installation=alpha`. The command prompts privately; never pass or record an answer in command arguments.

## Encryption, backup, restore and rotation

The installed PHP Sodium extension supplies authenticated XChaCha20-Poly1305 with a fresh random nonce per envelope. Authenticated associated data binds key version, installation and record/purpose context; file envelopes additionally bind the parent item and file. Original secret whitespace and Unicode are retained. This is server-decryptable storage, not zero knowledge or end-to-end encryption. See [PHP's API](https://www.php.net/manual/en/function.sodium-crypto-aead-xchacha20poly1305-ietf-encrypt.php), [the installed TOTP library](https://github.com/antonioribeiro/google2fa) and [OWASP key separation guidance](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html).

Back up the installation database and complete private storage together, recording release/manifest/key-version identifiers. Preserve the keyring under the selected operational policy: the approved initial email-and-riddle setup uses the existing private website backup, while the default strong-mode recipe uses independent custody. Restore into an isolated installation with its matching ID/key versions; validate fictional markers before enabling traffic. Without matching keys, recovery is intentionally impossible; changing an authentication factor cannot decrypt lost encryption keys.

A restored database also restores authentication history: sessions, outstanding enrollment invitations, factor hashes, consumed recovery-code state and old authorization assignments. Do not expose restored traffic immediately. Stop the scheduler and queue workers, keep the installation isolated, and enter Laravel maintenance mode. Confirm that this is the installation's dedicated database with database sessions and the expected manifest identity, then explicitly invalidate restored temporary authentication:

```sh
php artisan down
php artisan vault:invalidate-restored-auth --confirm-installation=installation-id --confirm-restore
```

This command refuses an unconfirmed installation, incompatible database/session configuration or an application outside maintenance mode. It runs one transaction, increments Vault security generations, removes grants/challenges and pending invitations/setup state, deletes restored CRM database sessions and magic-login links, and writes a metadata-only audit event. Every CRM user must request a fresh login link. It works while Vault is disabled and is never invoked automatically by deployment, key setup or an ordinary login. It preserves active Vault password/TOTP/recovery hashes, permissions, encrypted content, files and keys. Pending users need fresh explicit enrollment approval.

Temporary-authentication invalidation cannot identify factor resets, recovery codes used, account changes or permission revocations that happened after the backup. Before reopening traffic, reconcile those changes against the incident/administrative record and reapply revocations through the supported model/permission services. When a restored factor or recovery-code history is uncertain or compromised, perform a verified reset/re-enrollment through the other enrolled administrator or the protected operator recovery procedure. Restoring an old database must never silently restore authority known to have been revoked. If neither administrator's factors remain trustworthy, keep the installation isolated until the documented infrastructure-owner identity process restores controlled administration; email access alone remains insufficient. Rehearse this procedure with fictional data and retain the evidence privately, then restart workers/scheduler and leave maintenance mode only after the review is complete.

Perform rotation as a controlled maintenance change without concurrent keyring edits. To rotate, retain old key versions, add and activate a new random version, verify all envelopes, then apply:

```sh
php artisan vault:keyring /run/secrets/basecrm/vault-keyring.json --add --key-version=v2
php artisan vault:rotate-keys
php artisan vault:rotate-keys --apply
```

Rotation covers item payloads, encrypted files/names and active/pending TOTP seeds. New ciphertext is staged; database errors preserve original references/bytes. Old files are removed only after successful transaction commit. Retain old keys for all supported backups until a documented retirement review; test restoration before deletion. Re-encryption does not change external passwords, wallet keys or the underlying TOTP seed. A compromise requires a separate incident/credential/factor-response process.

## Review evidence and remaining prerequisites

See repository tests `VaultDataTest`, `VaultCipherTest`, `VaultAuthenticationTest`, `VaultBoundaryTest`, `IdentityPanelTest`, and the desktop/mobile browser acceptance. Test secrets are deliberate fictional markers or ephemeral random values; no real customer secret or email is used. Exact completed results are recorded in the PR after checks finish.

The 26 November 2026 security update remediates the baseline's 26 Composer advisories with targeted compatible Laravel, Symfony and WebAuthn patches. Fresh locked Composer and npm audits report zero known advisories; the typechecker keeps its current major version with a scoped patched minimatch override. Existing Sodium/Google2FA versions are retained. See the [Symfony security release](https://symfony.com/blog/symfony-8-1-12-released), [Laravel advisory](https://github.com/laravel/framework/security/advisories/GHSA-63hm-g96r-2m2q) and [WebAuthn advisory](https://github.com/web-auth/webauthn-framework/security/advisories/GHSA-c9p6-r72h-rp3x).

Production still requires protected persistent keys/storage, HTTPS, secure sessions, persistent rate limiting, delivering mail, monitored queues and controlled recovery. Follow the installation's approved backup policy; separate key custody is deferred explicitly for the Basics profile. Passing tests or clean advisory audits is not a security certification, penetration test or guarantee against compromise. This source change does not itself activate an installation, import real secrets or establish PCI compliance.
