# Shared Material data tables

Leads, contacts, companies and follow-ups use the same Material 3 table component, backed by TanStack Vue Table 8 and a permission-scoped PostgreSQL query engine. Each table exposes its authorized business-field catalog; authentication secrets and unrelated record data are never table fields.

## Controls

- **Columns:** search by field/group, show any available field, show all, reset layout. Reorder through header drag handles or the column sheet. Keyboard users can use the sheet move buttons or Alt+Left/Right on a header drag handle.
- **Column options:** A–Z/Z–A, low–high/high–low or oldest–newest/newest–oldest depending on field type; move left/right, hide, pin/unpin to the left, and adjust width. Drag a column separator to resize, use arrow keys on a focused separator, or double-click to reset its width.
- **Sorting:** click a header to replace the sort; Shift+click adds a sort. The Sort dialog supports up to five ordered criteria. Priorities use Low < Normal < High, numbers sort numerically, text sorts case-insensitively, and empty values remain last in either direction. Stable record-ID ordering resolves ties.
- **Filters:** up to twenty conditions using AND or OR. Text supports contains/not-contains, exact/not-exact, starts/ends and empty/not-empty. Numbers and dates support comparisons and ranges. Enumerated/boolean fields support equality and multi-value choices. Date comparisons against timestamps use the current user's configured timezone and the entire selected local calendar day.
- **Search:** debounced, case-insensitive, literal substring matching across the authorized field catalog. It applies to the full result set before pagination. `%` and `_` in user input are escaped rather than treated as wildcard operators.
- **Views:** save named column/search/sort/filter combinations to the current user's account, select them later, or delete them. Column widths, pins, row density and text wrapping are also persisted separately per user and table. Views do not modify anyone else's records or layout.
- **Reading:** sticky headers, pinned columns, internal horizontal scrolling, selectable comfortable/compact rows, wrapping, meaningful empty/error/loading states and selectable page sizes of 10/20/25/50/100. Selection is explicitly limited to the current page; Copy rows copies selected visible cells as tab-separated text. It performs no bulk data mutation.

All available lead business fields include linked contact/company names and communication details, owner, source, priority, stable pipeline projection, creation/update/archive timestamps, record identifiers, external reference, next follow-up, follow-up counts, notes count and last activity. Configured typed custom fields appear automatically. Contacts/companies and follow-ups have their own domain-specific catalogs. Internal implementation columns and secrets are deliberately excluded.

## Backend boundaries

`Tables/Catalog` owns trusted field metadata and SQL expressions. `Tables/Query` accepts only catalog field IDs, supported typed operators, validated values, directions, sizes and bounded condition counts. Request text never becomes SQL identifiers or executable expressions. Parent permission scopes are applied before grouped search/filter clauses, preventing OR filters from widening access.

`Tables/Preferences` validates and persists a bounded layout/view payload within the user's existing JSON display preferences, using a row lock so saving a contacts layout does not overwrite a concurrently saved leads layout. No new migration is needed. Legacy lead column preferences are read as a fallback.

Optional modules can implement the additive `Contracts/LeadTableContributor` interface and return typed `Tables/Column` definitions. Contributions require enabled installed code and module permission. The demonstration extension contributes service reference and requested service. Disabled/unauthorized fields cannot be queried, sorted or filtered; stale saved-view references are sanitized at read time while the stored view remains available after re-enabling. Core has no dependency on the specific demonstration package.

## Verification

`tests/Feature/TableTest.php` covers cross-page server search/pagination, multi-sort, semantic priority and numeric custom-field order, AND/OR/range/empty/multi-value filters, timezone-local dates, scoped contacts/follow-ups, rejected secret/SQL-shaped input, per-user/table persistence and extension permission/disable states. `tests/e2e/tables.spec.ts` exercises the column sheet, desktop drag/mobile move controls, keyboard resizing, pinning, reload persistence, named views, query controls, module/custom columns and responsive containment. Existing persisted CRM browser scenarios remain in place.

The engine uses server pagination, not a full-database client download. Very large installations should profile searches against their actual field/extension set before adding specialized indexes; no unmeasured scale or performance guarantee is claimed.

Table browser scenarios use dedicated per-installation/per-viewport fictional users, so they do not overwrite the reviewer account's table layouts. Prepare them with `php scripts/seed-table-e2e.php alpha` and `php scripts/seed-table-e2e.php beta`. Native browser dialogs provide focus containment and Escape dismissal.

References: [TanStack column-ordering documentation](https://github.com/TanStack/table/blob/main/docs/guide/column-ordering.md), [Material data-table guidance](https://m2.material.io/components/data-tables).

## Recorded local results — 22 October 2026

- `php artisan test --compact`: 73 passed, 652 assertions (64 existing CRM tests plus nine table-focused tests).
- `vendor/bin/phpstan analyse --debug --no-progress --memory-limit=512M`: passed, zero errors.
- `vendor/bin/pint --test`, `npm run check`, `npm run typecheck`, `npm run build`: passed.
- `npm run test:e2e`: eight desktop/mobile scenarios passed, including column persistence, query controls and the original persisted CRM workflow. The final run also checks selection and clipboard copying.
- `python3 scripts/core-only-build.py`: passed, optional source/dependency absent, 23 core tests / 262 assertions, production frontend assets compiled.
- Stable `@tanstack/vue-table` dependency installed and locked; npm audit reported zero findings.

Review [desktop table](screenshots/desktop-advanced-leads-table.png), [mobile table](screenshots/mobile-advanced-leads-table.png), [desktop field chooser](screenshots/desktop-table-field-chooser.png) and [mobile field chooser](screenshots/mobile-table-field-chooser.png). Earlier failed development checks exposed nested-query encoding and stale test-user preference issues; these were corrected before the passing runs above. No remote deployment or new container runtime verification was performed for this iteration.
