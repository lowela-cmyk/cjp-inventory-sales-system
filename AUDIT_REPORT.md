# CJP Southern Star OPC — Inventory & Sales System Audit

- **Date:** 2026-10-12
- **Branch:** `audit`, created from `main` @ `bc3f889`. It also carries the earlier uncommitted revision work.
- **Scope:** audit only. No application code, schema or data was changed.

**Audit-only changes on this branch:**
- `larastan/larastan` dev dependency (`composer.json`, `composer.lock`) and `phpstan.neon`.
- `tests/Feature/Audit/*`
- This report.

**Environment:** PHP 8.5, Laravel 12, MariaDB 10.4.28 (XAMPP).

**Data safety:**
- Tests ran on in-memory SQLite (`RefreshDatabase`) and, separately, on a new empty MySQL database `cjp_audit_testing`.
- UI rendering used a copy of the local data in a new database, `cjp_audit_ui`.
- `cjp_inventory_sales_db` was only read.

---

## 1. Summary

| Severity | Count | IDs |
|---|---|---|
| CRITICAL | 3 | AUD-C01 – C03 |
| HIGH | 3 | AUD-H01 – H03 |
| MEDIUM | 13 | AUD-M01 – M13 |
| LOW | 11 | AUD-L01 – L11 |
| **Total** | **30** | |

### Test baseline

| Run | Result |
|---|---|
| Before audit, SQLite (`php artisan test`) | **398 passed, 2 failed** (`FunctionalTestingTest`, `Phase3OperationalAcceptanceTest`; both explained in AUD-M13) |
| Before audit, MySQL `cjp_audit_testing` | **397 passed, 3 failed** (the 2 above plus `Phase5PerformanceTest`, which depends on test order; see AUD-M13) |
| After adding audit tests (`php artisan test`) | **399 passed, 14 failed**: the 2 pre-existing failures plus the 12 intentional audit failures |
| Audit tests only (`php artisan test --group=audit`) | **12 failed, 1 passed**. Each failure reproduces one CRITICAL/HIGH finding. The passing test is a control proving the login limiter works when the IP is not spoofed. |
| Existing suite only (`php artisan test --exclude-group=audit`) | 398 passed, 2 failed (unchanged) |

### Other baseline checks

| Check | Result |
|---|---|
| `php -l` on all 48 files in `app/` | No syntax errors |
| `php artisan route:list` | 106 routes, 44 of them GET. Every role-scoped group has `auth` + `role:` middleware. |
| `npm run build` | Succeeds |
| Larastan level 5 | 65 errors: 29 unused methods, 30 generic/type-inference notes, 6 notable (AUD-L05, AUD-M02) |
| `composer audit` | 3 advisories in 2 packages (AUD-M11) |
| `storage/logs/laravel.log` | No recurring application errors. Every error comes from environment setup (missing APP_KEY, wrong DB name, Vite manifest), the audit harness, or the window before the `bank_name` migration ran. Tests write to the same log (AUD-L09). |
| VS Code / Intelephense | No warnings or errors, only hints (AUD-L10) |

### What came out clean

- **Routes & pages:** all 44 GET routes were hit as a guest and as each of the 5 roles. No 500s, blank pages, raw Blade output, or wrong redirects. Each role reaches only its own area. Guests are sent to `/login`. Logged-in users hitting `/login`, `/register` or `/` are sent to their own dashboard.
- **Authorization / IDOR:**
  - Drivers can only change status on, or upload receipts for, their own lifts (`DriverLiftingStatusController::haulForDriverUpdate`, `DriverDeliveryController::storeWithdrawalReceipt`).
  - Alert mark-read is limited to the alert types each role can see.
  - Admins cannot deactivate themselves or remove the last active admin.
  - Inactive or pending accounts are logged out on their next request (`EnsureUserHasRole`).
  - Passwords are hashed (`'password' => 'hashed'` cast).
