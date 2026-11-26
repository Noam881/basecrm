# Deployment footprint audit — 26 November 2026

Scope: shared deployment maintenance for `Noam881/basecrm`; no business-module or customer-data changes. Source baseline: accepted commit `4f164bfbb650f324cd2e04038781e418b223edc5`.

## Findings

| Item | Measurement | Evidence boundary |
| --- | ---: | --- |
| Entire tracked repository | 8,127,426 bytes / 7.75 MiB; 1,039 files | `git ls-tree -rl` at the baseline; includes documentation and tests |
| Documentation screenshots | 3,287,922 bytes / 3.14 MiB | Tracked source, not customer uploads |
| Build dependencies (`node_modules`) | Approximately 252 MiB | Local macOS installation; Linux production size was not measured |
| Composer runtime packages | Approximately 90 MiB of file contents | Local locked dependency inventory excluding development packages; disk allocation differs |
| Mirrored Awove packages | Approximately 2.24 MiB per release | Intentional Composer path-package copies, required by the modular build |
| Clean frontend build, before → after | 5,220,598 → 2,919,523 bytes (44.08% smaller) | Isolated Vite outputs; all JavaScript bytes unchanged |
| Retained live release directories | 19 | Enhance file manager, `SITE_HOME/deploys`; also contains `current` |
| Latest listed backups | 5.88 GB each, marked S3 | Enhance backups at 13:33 and 13:40 EET, before the 13:56 release on 26 November |

The current `4f164bf…` and previous `651edcee…` release directories both retain `node_modules`, `vendor` and `.git`. The original deployment hook ran `npm ci --include=dev` and built assets without removing build dependencies afterwards. It also had no historical-release retention step. Home-level `.npm`, `.cache` and `.nvm` directories exist; their recursive sizes were not available in the file-manager UI.

The repository contains no tracked backup archives, database dumps, generated dependencies or compiled builds. Whole-file duplicate detection found no identical tracked files of at least 256 bytes. This does not imply there is no repeated logic: proposal/contract document handling and unused starter UI are smaller maintenance candidates, not gigabyte-scale causes. Vault's repeated authorization/deadline checks are intentional security controls.

The frontend review identified an unimported `@material/web` dependency (approximately 7.5 MiB locally) and three unused Material Symbols font weights. The application explicitly renders these icons at weight 400. This change removes the unused dependency and imports only the 400 font stylesheet; it preserves the shared Material 3 controls, tokens, motion and elevation. The unused starter UI subtree is a separate small source-cleanup candidate, left for a focused reachability/refactoring review.

Validation: clean builds before and after, TypeScript and formatting checks passed. JavaScript totals remained 1,148,214 bytes; icon font files fell from eight to two (3,000,600 → 702,888 bytes). The six hermetic deployment fixtures passed, covering successful cleanup, failed/missing-manifest builds, unsafe symlink/file targets, and preservation of shared storage, environment links, the previous release and `deploys/current`. These fixtures do not substitute for a live deployment or recursive server inventory.

Enhance explicitly [includes Git deployment history in backups](https://enhance.com/docs/github-deploy/github-deploy-on-enhance). Its [S3 backup documentation](https://enhance.com/docs/backup-role/admin/s3-backups) specifies full `tar.gz` archives; the built-in backup role's hard-link deduplication must not be assumed for S3.

Conclusion: retained deployments and build dependencies are confirmed unnecessary storage sources and the primary explanation to investigate for the large archives. The exact contribution of each live directory, the database and compression has **not** been measured; do not present local sizes as a reconciliation of all 5.88 GB.

## Correction and live cleanup

The updated release hook discards only the newly built release's `node_modules` after a successful asset build and manifest check. It preserves runtime PHP dependencies, source, built assets, private storage and installation configuration. See [Enhance production deployment](enhance-production.md).

Before removing existing server files, use an authorized website shell to record recursive disk usage. These commands are read-only and do not read file contents:

```sh
cd /var/www/4221a676-c7bc-4764-a592-d42e9e05a83c
readlink -f deploys/current
du -x -h --max-depth=1 deploys .npm .cache .nvm .basecrm
du -x -h --max-depth=1 deploys/4f164bfbb650f324cd2e04038781e418b223edc5
du -x -h --max-depth=1 deploys/651edcee6758dcb593a7f363b9b86eec29457ef0
```

Keep the active release and a verified rollback release. Check worker/cron working directories and each candidate's storage links before removing older releases; older failed or pre-persistent-storage releases may contain unique data. Inventory all 17 other directories first. Review npm/Composer download caches separately; retain the Node runtime needed for deployments. Do not remove `.basecrm`, environment files, encryption keys, uploads, databases or backup archives as part of build cleanup.

Historical server releases and S3 backups were not deleted, and live backup retention was not changed by this audit. Pruning releases permanently removes local rollback copies, so approve the measured candidate list before that action. Existing S3 archives will retain their original size; only a later backup after live cleanup can verify the reduced footprint.
