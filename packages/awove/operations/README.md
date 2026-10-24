# Administrative Operations

Optional package `awove/operations` 1.0.0 requires `awove/crm-core` ^1.1, contract 1. Its stable module ID is `operations`; it does not require Customer Service, Sales, Executive Management or Finance. Add it to the installation manifest's `modules` list and explicitly grant its permissions. Installation registers permissions without granting existing Employee roles access.

## Records and shared foundations

- Customers and suppliers classify existing shared contacts or companies. Their name, phone and email always come from that identity. Responsible person, main contact, activity state, notes and classification history are shared Core data. Operations exposes their scoped directory and permitted links to operational requests, supplier orders and Sales opportunities when Sales is enabled. No identity is copied, matched by name or merged automatically.
- Operational requests use Core WorkItems and the shared WorkFields controls: title, optional description, owner, priority, due date, status, completion date plus an optional classified customer, notes and HTTPS document link. Defaults are New, In progress, Waiting, Done and Cancelled. Reopening retains previous completion history.
- Supplier orders retain supplier, reference, description, optional quantity, exact agreed total and installation currency, owner, order date, expected delivery, document link and outstanding items. Defaults are Draft, Ordered, Part received, Received and Cancelled. Receipts append dated descriptions and exact quantities. Their retry key makes exact repeated submissions harmless; a reused key with different input fails. Optimistic record versions reject stale edits.
- Routines use shared Core routines, reusable fields, optional checklists and dated occurrence snapshots. Frequency is a human description. Completion clears the next date unless the owner explicitly schedules another date; no recurrence engine creates work automatically.

Agreed quantities support up to three decimal places, and totals up to two. Calculations use integer thousandths rather than binary floating point. Quantity must be positive when supplied; receipt quantity cannot exceed the agreed remainder. Supplier and quantity become immutable after the first receipt. Receipt-derived states cannot be reset through ordinary edits. Cancelling or archiving retains receipts and history. Receipt dates before the order date are rejected, and later edits cannot move the order date after an existing receipt. Unquantified orders use an explicit complete/partial choice and a required description of outstanding items for partial delivery; no zero quantity is fabricated.

Order audit stores supplier, owner and order-date changes and each receipt's date/outstanding snapshot. Inaccessible supplier relationship IDs are redacted from serialized history. Shared request/routine audit records changed field names and completion transitions. Files are represented by validated HTTPS links. Document redirects authorize the current parent record, use private/no-store/no-referrer headers, and never fetch the remote document server-side.

## Access and lifecycle

Permissions are `operations.view.own`, `operations.view.all`, `operations.create`, `operations.edit.own`, `operations.edit.all`, `operations.assign`, `operations.archive` and `operations.export`. All-record read does not grant edit or assignment. The directory additionally requires explicit Core `identities.classifications.view` or `.manage`; classification assignment uses `.assign` or `users.manage`. Broad shared identity read is a separate `identities.view.all` permission.

Selectors, advanced search, tables, exports, directory links and reminder previews respect independent identity access. A hidden historical customer or supplier remains linked internally when an otherwise permitted edit leaves its redacted field untouched. Owner assignment uses active authorized users; ordinary edits retain unchanged inactive or unassigned owners. Mutations reload the active actor; exact receipt retries do not bypass fresh access checks.

Disable the module to remove navigation, widgets, table catalogs and reminder sources and reject its direct routes, mutations, exports and document redirects. Records remain stored. Generic request/routine reminders and supplier delivery reminders check the current module, recipient, owner, due date, record version and terminal/archive state at enqueue, delivery and display. Marking a notice read does not complete the work. Re-enable to regain scoped access.

## Configuration and data views

`module_labels.operations` sets the navigation label. `operations_request_statuses` and `operations_order_statuses` may override labels for known stable keys; internal states and transitions stay unchanged. Request keys are `new`, `in-progress`, `waiting`, `done`, `cancelled`; order keys are `draft`, `ordered`, `part-received`, `received`, `cancelled`.

The overview filters owner, customer, supplier and due/expected dates. Customer filters apply to requests; supplier filters apply to orders; routines use owner/date filters. Lists reuse the advanced shared table with all permitted columns, search, typed filters, multi-sort, resizing, keyboard reordering, visibility, saved views and pagination. Namespaced layouts are `operations.requests`, `.orders`, `.routines` and `.directory`.

CSV exports use the same scoped table search, sort and filters, include permitted column headers, apply shared spreadsheet-formula protection and reject more than 5,000 rows before streaming. Operations CSV import is not implemented. No payment, invoice, bank, contract, calendar, messaging or external provider integration is implied.

## Local verification

`tests/Feature/OperationsTest.php` exercises independence, lifecycle guards, record scopes, hidden historical links, exact receipts, retries, optimistic conflicts, append-only history, manual routine/checklist snapshots, all reminder sources and advanced table/CSV behavior on the isolated PostgreSQL test database.

`tests/e2e/operations.spec.ts` drives actual desktop/mobile workflows against an Operations-only local Alpha installation on port 8095. `scripts/operations-browser-fixtures.php` accepts only the isolated Alpha database/role/port and exact `operations-{desktop,mobile}@alpha.example.test` accounts and fictional identities. Cleanup checks ownership, known record names/kinds, child actors and related classifiers, saves a private recovery snapshot, and deletes in dependency order within one transaction. Restrictive foreign keys abort if protected external records reference those identities. No real account, message or external URL is used.