- **CSRF:** default web middleware, with no exceptions configured.
- **XSS:** the only `{!! !!}` uses wrap `nl2br(e(...))`, which escapes first.
- **Mass assignment:** every `User::create` / `forceFill` call passes explicit, validated fields.
- **File upload:** withdrawal receipts are `image|mimes:jpg,jpeg,png,webp|max:5120`, stored on the private `local` disk, and served only to admin and inventory officers with `nosniff`.
- **Data integrity on local data:**
  - Inventory cards = stock levels = raw movement balances = ledger balances, per fuel and tank (62,000 L).
  - Sales and payment totals match the raw tables (PHP 2,305,900).
  - No orphan rows, no overpaid or over-fulfilled sales, no `line_total` mismatches, no lifts over truck capacity or purchase quantity.
  - All multi-table writes run inside `DB::transaction`.
  - Lift scheduling and truck reassignment enforce truck capacity and remaining purchase quantity.
- **UI sweep:** 20 pages × 3 widths (1280, 1440, 1920) = 60 renders, every tab clicked, one modal of each kind opened. No JS console errors, no page-level horizontal scroll, no elements beyond the viewport, no modal overflow. Dashboard charts were confirmed to draw, by reading canvas pixels.
- **Indexes:** every filtered or sorted column on `sales`, `purchases`, `hauls`, `payments`, `stock_outs`, `inventory_movements` and `alerts` is indexed.
- **Code markers:** no `TODO`/`FIXME`/`dd()`/`dump()`/`console.log` left in `app/`, `routes/`, `resources/js` or `resources/views`.

---

## 2. Findings

Severity scale:
- **CRITICAL:** data loss, wrong money or inventory numbers, a security hole, or a crash in a core flow.
- **HIGH:** a broken feature or wrong role access.
- **MEDIUM:** validation gaps, inconsistent data, edge cases.
- **LOW:** polish, code quality, warnings.

