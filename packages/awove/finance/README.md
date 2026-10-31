# Finance and Collections

Optional package `awove/finance` version 1.0.0 implements an operational financial tracker. Stable module `finance` has no business-module dependencies. Select its capabilities independently with `finance:collections`, `finance:expenses`, and `finance:payroll`. Enabling Finance alone does not enable any capability. Neither collections nor supplier expenses requires Sales, Operations or HR.

Collections and expenses use one shared ledger foundation; payroll preparation remains a distinct workflow. This package does not issue invoices, calculate taxes or payroll, connect to banks, transfer funds, create accounting entries or transmit payroll records.

## Customer charges and supplier expenses

Charges retain a classified shared customer, reference, description, positive total payable, owner, due date, optional next collection action/date and supporting HTTPS document link. Supplier expenses retain a classified shared supplier, category, document reference, description, total, owner, due date and invoice/receipt link. Existing identities may be classified inline through Core's shared fields and explicit classification permissions; no separate copied customer/supplier directory is created.

Payments append an exact positive amount, payment date, optional reference/notes and actor. Remaining balance is always total payable minus the sum of retained payments. Unpaid, Part paid and Paid states derive from that sum; Cancelled is an explicit retained state. Internal state keys stay stable; `finance_statuses` may customize the known display labels.

Core `Money` uses the already locked Brick Math dependency and fixed two-decimal strings. It rejects floats, exponent notation, negative amounts, extra precision and values above the record limit. Aggregate sums remain exact beyond a single-record limit. Money inputs use text fields with decimal keyboards, preserving the entered string.

A parent row lock and optimistic version check protect payment/update races. A unique per-record submission key and normalized input fingerprint make exact payment retries harmless. Reusing a key with changed input fails. Fresh actor, capability and record authorization happen before returning an earlier payment. Payments and audit entries cannot be updated/deleted through their models.

Once payments exist, the agreed total and customer/supplier cannot change. Descriptions, due dates, owners and collection actions may still be edited with retained history. Refunds, credits, overpayments, negative payments and cancellation after any payment are unsupported pending an agreed correction policy. The package rejects them explicitly. An unpaid record can be cancelled with a reason; it retains its original total and unpaid balance, records no fabricated payment and is excluded from expected receipts/payments. No archive/delete action hides outstanding obligations.

## Expected and actual views

Expected receipts and supplier payments sum current outstanding balances by parent due date. Actual receipts and supplier payments sum immutable payments by their payment date, independently of the parent's due period. A January charge partially paid in February contributes its remaining balance to January expected receipts and the recorded payment to February actual receipts. These views are not an account balance or historical cash-flow forecast.

One configured currency is stored with each financial record and payroll preparation. `Finance\Currency` delegates to the shared Core Currency guard across all installed, registered monetary snapshot tables, including retained rows in disabled capabilities. A deployment currency mismatch preserves readable records but blocks financial writes and combined monetary reports with a configuration error, rather than summing different currencies or showing a fabricated zero. Currency conversion is unsupported.

## Access and lifecycle

Permissions are `finance.{capability}.view.own`, `.view.all`, `.create`, `.edit.own`, `.edit.all`, `.assign`, `.export`; ledger capabilities additionally declare `.record-payment` and `.cancel`. New permissions are registered without automatically granting Employee roles access. Payroll uses its own sensitive permissions and export boundary. All-record read does not grant edit, payment, cancellation or assignment.

Every route, table catalog, search, export, document redirect, widget and reminder checks the selected capability. Disabling one capability retains its records and stops access and its queued/delivered previews while another enabled capability continues working. Disabling Finance removes all its surfaces. Fresh current record scope and version checks suppress obsolete reminders after payment, reassignment, rescheduling or access loss. A read notice does not complete collection work or record a payment.

Shared identity permissions remain independent. Hidden historical identity relationships remain linked during unrelated permitted edits and are redacted from selectors, table search, exports and history. New/replacement classifications must have the correct visible active role. Supporting links use Core's HTTPS validation and private authorized redirect; no remote file is fetched by the server.

Advanced tables reuse Core's search, typed filters, exact numeric sort/display, selectable/reorderable/resizable columns, saved layouts and views. CSV exports preserve the same scope and query context, use shared formula protection and preflight a maximum of 5,000 rows. Ledger CSV import is not implemented.

## Verification

`tests/Feature/FinanceLedgerTest.php` covers independent capabilities, exact partial/full payments, immutable history, retries and stale writes, financial correction restrictions, distinct date-based reports, record/identity scopes, inline classifications, capability/module disable, fresh actor checks, reminders, advanced tables/CSV, document validation and currency mismatch.

Browser fixtures and desktop/mobile workflow tests use only reserved local Alpha `example.test` identities and Mailpit, with guarded ownership/dependency cleanup and private recovery snapshots. No real message, payment or external system action occurs.
