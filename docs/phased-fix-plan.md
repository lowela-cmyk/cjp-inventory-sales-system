# CJP Inventory and Sales System — Phased Fix Plan

## Goal

Move the system from **NOT READY FOR TURNOVER** to **READY FOR TURNOVER** by fixing correctness first, then strengthening data protection, deployment readiness, and operational acceptance.

The plan deliberately prefers small changes to existing Laravel controllers, services, migrations, and tests. Do not introduce event sourcing, microservices, a new frontend framework, or a large repository/domain architecture.

## Approved Fuel Catalog

The business-approved fuel types are fixed:

| Code | Display name |
|---|---|
| `F1` | `F1` |
| `UNL` | `UNLEADED` |
| `DSL` | `DIESEL` |
| `PREM` | `PREMIUM` |

Use these four records throughout Purchase → Lifting → Stock-In → Inventory → Sale → Stock-Out → Reporting. Keep the existing `fuel_types` table because transaction tables already reference it. Do not replace it with free-text fields or a database enum.

The simplest enforcement model is:

- one small configuration list for runtime validation, selectors, seeders, and readiness checks;
- one idempotent data migration to normalize the four display names and deactivate non-approved fuel types;
- no UI or ordinary route for creating arbitrary fuel types;
- inactive historical fuel rows remain readable when referenced by old transactions, but cannot be selected for new transactions.

## Working Rules

1. Fix one phase at a time. Do not begin the next phase until the current exit gate passes.
2. Add a failing regression test before each correctness fix.
3. Keep inventory and financial writes inside database transactions.
4. Treat `sale_items`, `payments`, `stock_outs`, and `inventory_movements` as authoritative records. Avoid adding duplicate stored totals or statuses unless necessary.
5. Never repair production data before the code that prevents the same problem is deployed.
6. Run the focused tests while developing, then run the complete suite at every phase gate.
7. Treat the approved fuel catalog as fixed master data. New operational records may use only active `F1`, `UNL`, `DSL`, or `PREM` records.

## Phase 0 — Establish a Trustworthy Baseline

**Priority:** Immediate  
**Purpose:** Remove misleading signals before changing business logic.

### Tasks

- Fix the stale stock-in assertion that expects `MOV-000001`. Assert the generated movement code from the database or match the current `MOV-YYMMDD-XXXXX` format.
- Add the two missing regression tests:
  - A paid or fulfilled sale cannot be changed to `cancelled` through the edit route.
  - Changing a draft sale to `confirmed` performs the same stock-out preparation/release behavior as confirming a new sale.
- Update `docs/system-audit-report.md` so it no longer claims that all tests pass or that 93 routes exist.
- Change `cjp:readiness-audit` so missing trucks, drivers, purchases, hauls, inventory movements, or stock-outs are failures in turnover mode, not successful warnings.
- Add a catalog regression test proving that the only active fuel records are `F1`, `UNLEADED`, `DIESEL`, and `PREMIUM`, with codes `F1`, `UNL`, `DSL`, and `PREM` respectively.
- Add validation tests proving an inactive or non-approved `fuel_type_id` is rejected by purchase, lifting, stock-in, sale, stock-out, dashboard-filter, and AI-filter entry points.
- Rename or document the rollback smoke test accurately: it directly inserts most workflow records and is not an HTTP/UI acceptance test.
- Run Pint and commit only the formatting changes produced by Pint; do not mix style-only changes with business fixes.

### Primary files

- `tests/Feature/InventoryOfficerStockInTest.php`
- `tests/Feature/SalesOfficerSalesManagementTest.php`
- `routes/console.php`
- `app/Services/WorkflowSmokeTestService.php`
- `docs/system-audit-report.md`

### Exit gate

- Full PHPUnit suite passes.
- Pint passes.
- Readiness output no longer labels an operationally empty database as ready.
- Fuel catalog and fuel-selection validation tests pass.
- Documentation matches the current test and route counts.

---

## Phase 1 — Fix Sale, Stock-Out, and Receivable Correctness

**Priority:** Critical  
**Purpose:** Prevent financial and inventory contradictions.

### 1.1 Use one confirmation path

Add a small `SaleConfirmationService` with one public method that:

1. Locks the sale and its items.
2. Confirms that the sale is currently `draft` or being newly created.
3. Calls the existing `StockOutReleaseService` for every sale item.
4. Sets the final sale and receivable states in the same transaction.

