# Vault activation decisions — basics.awove.dev

Owner decisions confirmed on 26 November 2026. This records the intended installation setup; it does not grant permissions, enroll users, provision keys or activate Vault.

## Administrators and item levels

| Existing CRM account | Approved Vault access | Recovery responsibility |
| --- | --- | --- |
| `noam@awove.com` | First Vault administrator; full Vault actions for Level 1 and Level 2 items | Encryption-key backup custody and recovery authorization |
| `niva@mymisrad.co.il` | Additional Vault administrator; the same full Vault actions | Encryption-key backup custody and recovery authorization |

Other users initially receive no Vault permissions or enrollment approval. Both named administrators are to receive the complete Vault permission set, including `vault.admin` and every action permission. Additional-administrator enrollment approval alone does not grant those permissions. Verify their existing CRM customer access as part of provisioning; Vault retains the shared customer policies and installation boundary.

Each item must have a selected Level 1 or Level 2. This requirement already exists in the form, API and database. In the owner's terminology, administrators have “Tier 1 / full access”: both administrators may work with both item levels after completing the required authentication. Levels classify Vault items; there is no new user-tier field or role to add.

Further tier-specific rules and non-administrator assignments are deferred. No access-rule implementation or production permission change is requested by these decisions. Preserve the existing authorization checks while only the two full administrators are provisioned.

## Recovery ownership

Both named administrators are approved operational owners of the separately protected encryption-key backup and recovery/reset authorization. Record the actual custody location, protected access and identity-verification procedure in the private operations runbook before activation. No key material or backup credentials belong in this repository.

Use the existing verified administrator/operator recovery process. Each user enrolls their own Vault password, authenticator and recovery codes. The personal unlock sequence and exact 300-second expiry remain required for administrators. Factor resets and encryption-key recovery remain distinct operations; key backup custody does not bypass authentication or restore missing key material.

## Activation state and remaining work

Vault code and migrations were deployed on 26 November 2026; the live module remains disabled. These decisions resolve the administrator identities, initial access scope and recovery ownership. Live activation remains a separate step after technical readiness is established.

Before enabling real-secret use, complete dependency remediation/review, protected keyring provisioning and separate backup, enrollment and explicit grants for both administrators, production email/queue and host checks, restore/recovery rehearsal, and the required independent security assessment. Follow the [Vault security and activation runbook](../packages/awove/vault/README.md) for the complete requirements.