| ID | Severity | Area | Page/Route | Description | Steps to reproduce | Evidence | Suggested fix |
|---|---|---|---|---|---|---|---|
| AUD-C01 | CRITICAL | Core flow / inventory | `dispatch.fuel-lifting.hauls.store`, `…hauls.status` → `inventory-officer.inventory` | **Fuel lifted through the app can never become stock.** Scheduling and completing a lift never creates a `haul_allocations` row (destination garage/client). Stock-In needs a garage allocation, and direct depot releases need a client allocation, so neither can happen for new purchases. Once the seeded garage stock (62,000 L) runs out, every "Delivered" sale fails with *"Insufficient available garage inventory"*. | 1. Record a purchase. 2. Schedule a lift. 3. Driver: In Transit → Lifted. 4. Dispatch: Completed. 5. Inventory → no pending garage receipt, and nothing can be stocked in. | `DispatchDeliveryController.php:68` (store inserts `lifting_schedules` + `hauls` only). `DispatchLiftingStatusController::updateStatus` creates nothing. Allocations are only inserted by `OperationalDataRepairService.php:648,886,901` and `WorkflowSmokeTestService.php:318`. Existing tests hide this by inserting allocations in fixtures (`DispatchLiftingStatusManagementTest::haul()`). The creating code was removed with the delivery module in `542e982`. Test: `tests/Feature/Audit/C01LiftCompletionReceivableStockTest.php`. | Create a `planned` allocation per haul when scheduling. Add a destination choice to Schedule Lift: Garage (default) or Client sale. Garage allocations then appear as pending receipts once the lift completes; client allocations feed direct depot releases. Add a one-off command to backfill completed hauls that have no allocation. |
| AUD-C02 | CRITICAL | Security / auth | `password.update` | **Password-reset codes can be brute-forced.** The code is 6 digits, valid for 15 minutes, with no per-code attempt limit. The only throttle is 5/min keyed by `email\|IP`, and the IP comes from a client-controlled `X-Forwarded-For` because all proxies are trusted. Rotating the header gives unlimited guesses, so accounts (including admin) can be taken over. | Request a code, then POST wrong codes with a different `X-Forwarded-For` each time: never 429. | `AuthController.php:71` (`random_int(100000, 999999)`), `:226` (15-min expiry, no attempt count). `AppServiceProvider.php:38-42`. `bootstrap/app.php:16` (`trustProxies(at: '*')`). Test: `C02PasswordResetBruteForceTest.php`. | Trust only the real proxy IPs, or none locally. Key the reset limiter by email alone, plus a global cap. Count failed attempts per code and delete the code after 5 failures. Consider longer alphanumeric codes. |
| AUD-C03 | CRITICAL | Security / secrets | repository | **A full DB dump is committed** to a repo with a GitHub remote: `database/backups/pre_phase3_backup_20260916.sql` (109 KB). It contains all users with emails, phone numbers and **bcrypt password hashes**, plus a `sessions` table dump. | `git ls-files database/backups` | Committed in `7aeb007`. `origin` = `github.com/lowela-cmyk/cjp-inventory-sales-system`. Test: `C03CommittedDatabaseDumpTest.php`. | Remove the file and add `database/backups/` and `*.sql` to `.gitignore`. **Purge it from history** (`git filter-repo`) and force-push, which needs the repo owner. Rotate every account password. Confirm whether the repo is public. |
| AUD-H01 | HIGH | Security / auth | `login.store` | **Login rate limit bypass.** The 5/min limiter is keyed by `username\|IP`, with the IP taken from a spoofable `X-Forwarded-For`, so password guessing is unlimited. The default policy is only min-8 characters (AUD-M12). | 8 wrong logins for one account, each with a different `X-Forwarded-For`: no 429. Without the header, the 6th attempt gets 429 (control test passes). | `bootstrap/app.php:16`, `AppServiceProvider.php:26-30`. Test: `H01LoginThrottleSpoofTest.php`. | Same root cause as C02: restrict trusted proxies, and add a per-username limiter not keyed by IP. |
| AUD-H02 | HIGH | Sales | `sales-officer.sales.update`, `admin.sales.update` | **A fully paid sale cannot be edited.** The Edit modal's Status select only offers draft/confirmed/partially_paid/unpaid. For a `paid` sale the browser submits `draft`, and the server rejects it: *"cannot be changed to draft or cancelled"*. Changing just the Sales Order No., due date or payment method is impossible. | Record a sale paid in full → View → change Sales Order No. → Edit: error. | `SalesOfficerCustomerController.php:60`, `AdminMonitoringController.php:322` (`editableSaleStatuses`). Both sales views' status `<select>`. `updateSale` dependency check. Test: `H02PaidSaleEditTest.php` (both roles). | Status is derived from payments by `reconcile()`. Show it read-only for paid sales, and ignore a submitted status on sales with payments. |
| AUD-H03 | HIGH | UI / filters | `admin.sales`, `sales-officer.sales`, `admin.fuel-lifting`, `admin.alerts`, `admin.ledger`, `inventory-officer.ledger` | **Placeholder filters that don't filter.** "Date / Status / Depot / Location / Company / Fuel Type (All)" are unnamed submit buttons that only reload the page with the search text. This is the same defect fixed on Inventory in the last round. | Click "Date" or "Depot" on any listed page: nothing filters. | `admin/sales.blade.php:29-31,70-71`, `sales-officer/sales.blade.php:50-52,116-118`, `admin/fuel-lifting.blade.php:23-25,34-36`, `admin/alerts.blade.php:6`, `admin/ledger.blade.php:12-14,41-44`, `inventory-officer/ledger.blade.php:19-21,48-50`. Test: `H03PlaceholderFilterButtonsTest.php` (6 cases). | Replace them with real auto-applying controls, reusing `inventory-officer/partials/filter-controls`, and add matching query parameters to each controller. |
| AUD-M01 | MEDIUM | Robustness | `login.store`, `register.store`, `password.update`, `*.trucks.store/update` | **500 errors on array-valued input.** The rate-limiter key and truck input normalisation cast `$request->input()` to string before validation runs (`Array to string conversion`). The auth endpoints are unauthenticated, so this means log spam and error pages. | POST `/login` with `username[]=x`: 500. | `AppServiceProvider.php:27,33,39`, `TruckController.php:147-149`. Found by the write-route fuzz probe. | Use `is_string($v) ? $v : ''` before lowercasing or trimming. |
| AUD-M02 | MEDIUM | Security / UX | Sales, Dispatch, Inventory writes | **Raw SQL errors are shown to users.** `QueryException` extends `RuntimeException`, so every `catch (\RuntimeException $e) { withErrors([... $e->getMessage()]) }` puts the SQLSTATE text, with table and column names, into a flash message, even with `APP_DEBUG=false`. It also makes the friendly `catch (QueryException)` branch dead code. | Force a DB constraint error during sale save. | `SalesOfficerCustomerController.php:292` (Larastan: dead catch at `:296`), same pattern at `:165,:417`, `DispatchDeliveryController.php:185`, `storeStockOut`. | Catch `QueryException` first (or rethrow it), log it, and show a generic message. Use a dedicated domain exception for business-rule errors. |
| AUD-M03 | MEDIUM | Validation | purchases, sales, payments, stock-in/out, lifts | **Dates are unbounded.** `purchase_date`, `sale_date`, `payment_date`, `movement_date`, `stock_out_at` and `scheduled_at` accept any date (e.g. 1900 or 2099). A payment can predate its sale, and a stock-in can be in the future. This skews the "Today" cards, overdue status and reports. | Record a payment dated 2099-01-01. | `SalesOfficerCustomerController.php:892,931`, `InventoryOfficerPurchaseController.php:243,268,337`, `DispatchDeliveryController.php:79`. | Add bounds (`after_or_equal:2000-01-01`, `before_or_equal:today` for actuals; `payment_date >= sale_date`). Allow future dates only for lift scheduling. |
| AUD-M04 | MEDIUM | Money | `*.sales.payments.store` | **Cheque and bank reference numbers aren't unique.** The same cheque number can be recorded on two sales, or twice on one sale with a different amount or date. The duplicate check needs date, amount, method and reference all to match. | Record two payments with reference `CHK-1` on different sales. | `validatedPaymentData` (`reference_number` is only `max:100`), `duplicatePaymentExists`. | Make `(method, reference_number)` unique for cheque/bank_transfer. Check existing data for duplicates first. |
| AUD-M05 | MEDIUM | Data consistency | `*.inventory.purchases.update/cancel` | **One cancelled lift blocks purchase corrections forever.** `hasDependentActivity` counts hauls of any status, including cancelled ones. | Schedule a lift, cancel it, then try to edit the purchase quantity: rejected. | `PurchaseService::hasDependentActivity` (`hauls` exists check). | Exclude `status = 'cancelled'` hauls. |
| AUD-M06 | MEDIUM | Inventory | `*.inventory.stock-out.store` | **The manual "+ Record Stock-Out" form is effectively unusable.** It only lists delivered sales with unfulfilled quantity, but marking a sale Delivered releases the full quantity atomically or fails. The depot option also needs a client allocation (C01). | Open + Record Stock-Out on real data: the Sale/Item list is empty. | `stockOutSaleItemOptions()`, `SaleConfirmationService::updateDeliveryStatus` (`allowPreparation = false`). | Decide its purpose (corrections / partial releases), or remove it. Revisit after C01. |
| AUD-M07 | MEDIUM | Performance | `admin.sales` | **N+1 queries on an unpaginated page.** About 8 queries per sale: 48 queries with 5 sales, 248 with 30. At 1,000 sales that is about 8,000 queries per page load. | Query log while loading `/admin/sales`. | `AdminMonitoringController::salesRows` `:464-580` (`latestPaymentDateForSale`, `itemsForSale`, `itemPriceSummary` ×2, `fulfilledQuantityForSale`, `paymentsForSale`, `paymentSchedulesForSale`, `correctionMode` per row). | Reuse the batched loaders from `SalesOfficerCustomerController::salesRows` and paginate (25 per page). |
| AUD-M08 | MEDIUM | Portability | `admin.dashboard`, `admin.reports`, AI insights | **`CAST(... AS INTEGER)` only works on SQLite/MariaDB.** Oracle MySQL 8 rejects it, so the dashboard, reports and AI-insight queries would throw if the host moves to MySQL. | Run the dashboard against MySQL 8. | `DashboardSummaryService.php:654,674,744,760`, `AIDataPreparationService.php:457,473`. | Group in PHP, or use a driver-aware expression (`MONTH()` / `YEAR()` on MySQL/MariaDB, `strftime` on SQLite). |
| AUD-M09 | MEDIUM | Reporting | `admin.ledger` (Transaction) | **No screen reconciles stock-in − stock-out per tank.** Ledger > Transaction is a purchase→lift ledger, so stock-outs and receipts never appear there, and Stock Out quantities can't be traced in the Ledger. | Deliver a sale and look for it in Ledger > Transaction. | `InventoryLedgerService::rows`. | Add a "Movements" tab listing `inventory_movements` + depot `stock_outs` with a running balance per tank and fuel. |
| AUD-M10 | MEDIUM | Inventory cards | `*.inventory` | **STOCK-OUT TODAY counts garage releases only**, while the Stock Out tab also lists direct depot releases. An existing test (`AnalyticsTestingTest`) deliberately asserts garage-only, so this needs a product decision, not a code fix. | Release from depot today: the tab shows it, the card doesn't. | `DashboardSummaryService::stockMovementLiters('out')`. | Either relabel the card "Released from garage today", or count released `stock_outs`. |
| AUD-M11 | MEDIUM | Dependencies | — | `composer audit`: `league/commonmark` ≤2.10.1, HIGH (quadratic-time DoS in GFM) and MEDIUM (DisallowedRawHtml bypass). `laravel/framework` <12.69, LOW (XSS on debug page). | `composer audit` | `composer.lock` | `composer update laravel/framework league/commonmark`, then rerun the suite. |
| AUD-M12 | MEDIUM | Security | auth | **Weak password policy:** `Password::defaults()` was never configured, so the only rule is min 8 characters. A password reset doesn't log out other sessions. | Register with `password1`. | `AuthController::register/resetPassword`; no `Password::defaults()` in `AppServiceProvider`. | Set `Password::min(10)->letters()->numbers()->uncompromised()`. After a reset, rotate `remember_token` and delete the user's DB sessions. |
| AUD-M13 | MEDIUM | Tests | suite | **The baseline is permanently red, which hides regressions.** `FunctionalTestingTest` is a time bomb: due date 2026-09-30, no fixed clock, and now "overdue". `Phase3OperationalAcceptanceTest` reads the real `.env` (`APP_ENV`/`APP_DEBUG`). `Phase5PerformanceTest::test_purchase_service_methods_directly` passes a purchase id where a purchase-item id is expected, so it fails on MySQL depending on test order. | `php artisan test`; for the MySQL case, run the suite on MySQL. | Tests named; readiness output: `FAIL APP_ENV is production`, `FAIL APP_DEBUG is false`. | Add `Carbon::setTestNow`. Set the env in the test (`config([...])`). Pass `$item->id`. |
| AUD-L01 | LOW | Config | `.env` | `APP_DEBUG=true`, `APP_ENV=local`, `LOG_LEVEL=debug`, `SESSION_SECURE_COOKIE=false`. Fine for development, but they must change in production (the readiness audit flags them). | — | `.env` | Use a production env template, and enforce it with `cjp:readiness-audit --turnover` at deploy. |
| AUD-L02 | LOW | Export | paginated tables | The Export button exports only the current page (25 rows) of paginated tables: Inventory tabs, Sales Officer sales and customers. Rows hidden by the live search are now excluded. | Export Purchases with more than 25 records. | `app.js` `exportVisibleTable`. | Add server-side CSV exports that reuse each table's query and filters, as User Management already does. |
| AUD-L03 | LOW | Filters | Stock Out | The Depot filter can't match garage stock-outs, because pooled tanks have no single source depot. They are still reachable by search (tank name), Date, Fuel and Status. | — | `stockOutRows` | Optionally add a Garage/Tank filter. |
| AUD-L04 | LOW | Dead code | — | 29 unused private methods. `AdminMonitoringController::inventory()`/`stockOutRows()` and `resources/views/admin/inventory.blade.php` have no route. The driver `no-schedule`/`no-hauled` routes are aliases. `$purchaseDepotIds` is unused. | Larastan `method.unused` | `InventoryOfficerPurchaseController.php:793-1156`, `SalesOfficerCustomerController.php:1248-1636`, `AdminMonitoringController.php:170,674,869,929`, `AdminDashboardService.php:163-183`, `InventoryLedgerService.php:244` | Delete them. |
| AUD-L05 | LOW | Static analysis | — | Larastan level 5 notable items: `AuthController.php:266` unhandled `match` (unknown role → `UnhandledMatchError` 500); `User.php:63` and `SalesOfficerCustomerController.php:411` always-true comparisons; `AdminUserManagementController.php:401` undefined property `driver_code`; `WorkflowAlertService.php:45` needless nullsafe. Also 30 collection-generic notes. | `vendor/bin/phpstan analyse` | `phpstan.neon` | Fix the notable items, then add a Larastan baseline and run it in CI. |
| AUD-L06 | LOW | SQL hygiene | stock-out writes | Floats are concatenated into `DB::raw` (`fulfilled_quantity_liters + '.$quantity`). They're typed floats, so not injectable, but fragile. | — | `InventoryOfficerPurchaseController.php:720,722`, `StockOutReleaseService.php:153,303` | Use `->increment('fulfilled_quantity_liters', $quantity)` and bound comparisons. |
| AUD-L07 | LOW | Repo hygiene | repository | `vendor.zip` (37 MB) and `graphify-out/` (485 files, 39 MB) are committed. | `git ls-files` | — | Remove them and add them to `.gitignore`. |
| AUD-L08 | LOW | Auth UX | `register.store`, `password.email` | Unknown requested roles silently become `driver`. If the reset mail fails, the error reveals that the account exists (enumeration). | — | `AuthController.php` register / sendPasswordResetCode | Validate `role` with `Rule::in`. Return the same generic message, and log the mail failure. |
| AUD-L09 | LOW | Logging | tests | The test suite writes to the app's `storage/logs/laravel.log` (AI/SMTP warnings), which hides real errors. | — | `phpunit.xml` (no `LOG_CHANNEL`) | Set `LOG_CHANNEL=null` (or a separate file) in `phpunit.xml`. |
| AUD-L10 | LOW | IDE | — | Intelephense shows hints only: unused functions, `in_array`/`is_array` global-namespace optimisation, one unused variable. No warnings or errors remain (the two earlier warnings were fixed). | VS Code Problems panel | — | Cleared by AUD-L04, or with `\in_array` imports. |
| AUD-L11 | LOW | Scalability | lift and ledger lists | `admin.fuel-lifting`, `dispatch.fuel-lifting`, `driver.fuel-lifting` and the ledgers are unpaginated. Query counts are flat (no N+1), but row counts grow without limit. | — | Controllers listed | Paginate, or add a date window. |