Use this service from both `storeSale()` and `updateSale()` when a draft becomes confirmed. Do not duplicate the stock-out loop in both controller methods.

Keep the existing behavior:

- Sufficient garage stock creates released stock-outs and inventory movements.
- Insufficient stock creates a visible `prepared` stock-out without deducting inventory.
- Direct-depot fulfillment remains a later explicit release.

### 1.2 Block unsafe status edits

- Remove `cancelled` from the ordinary edit status selector.
- In `updateSale()`, reject transitions to `draft` or `cancelled` when any payment, stock-out, fulfilled quantity, or haul allocation exists.
- Keep `cancelSale()` as the only simple cancellation path.
- If the business later needs reversals, implement them as a separate audited workflow; do not silently reuse cancellation.
- Prevent a paid sale from being changed to any non-paid financial status.

### 1.3 Reconcile receivables from authoritative totals

- Put sale/receivable status calculation in one small service or shared method.
- Derive it from `SUM(sale_items.line_total)` and `SUM(payments.amount)`.
- Use the same calculation after sale confirmation, sale edits, and payments.
- Stop manually treating a cancelled paid sale as `unpaid`.

### 1.4 Remove customer payment-status drift

Simplest solution: stop using `customers.payment_status` as an editable financial fact. Display a derived customer status from aggregate outstanding receivables. Keep the column temporarily for compatibility, but do not use it for reporting or row colors.

### Tests

- Draft → confirmed with sufficient inventory creates exactly one inventory effect per physical release.
- Draft → confirmed without sufficient inventory creates a prepared record and no outgoing movement.
- Paid/partially paid/fulfilled sales cannot be cancelled or returned to draft.
- Editing harmless metadata does not create another stock-out.
- Multi-item sale confirmation is atomic: one failed item rolls back all sale and inventory changes.
- Receivable and sale statuses agree after zero, partial, and full payment.

### Primary files

- `app/Http/Controllers/SalesOfficerCustomerController.php`
- `app/Services/StockOutReleaseService.php`
- New `app/Services/SaleConfirmationService.php`
- `resources/views/sales-officer/sales.blade.php`
- Relevant sales, stock-out, payment, and integration tests

### Exit gate

- All sale-state regression tests pass.
- No controller path can produce a cancelled sale with retained payments or unreversed releases.
- Both new-sale confirmation and draft-sale confirmation use the same stock-out logic.

---

## Phase 2 — Add Database Guardrails and Reliable Duplicate Protection

**Priority:** High  
**Purpose:** Ensure invalid data cannot be created by concurrency or code paths outside the UI.

### 2.1 Lock the approved fuel catalog

- Add one small configuration list containing the four approved code/name pairs. Reuse it in seeders, runtime validation, selector queries, and `cjp:readiness-audit`; do not scatter new hard-coded arrays across controllers.
- Add a new idempotent data migration; do not edit the already-applied fuel migration. It must:
  - normalize names to exactly `F1`, `UNLEADED`, `DIESEL`, and `PREMIUM`;
  - activate those four codes;
  - deactivate every other fuel type without deleting historical rows;
  - ensure the required garage tanks exist for all four approved fuels.
- Remove both fuel-type creation route registrations, `storeFuelType()`, and the inventory officer's **Add Fuel Type** modal. A fixed catalog does not need CRUD.
- Restrict every new purchase, haul/lifting, stock-in, sale item, stock-out, filter, and AI request to an active approved fuel ID. A bare `exists:fuel_types,id` check is insufficient.
- Keep historical reports capable of displaying inactive referenced fuels, while new-record selectors show only the four approved fuels.
- Do not add a database enum or duplicate a fuel name on transaction rows; retain the existing foreign keys to `fuel_types`.

### 2.2 Align numeric limits with the schema

- Define realistic business maximums for liters, unit price/cost, and line total.
- Validate the calculated line total before insert/update.
- Prefer keeping the current decimals if the agreed limits fit. Increase precision only if the business genuinely needs larger values.
- Return validation errors instead of raw database exceptions.

### 2.3 Add simple check constraints

Create one new migration with constraints supported by the production MySQL version:

- Quantities, prices, costs, payments, and line totals must be non-negative; operational quantities must be greater than zero.
- `fulfilled_quantity_liters <= quantity_liters`.
- Garage released stock-outs require a storage location and no depot allocation.
- Direct-depot released stock-outs require a depot and haul allocation and no storage location.
- Garage allocations require a storage location; customer allocations require a customer.

