# Vault activation decisions — basics.awove.dev

Owner decisions confirmed and simplified on 26 November 2026. This records the intended installation setup; operational deployment and activation evidence is kept in the private installation ledger.

## Administrators and item levels

| Existing CRM account | Approved Vault access | Recovery responsibility |
| --- | --- | --- |
| `noam@awove.com` | First Vault administrator; full Vault actions for Level 1 and Level 2 items | Recovery authorization |
| `niva@mymisrad.co.il` | Additional Vault administrator; the same full Vault actions | Recovery authorization |

Other users initially receive no Vault permissions or enrollment approval. Both named administrators are to receive the complete Vault permission set, including `vault.admin` and every action permission. Additional-administrator enrollment approval alone does not grant those permissions. Verify their existing CRM customer access as part of provisioning; Vault retains the shared customer policies and installation boundary.

Each item must have a selected Level 1 or Level 2. This requirement already exists in the form, API and database. In the owner's terminology, administrators have “Tier 1 / full access”: both administrators may work with both item levels after completing the required authentication. Levels classify Vault items; there is no new user-tier field or role to add.

Further tier-specific rules and non-administrator assignments are deferred. Preserve the existing customer, action, level and installation checks while only the two approved full administrators are provisioned.

## Simplified administrator sign-in

Select the optional deployment-controlled `email_riddle` authentication mode for this installation. The normal CRM email-link sign-in remains unchanged. Vault then requires a fresh, dedicated email confirmation in the initiating CRM session followed by the configured shared riddle passphrase. A successful unlock lasts exactly 300 seconds; activity does not extend it.

This mode is limited to verified, active CRM Administrators with explicit Vault administrator/action permissions and operator-approved Vault membership. Each administrator uses their own email confirmation. There is no personal Vault-password or authenticator enrollment in this mode. The shared answer is entered privately through the operator configuration command and stored only as an Argon2id verifier; it cannot be configured or reset through an email-only flow. Changing it revokes current challenges and unlocks.

This explicit production mode is separate from the fictional local development-question option, which remains disabled. The stronger password/email/TOTP mode remains the reusable Base default. Do not describe the shared riddle as an authenticator code or as equivalent assurance to TOTP.

## Encryption, backups and recovery

Vault content and attachments remain encrypted on the server with a dedicated random key. The persistent keyring is outside the repository/releases/public root, in the website's private installation directory, with owner-only permissions.

The owner deferred the separate encrypted-key backup setup and external server-directory requirement. Use the existing private website backup process for now. Those archives include both encrypted Vault content and its keyring; they do not provide separate key custody. Access to the archives must remain protected. No local key export was completed. Both named administrators remain recovery owners; recovery follows the controlled operator process and must reconcile authentication/permission changes made after the restored snapshot.

## Activation checks

Complete the targeted dependency patches, protected persistent keyring, operator configuration of the shared riddle, explicit full grants and approval for both administrators, production mail/queue/host checks and the documented security review/tests. Verify the actual email flow with the intended user before claiming delivery. Follow the [Vault security and activation runbook](../packages/awove/vault/README.md). The original brief requires security review and hardening; it does not mandate an external assessor.