---

## 3. Phased fix plan

Each phase is independent and can ship on its own. Severity order is followed, except H01, which moves into Phase 1 because it shares one root cause and one fix with C02.

### Phase 1 — CRITICAL (security, inventory flow)

| Item | Content |
|---|---|
| Findings | AUD-C01, AUD-C02, AUD-C03, AUD-H01 (same root cause as C02) |
| Files | `bootstrap/app.php` (trusted proxies), `app/Providers/AppServiceProvider.php` (limiters), `app/Http/Controllers/AuthController.php` (reset attempt counter, invalidate on failure), `app/Http/Controllers/DispatchDeliveryController.php` + `resources/views/dispatch/fuel-lifting.blade.php` (destination per lift, create `planned` allocations), new artisan command to backfill allocations for completed hauls, `.gitignore`, removal of `database/backups/*.sql` |
| Migrations | None. `haul_allocations` already exists; the reset attempt count can live in the cache. |
| Risk | **Medium.** C01 changes the core lifting flow and needs a decision: default destination Garage, with an optional Client sale. C03 needs a history rewrite and force-push by the repo owner, plus password rotation. |
| Proof | `php artisan test --group=audit` → C01, C02, C03 and H01 pass. The full suite stays at the baseline. New end-to-end test: purchase → lift → complete → stock-in → sale delivered from that stock. |
| Effort | About 2–2.5 days: C01 1.5 days, C02/H01 0.5 day, C03 2 hours plus owner coordination |

