# Email sign-in

Existing active users can choose **Email me a sign-in link** from `/login`, enter their account email, and open the email's **Sign in to CRM** link. The landing page shows the account and requires an explicit **Continue to sign in**. No password or password reset is required. Request a fresh link if it has expired; only the latest link for the account works.

Administrator MFA requirements remain in place. A new administrator can reach authenticator enrollment after email sign-in without confirming an unknown password. An enrolled administrator must complete the existing TOTP or recovery-code challenge before authentication finishes. The password confirmation screen also offers email sign-in for later reauthentication. Password login and password reset remain available.

## Implementation boundaries

- `App\Auth\MagicLogin` issues random 64-character, purpose-bound tokens lasting ten minutes. PostgreSQL stores a SHA-256 token hash, expiry and an account-credential fingerprint, with one row per user. An atomic transaction/row lock consumes the token once. New issuance replaces an earlier link.
- Signed URLs use the configured application origin, never an untrusted request Host. Only signed URL/route data authorizes consumption; POST body values cannot replace it. GET and HEAD never consume a link or authenticate. The CSRF-protected POST is the only consumption operation. Token pages prohibit caching, indexing and referrer transmission.
- Request responses are identical for known, unknown and inactive email addresses. No account is created. Request limits are five per minute per IP and three per ten minutes per normalized email/installation. Confirmation requests are limited to twenty per minute per IP/installation.
- Credential fingerprints bind the installation, email, password and MFA configuration. Email/password/MFA changes, inactive accounts, expiry and replay invalidate the corresponding flow. Different authenticated accounts cannot consume each other's links.
- `MagicLoginLink` is queued after commit and implements `ShouldBeEncrypted`, so the queued bearer link is encrypted at rest. The normal production queue worker and SMTP transport deliver the message. Never copy a live link or queue payload into logs, issue text, screenshots or Git.
- A completed flow regenerates the session and establishes recent authentication. Confirmed MFA users remain guests while the challenge is pending; its identity, credentials, eligibility and ten-minute deadline are checked before and after Fortify completes the second factor. Recent authentication is granted only after success. Switching back to password login clears the entire pending magic challenge.

## Deployment and verification

Deploy the additive `magic_login_tokens` migration through the native Enhance release hook, refresh the website PHP container, and verify the supervised queue resolves the new release. Existing passwords, roles, MFA configuration and customer data are retained. The production owner can request an email sign-in link immediately; do not run fixture scripts or set a shared administrator password.

`tests/Feature/Auth/MagicLoginTest.php` covers issuance, scanner-safe requests, signatures, hashes, expiry/replay, account changes, session rotation, MFA, reauthentication and limits. `tests/e2e/magic-login.spec.ts` exercises actual local Mailpit delivery and desktop/mobile confirmation using dedicated fictional fixture accounts. Live owner links are delivered to the owner and must not be opened by an automated test.
