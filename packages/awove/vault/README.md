# Vault / כספת

Optional package `awove/vault`, module `vault`, extension contract 1. This implementation is for code review, hardening and security assessment with fictional data. It has not been deployed or provisioned for a customer installation. CRM email-only sign-in is unchanged.

## Existing-system integration

- A customer is an existing `crm_contacts` or `crm_companies` identity, not a lead. Each `vault_items` row has exactly one restricted foreign key to those tables; no Clients table or duplicated customer secrets are created. Central and customer-profile lists query the same rows.
- Reads use the existing `IdentityPolicy::view`; changes also require `IdentityPolicy::update`. The current identity policy permits an identity's creator, users with `identities.manage` / `identities.view.all`, or users with an accessible associated lead to view the identity. That last rule is existing CRM behavior, not an assumption that Lead and Contact are equivalent. Vault action permissions, item level and valid authentication remain independent requirements.
- The additive Core `IdentityPanelContributor` / `IdentityPanel` contract composes authorized metadata-only panels on contact/company profiles. Generic `ExtensionPanel` supports a pre-resolved customer endpoint; existing Lead panels continue unchanged. Shared DataTable accepts string or numeric IDs and an optional metadata loader for embedded JSON tables.
- `VaultModule` contributes navigation, the customer panel and `vault.items` table columns. No Sales dependency is required. Installed, enabled, granted and enrolled are separate states. Disabling hides contributions and denies routes/services; rows and encrypted files remain retained.
- Normal CRM authentication continues through the existing email magic link, database session and active-user middleware. Vault uses a separate Argon2id password hash, encrypted TOTP seed, one-time emailed challenge and server-side unlock grant. It never activates the unused CRM password/MFA fields.
- Attachments use the installation's private local disk. Only ciphertext and encrypted original filename metadata persist. No preview, OCR, remote service connection, wallet transaction or public download URL exists.

## Data and authorization

Migrations `2026_11_26_001600_create_vault_data.php` and `2026_11_26_001610_create_vault_auth.php` add the namespaced item/file/audit/member/challenge/unlock/recovery tables. Parent deletion is restricted rather than cascading Vault items. Item/file deletion is logical; ciphertext is retained for recovery. A retention and independently reviewed permanent-erasure process must be agreed before real-secret use. Migrations refuse destructive rollback.

Permissions are `vault.list`, `read`, `create`, `update`, `delete`, `level1`, `change-level`, `transfer`, `files.upload`, `files.download`, `files.delete`, and `admin` (all prefixed `vault.`). No optional permissions are automatically granted by installation. Bootstrap explicitly grants Vault permissions to its selected first administrator; it does not widen that user's underlying customer permissions.

Level 1 is more restricted: it requires `vault.admin` or explicit `vault.level1` in addition to the requested action and customer policy. Level 2 follows customer policy plus the independent Vault action. Even administrators need a current unlock to read or change content. A view permission does not grant writes; changing level or customer requires its separate permission. Both old and new customers are checked on transfer.