### Phase 2 — HIGH (broken features)

| Item | Content |
|---|---|
| Findings | AUD-H02, AUD-H03 |
| Files | `SalesOfficerCustomerController.php` (`updateSale`, `editableSaleStatuses`), `AdminMonitoringController.php` (sales, alerts, fuel lifting filters), `resources/views/{admin,sales-officer}/sales.blade.php`, `admin/fuel-lifting.blade.php`, `admin/alerts.blade.php`, `admin/ledger.blade.php`, `inventory-officer/ledger.blade.php`, `InventoryLedgerService.php` (filter params) |
| Migrations | None |
| Risk | Low to medium: UI and query filters only, no data changes |
| Proof | `H02PaidSaleEditTest`, `H03PlaceholderFilterButtonsTest` (6 cases) pass. Add per-page filter tests (include/exclude) like `SystemAnalysisRevisionsTest`. |
| Effort | About 1.5–2 days |

### Phase 3 — MEDIUM (validation, consistency, edge cases)

| Item | Content |
|---|---|
| Findings | AUD-M01 – M13 |
| Files | `AppServiceProvider.php`, `TruckController.php` (M01). Controllers catching `RuntimeException` (M02). Validation rules in Sales/Inventory/Dispatch controllers (M03, M04). `PurchaseService.php` (M05). Stock-out form (M06, after Phase 1). `AdminMonitoringController::salesRows` + `admin/sales.blade.php` pagination (M07). `DashboardSummaryService.php`, `AIDataPreparationService.php` (M08). New ledger Movements tab (M09). Card label/logic per decision (M10). `composer.lock` (M11). Password policy + session invalidation (M12). The 3 unreliable tests (M13). |
| Migrations | **One**, for M04: a unique index on `payments(method, reference_number)` for cheque/bank_transfer. A generated column or a partial validation rule may be needed on MariaDB. Check existing duplicates before applying. |
| Risk | Medium. M04 and M03 can reject data users enter today, so communicate the new rules. M10 needs a product decision. |
| Proof | New tests per item: array-input fuzz returns 302/422 not 500; date bound and reference uniqueness tests; purchase editable after a cancelled lift; `/admin/sales` query count ≤ 15 regardless of row count; the suite runs on MySQL with 0 order-dependent failures. Baseline becomes **fully green**. |
| Effort | About 3–4 days |

