# Email-only sign-in

Active users enter their account email at `/login` and open the email’s **Sign in to CRM** link. The browser completes sign-in automatically and opens `/dashboard`. There is no password, reset, confirmation button, authenticator challenge or required security setup. Administrator and employee navigation use the same flow. Account settings show the current sign-in method.

## Link and session behavior

- Links expire after ten minutes, are single use, and only the latest link for an account works. Tokens are random, stored as hashes, and bound to the configured installation and current email. A versioned credential fingerprint invalidates older links during this transition.
- GET and HEAD requests do not consume links, so ordinary mail scanners and prefetchers leave them usable. The browser landing page submits once automatically; the POST checks the signature, expiry, active account and fingerprint under a row lock before consuming the token.
- Responses conceal account existence, normalize email input and apply request/consume throttles. Queued email payloads are encrypted. Links always use the configured installation origin rather than an incoming Host header.
- Successful sign-in verifies the email, regenerates the session and opens the dashboard directly, clearing stale intended destinations. Opening an already-used link while signed in keeps the current session. After logout the same link cannot sign in again. Different authenticated accounts cannot consume each other’s links.
- Existing password hashes and encrypted MFA data remain stored, but password and MFA authentication routes and enforcement are disabled by the owner’s explicit choice. Legacy GET pages lead to email sign-in; old password/MFA mutation endpoints are unavailable. Active-user checks, permissions and last-administrator protection remain enforced.

## Operations and verification

Bootstrap and administrator invitations send `MagicLoginLink` through the same queue as normal sign-in. Configure a real production mail transport, supervise the database worker and retain each installation’s persistent application key. If mail delivery fails, repair it and request a new sign-in link for the existing account; do not repeatedly bootstrap accounts or set shared passwords.

Deploy through the native Enhance GitHub hook, refresh the website PHP container and verify the queue resolves the activated release. No production owner link is opened by automated tests. Backend tests cover signatures, expiry, replay, concurrent consumption, admin navigation, account changes and limits. Desktop/mobile browser tests use isolated fictional accounts and local Mailpit delivery to verify automatic sign-in and normal navigation.