Keep cross-row total checks in transactional services because SQL checks cannot safely express them.

### 2.4 Persist idempotency keys

Add one small `idempotency_keys` table with:

- `key` unique
- action type
- user ID
- result/reference ID
- timestamps

At the start of a write transaction, reserve the key. On repeat submission, return the stored result. Apply this first to sale creation, haul creation, payments, and stock-outs. The session key may remain as a UI optimization but must not be the integrity mechanism.

### 2.5 Improve code collision handling

Keep the existing readable random codes. Catch duplicate-key errors and retry generation a small fixed number of times. Do not build a separate sequence service unless real collisions occur frequently.

### Tests

- Concurrent/repeated requests with the same idempotency key create one record.
- Different users cannot reuse another user's key to retrieve or alter a result.
- Each approved fuel can pass through purchase, lifting, stock-in, sale, and stock-out validation.
- Non-approved and inactive fuel IDs are rejected on every operational write path.
- Rerunning the seeder or normalization migration leaves exactly the same four active fuel records and does not duplicate tanks.
- Maximum allowed totals fit the database; one step above is rejected by validation.
- Invalid conditional stock-out/allocation shapes fail at the database boundary.

### Exit gate

- Duplicate submissions are prevented by database-enforced keys.
- Only the four approved fuel types can be used for new operational records.
- Arbitrary fuel creation is no longer exposed through routes or UI.
- Controller validation cannot accept values that overflow the schema.
- Database constraints reject the most damaging impossible states.

---

## Phase 3 — Repair and Prove Operational Data

**Priority:** High  
**Purpose:** Make the turnover database capable of running the business workflow.

### Tasks

- Back up the database before any repair.
- Review the existing paid 50-liter sale that has zero fulfilled liters and no stock-out.
- Decide with the business whether it should be:
  - fulfilled through a valid stock-out,
  - retained as a prepared/unfulfilled order, or
  - reversed through an explicit correction record.
- Do not fabricate historical inventory movements without source documentation.
- Create validated production/staging master data:
  - exactly four active approved fuel records: `F1`, `UNLEADED`, `DIESEL`, and `PREMIUM`,
  - the required active garage tanks for each approved fuel,
  - at least one eligible hauling or mixed truck,
  - at least one approved active driver with driver profile,
  - verified depots and customers.
- Execute authenticated HTTP workflow runs covering all four approved fuels. Cover both garage and direct-depot fulfillment across those runs; do not require both fulfillment paths for every fuel unless the business actually operates both paths for every fuel.
- Use unique, clearly marked acceptance records and retain their references for sign-off.
- Reconcile after each workflow:
  - purchase ordered vs hauled,
  - haul vs allocation total,
  - garage allocation vs stock-in,
  - sale item vs released stock-outs,
  - garage stock-outs vs outgoing movements,
  - sale total vs payments and receivable balance.

### Exit gate

- No unexplained paid/confirmed unfulfilled sales.
- The live catalog contains exactly the four approved active fuels, with no selectable non-approved fuel.
- Every approved fuel has its required active garage tanks and a reconciled acceptance workflow.
- Truck and driver workflows are usable with real master data.
- Garage and direct-depot acceptance workflows reconcile exactly.
- `cjp:readiness-audit` exits successfully with no unaccepted warnings.

---

## Phase 4 — Production Security and Configuration

**Priority:** High  
**Purpose:** Remove deployment and account-management risks.

### Tasks

- Set `APP_URL` to the final HTTPS URL.
- Make the application timezone configurable and set it to `Asia/Manila`.
- Enable secure and encrypted session cookies in production.
- Verify trusted proxy configuration so HTTPS detection is correct behind a reverse proxy.
- Configure and test the real SMTP provider using a non-personal sender.
- Run `config:cache`, `route:cache`, and `view:cache` as deployment steps.
- Keep withdrawal receipts on the private disk and test authorization for valid and invalid roles.
- Stop public users from requesting the `admin` role. Default public registration to the least-privileged permitted role and let an admin assign staff roles.
- Prefer email-only login. If name login must remain, make names unique before relying on them as identifiers.
- Prefix CSV cells beginning with `=`, `+`, `-`, `@`, tab, or carriage return to prevent spreadsheet formula execution.
- Add a small security-header middleware for CSP, frame restrictions, referrer policy, permissions policy, and HSTS in HTTPS production.
- Re-run Composer and npm advisory audits in CI/deployment.

