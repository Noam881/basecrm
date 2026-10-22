# Source of truth

The canonical repository is https://github.com/Noam881/awove.cloud. This independent application's source root is `base-crm/`; the repository-root Awove Cloud website is a separate project.

GitHub `main` is the accepted baseline. Publish CRM changes through reviewed feature-branch pull requests; do not treat an unpushed local checkout, deployed image, server-side edit, or copied client folder as the authoritative source. Use the Git commit ID, `release.json`, Composer lockfile and npm lockfile together to identify a release.

The initial CRM publication is on `codex/base-crm-foundation-github`, based directly on GitHub `main`. Its pull request contains only the independent CRM and its CI workflow. The older local checkout has unrelated Git history; it must not be merged wholesale into this repository.

GitHub stores shared host/core/extension source, fictional installation examples, migrations, tests, setup/runbooks and non-secret verification evidence. Credentials, application keys, real client manifests, customer records, uploaded files and encrypted backups belong in deployment-controlled storage, never Git. A source repository is not a backup of live installation data.

For updates, start from current GitHub `main`, use a `codex/` feature branch, preserve client configuration outside the image, run the documented checks, and merge through review. Build release images from accepted commit IDs rather than moving branch references. See `installations-and-upgrades.md` for release and recovery procedures.