### Phase 4 — LOW (cleanup, performance, polish)

| Item | Content |
|---|---|
| Findings | AUD-L01 – L11 |
| Files | Dead methods and views (L04), Larastan notable items + baseline (L05), `DB::raw` → `increment` (L06), `.gitignore` for `vendor.zip` and `graphify-out/` (L07), auth UX (L08), `phpunit.xml` log channel (L09), server-side exports (L02), optional Garage filter (L03), pagination on lift and ledger lists (L11), production env template (L01) |
| Migrations | None |
| Risk | Low |
| Proof | Larastan level 5 clean with a baseline, the suite green, the UI sweep re-run (60 renders), and export row count = filtered query row count |
| Effort | About 1.5–2 days |

---

## 4. How this audit was run

- **Static checks:** `php -l`, `route:list`, `npm run build`, Larastan level 5 (`phpstan.neon`), `composer audit`, log scan, Intelephense diagnostics.
- **Route/role matrix:** a temporary probe test seeded the DB, persisted the `WorkflowSmokeTestService` dataset, and ran the repair service. It then requested every named GET route as a guest and as each of the 5 roles, recording status, redirect target, blank responses and unrendered Blade.
- **Write-route fuzz:** every POST/PATCH route was called as its own role with negative, zero, `1e309`, invalid-date, array, 10,000-character, `<script>` and SQL-quote payloads, looking for 5xx responses.
- **Business-flow review:** code reading of purchase → lift → stock-in → sale → payment → stock-out → cancellation, cross-checked against the existing feature tests and their fixtures.
- **Data checks:** read-only SQL on the local DB (orphans, overpayment, line totals, capacity, over-lifting, foreign keys), plus service-level reconciliation of cards, stock levels, ledger and raw movements.
- **UI sweep:** server-rendered HTML from the `cjp_audit_ui` copy, loaded in headless Chrome at 1280/1440/1920. Checks covered console errors, page overflow, off-viewport elements, clipped controls, every tab and one modal of each kind. Screenshots were reviewed manually at 1280.
- **Performance:** query counts per list page with 5 vs 30 rows.
- **Not done:** live login through a real browser session (pages were rendered server-side instead). The Reports CSV was spot-checked (it generates, and the summary matches) but not reconciled row by row.