Titles, customer references, type, level, authors/versions and times are metadata. Titles must never contain passwords, full card numbers or private keys. Custom field labels, values, notes, usernames, URLs, card details, keys, recovery codes and attached bytes/names are encrypted. Search, counts, sorting and pagination operate on authorized metadata only. Known prohibited card-field labels are rejected, but free text cannot be reliably classified; do not store CVV/CVC, card PIN or magnetic-stripe data anywhere, including notes and attachments. Encryption alone is not PCI approval: assess applicable PCI obligations before storing real card numbers. See [PCI SSC guidance](https://www.pcisecuritystandards.org/faqs/1319/).

## Controlled activation recipe — not performed for a real installation

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

Set `VAULT_KEY_FILE=/run/secrets/basecrm/vault-keyring.json` in private deployment configuration. Keep the key file mode 0600 and its parent directory restricted. Key material must be independently backed up outside the database/storage backup. Never use `APP_KEY`, an emailed token, TOTP code or a security answer as an encryption key. A missing, malformed or unavailable version fails closed.

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

## Authentication and 300-second unlock

The sequence is CRM session → Vault password → fresh email confirmation → TOTP (or one-time recovery code). Passwords require 12–1024 characters and preserve exact spaces. Ten randomly generated recovery codes are displayed once and stored as Argon2id hashes. A recovery code replaces only TOTP, not the password or fresh email step.

Email tokens are random, hashed in the database, session/user/installation/factor-bound, single-use and valid for 600 seconds from the password step. Resend cancels the old token without extending the challenge lifetime. Tokens live in URL fragments: GET/HEAD cannot approve them, and confirmation requires an authenticated CSRF-protected POST in the initiating session. Links cannot sign in another user or transfer the unlock to a different browser. SMTP providers necessarily handle the message; disable click tracking, link rewriting and analytics for these messages. Synchronous delivery avoids storing usable tokens in queued jobs. Production log/array mailers are rejected; isolated local testing uses Mailpit, and backend tests use the non-delivering array transport.

Password attempts are rate-limited, as are email sends and factor attempts. TOTP counters and recovery-code use are updated under database locks. The enrollment confirmation's counter is consumed, so immediate first unlock requires the next unused authenticator code.

A server grant expires exactly 300 seconds after all factors complete. Requests, typing, copying and editing never extend it. Every sensitive action rechecks current identity/action/level/factor/session state. Supported application permission/role changes and user activation/email/verification changes synchronously advance a security generation and revoke grants/challenges across the affected user's memberships; restoring a permission cannot revive prior authentication. Vault enables the maintained Spatie permission events while installed. Administrative tools must use those model/permission services; direct SQL repairs bypass application events and require controlled invalidation before traffic resumes. Logout and session changes prevent reuse. The browser's monotonic countdown clears forms, values and DOM on expiry/lock/page lifecycle transitions, rejects late responses and does not persist sensitive drafts or useful unlock tokens in browser storage. Previously copied, saved or photographed information cannot be recalled.

HTTP administrator approval/reset transactions recheck the original unlock deadline and administrator authority before committing. Factor-reset notices contain no invitation or Vault values and use the existing database queue; keep its worker running and monitor delivery failures. Protected console operations send their notice directly. Dedicated email challenges remain synchronous and never put usable tokens in queued jobs.

The optional security-question mode is off by default. It requires both `VAULT_DEV_QUESTION=true` and `VAULT_FICTIONAL_ONLY=true`, local/testing environment and an actual guarded local example/test database and role. Production/staging/pilot use is rejected. A trusted local `vault:dev-question` command prompts for a per-user test answer and stores only its hash. No shared answer is shipped. The question only replaces the TOTP step; the password and fresh email remain required. A prominent fictional-test-mode notice is displayed.

For an explicitly fictional local Alpha installation only, enable both flags in its private local configuration and run `php artisan vault:dev-question owner@example.test --confirm-installation=alpha`. The command prompts privately; never pass or record an answer in command arguments.

## Encryption, backup, restore and rotation

The installed PHP Sodium extension supplies authenticated XChaCha20-Poly1305 with a fresh random nonce per envelope. Authenticated associated data binds key version, installation and record/purpose context; file envelopes additionally bind the parent item and file. Original secret whitespace and Unicode are retained. This is server-decryptable storage, not zero knowledge or end-to-end encryption. See [PHP's API](https://www.php.net/manual/en/function.sodium-crypto-aead-xchacha20poly1305-ietf-encrypt.php), [the installed TOTP library](https://github.com/antonioribeiro/google2fa) and [OWASP key separation guidance](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html).

Back up the installation database and complete private storage together, recording release/manifest/key-version identifiers. Back up the keyring through a separate protected process with independent access controls. Restore into an isolated installation with its matching ID/key versions; validate fictional markers before enabling traffic. Without matching keys, recovery is intentionally impossible; a user-factor reset cannot decrypt lost encryption keys.

Perform rotation as a controlled maintenance change without concurrent keyring edits. To rotate, retain old key versions, add and activate a new random version, verify all envelopes, then apply:

```sh
php artisan vault:keyring /run/secrets/basecrm/vault-keyring.json --add --key-version=v2
php artisan vault:rotate-keys
php artisan vault:rotate-keys --apply
```

Rotation covers item payloads, encrypted files/names and active/pending TOTP seeds. New ciphertext is staged; database errors preserve original references/bytes. Old files are removed only after successful transaction commit. Retain old keys for all supported backups until a documented retirement review; test restoration before deletion. Re-encryption does not change external passwords, wallet keys or the underlying TOTP seed. A compromise requires a separate incident/credential/factor-response process.

## Review evidence and remaining prerequisites

See repository tests `VaultDataTest`, `VaultCipherTest`, `VaultAuthenticationTest`, `VaultBoundaryTest`, `IdentityPanelTest`, and the desktop/mobile browser acceptance. Test secrets are deliberate fictional markers or ephemeral random values; no real customer secret or email is used. Exact completed results are recorded in the PR after checks finish.

The 26 November 2026 Composer audit of the unchanged locked baseline reported 26 advisories across nine packages. This task adds only `awove/vault` and uses existing Sodium/Google2FA versions; it does not upgrade the framework or unrelated dependencies. An up-to-date dependency remediation/review is a prerequisite before real-secret use, alongside HTTPS, trusted proxy/host configuration, verified mail delivery, independently protected key backups, tested restore/operator recovery, host/temporary-upload permissions, and independent security assessment. For example, see the [Symfony filesystem advisory](https://symfony.com/cve-2026-135803) and [Laravel concurrency advisory](https://github.com/laravel/framework/security/advisories/GHSA-63hm-g96r-2m2q). Passing tests is not a security certification, penetration test or guarantee against compromise. No live activation, real-secret import, PCI attestation or customer-installation change is included.