### Exit gate

- HTTPS URLs, cookies, timezone, email, and caches are verified in the deployed environment.
- Public registration cannot request administrator access.
- CSV formula-injection tests pass.
- Receipt authorization and security-header tests pass.

---

## Phase 5 — Performance and Maintainability

**Priority:** Medium  
**Purpose:** Keep the working system responsive without a broad rewrite.

### Tasks

- Add simple pagination to purchases, stock-in/out, sales, customers, users, ledger, and monitoring pages.
- Batch-load purchase withdrawal receipts instead of querying once per purchase row.
- Add query-count tests for the busiest list pages.
- Review `EXPLAIN` output on the main production-sized queries before adding indexes. Add only indexes proven useful.
- Split `InventoryOfficerPurchaseController` gradually into small workflow services:
  - `PurchaseService`
  - `StockInService`
  - existing `StockOutReleaseService`

  Keep view/read queries in the controller initially; do not create a repository layer solely for style.
- Keep `DashboardSummaryService` request-level memoization. Add shared caching only if profiling shows dashboard queries are too slow and define clear invalidation rules first.
- Remove obsolete code and terminology only after tests prove it has no active route or workflow use.

### Exit gate

- Large list pages are paginated.
- No known N+1 query remains on primary pages.
- Representative pages meet an agreed response-time target with production-sized data.
- Refactoring does not change financial or inventory outputs.

---

## Phase 6 — UI, Accessibility, AI, and Final Acceptance

**Priority:** Final turnover gate  
**Purpose:** Verify the system as users will operate it.

### 6.1 Browser acceptance

Run a manual browser script for every role:

- Admin
- Inventory officer
- Dispatch officer
- Driver
- Sales officer

Test desktop, tablet, and phone widths; keyboard-only operation; form errors; modal focus; uploads; CSV downloads; logout; and forbidden-route behavior.

For every purchase, lifting, stock-in, inventory, sale, stock-out, dashboard, report, and AI fuel selector/filter, verify:

- the labels are exactly `F1`, `UNLEADED`, `DIESEL`, and `PREMIUM`;
- no arbitrary **Add Fuel Type** control remains;
- inactive historical fuels are not selectable for new records;
- each selected fuel remains unchanged through the complete workflow and appears under the correct dashboard/report grouping.

### 6.2 AI acceptance

If AI is required for turnover:

- Store the key only in the deployment secret store.
- Run `php artisan ai:test-connection`.
- Verify timeout, rate-limit, invalid-key, malformed-response, and no-data fallbacks.
- Confirm AI output remains advisory and cannot change authoritative inventory or financial records.

If AI is optional, leave it disabled and document that dashboards and reports remain fully usable without it.

### 6.3 Final verification commands

```powershell
composer validate --strict
vendor\bin\pint --test
php artisan test
npm.cmd run build
composer audit --locked --no-interaction
npm.cmd audit --audit-level=moderate
php artisan migrate:status
php artisan cjp:readiness-audit
```

### Final turnover evidence

Retain:

- Passing command output
- Acceptance record references
- Database reconciliation results
- Fuel catalog and per-fuel workflow reconciliation results
- Per-role browser checklist
- Deployment configuration checklist with secrets redacted
- Business-owner sign-off on prepared stock-out and cancellation behavior

### Exit gate

The system may be declared **READY FOR TURNOVER** only when:

- Every automated check passes.
- Both end-to-end workflows pass through real routes.
- All four approved fuels pass catalog, validation, workflow, inventory, and reporting acceptance checks.
- No critical/high correctness or security issue remains.
- Live data reconciliation is clean.
- Production configuration is verified.
- Browser acceptance and business sign-off are complete.

## Recommended Implementation Order

1. Baseline and regression tests
2. Sale state and automatic stock-out fixes
3. Numeric limits, constraints, and idempotency
4. Live data reconciliation and operational acceptance data
5. Production configuration and security
6. Pagination and targeted refactoring
7. Browser, accessibility, AI, and final acceptance

Do not combine these into one large release. Phases 0–2 should be a correctness release; Phase 3 should be a controlled data/acceptance exercise; Phases 4–6 should prepare and verify the production turnover.
