# Nonprofit Extension

Optional package `awove/nonprofit`1.0.0, Core`^1.1`, contract1. Hebrew source alias: **עמותה**. English interface labels remain the default. This package has no required optional-business-module dependency. Checkpoint6 acceptance is complete; see the repository delivery ledger for exact backend, browser, physical-absence and restore evidence. Final cross-module verification also passed; see [the completed delivery ledger](../../../docs/module-status.md).

Select only the needed deployment capabilities:

```json
{
  "modules": ["nonprofit"],
  "capabilities": {
    "sales": false,
    "nonprofit:programs": true,
    "nonprofit:fundraising": false,
    "nonprofit:projects": false
  },
  "module_labels": {"nonprofit": "Community programs"}
}
```

This standalone example enables no Finance, Operations, Assets, HR or Sales. Keep other installed capability entries only when their owning package is installed. Enablement grants no permissions. Use explicit `crm:permissions` grants after the controlled migrate/install process. Private participant, pledge/receipt, budget and document grants remain independent from basic record viewing.

- [Programs and restricted participants](PROGRAMS.md): locations/groups, programs/sessions, one recorded attendance per participant/session, scoped summaries, feedback and manual owner follow-ups. Private participant details do not enter general contact search.
- [Fundraising](FUNDRAISING.md): shared donor/partner/prospect classifications, contextual ownership/actions, exact pledges and optional links to existing Finance receipts. Pledges and actual payments remain separate.
- [Projects](PROJECTS.md): annual planning, optional exact budgets, shared tasks/milestones, meetings/decisions, authorized optional purchase/equipment links and actual Finance payment comparisons.

Root `/nonprofit` opens the first enabled authorized capability. Shared navigation, tables, filters/preferences, global Material controls, history, CSV, scoped HTTPS document redirects and owner notices reuse Core facilities. Each capability owns its service authorization and record scope. Disabling one capability denies its direct entry points and queued work while retaining its data; it does not disable the others. No placeholder business navigation is created for unavailable capabilities.

Optional links require both current modules and both record permissions. Historical hidden links remain stored, with sensitive IDs/labels omitted or marked unavailable. Missing financial access produces unavailable actuals, not a fabricated zero or complete-looking partial sum. Monetary writes use Core Money/Currency; retained pledges and entered budget snapshots participate in the one-currency guard even while disabled. Nonfinancial program/project records create no monetary snapshot.

Migrations001300/001310/001320 add only this package's records and links to existing Core identities/work. They never run in ordinary HTTP requests. Optional Finance/Operations/Assets IDs have no unconditional foreign keys to absent packages. No duplicate purchases, equipment, accounting rows or address books are generated. Restore a tested backup instead of destructive down migration.

Deferred by source: grant applications, funder reporting, safeguarding casework, participant assessments, donation receipting and external reporting integrations. No automatic recurrence, communications, provisioning or financial execution is added.
