# Source of truth

The canonical repository is [Noam881/basecrm](https://github.com/Noam881/basecrm). This repository root is the application's source root. The CRM is an independent project; never publish its code, branches or pull requests to `Noam881/awove.cloud`.

GitHub `main` is the accepted baseline. Publish CRM changes through reviewed feature-branch pull requests; do not treat an unpushed local checkout, deployed image, server-side edit, or copied client folder as the authoritative source. Use the Git commit ID, `release.json`, Composer lockfile and npm lockfile together to identify a release.

The initial standalone `main` contains only the CRM history extracted from its original application directory, plus the root CI workflow and repository correction. No Awove Cloud website or unrelated CRM history is included. The mistaken, unmerged pull request in `awove.cloud` was closed and its branch deleted on 22 October 2026; that repository's `main` was unchanged. All future CRM work belongs here.

GitHub stores shared host/core/extension source, fictional installation examples, migrations, tests, setup/runbooks and non-secret verification evidence. Credentials, application keys, real client manifests, customer records, uploaded files and encrypted backups belong in deployment-controlled storage, never Git. A source repository is not a backup of live installation data.

For updates, start from current GitHub `main`, use a `codex/` feature branch, preserve client configuration outside the image, run the documented checks, and merge through review. Build release images from accepted commit IDs rather than moving branch references. See `installations-and-upgrades.md` for release and recovery procedures.
