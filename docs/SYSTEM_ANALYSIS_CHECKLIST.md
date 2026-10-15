# System Analysis Compliance Checklist

Compliance check of the CJP Southern Star OPC system against the "System Analysis" review document, Parts 1–7 (Sept 6 – Oct 14, 2026).

- **Date:** 2026-10-15
- **Code checked:** `main` @ `80aef6f`
- **Branches:** the audit is on `audit/system-analysis-compliance`. Fixes are on `fix/compliance-phase-1` … `fix/compliance-phase-4`, each branched off the previous one.
- **Scope:** audit only. No application code was changed.

## How it was checked

**Setup:**
- `composer install` and `npm run build` succeeded.
- `php artisan migrate` had nothing to run on either database (both already at `2026_10_17_000001`).

**Data safety:**
- Browser checks ran against `cjp_audit_ui`, served on `127.0.0.1:8001`.
- On `cjp_inventory_sales_db`, only `SELECT` queries were run.

**Changes made to the audit copy (`cjp_audit_ui`) only:**
- Added one login per role (`audit-<role>@example.test`) and a driver profile `DRV-AUD`.
- Raised the `F1 Tank 1` and `F1 Tank 2` capacity to 1,000,000 L, so repeated flow runs had tank space.
- The flow runs created purchases, lifts, sales and payments.

**Browser checks:**
- Playwright drove a responsive sweep of every page each role can open, at 375 / 768 / 1366 / 1920 px. That is 144 renders in Google Chrome.
- An end-to-end business flow (35 checks) ran in Chrome, Firefox and WebKit.
- Targeted scripts checked login/logout, sign-up → approval, red dots, filters and logo clipping.
- Screenshots are in `docs/screenshots/compliance/`. They are git-ignored locally and not committed.

**Tests:**
- `php artisan test` on in-memory SQLite: **437 passed, 8 failed**. See "Test suite" below.

**Where a later Part changed an earlier request, the later Part wins.** These rows are marked *superseded* and checked against the final behavior.

## Checklist

| ID | Part | Requirement | Status | Evidence | Notes |
|---|---|---|---|---|---|
| 1.1 | 1 | Login CJP logo never cut off | ✅ DONE | `1.1-login-{375,768,1366,1920}.png` | `object-fit: contain`; whole mark visible at every width. |
| 1.2 | 1 | Bottom-left banner only after sign-out, dismissible | ✅ DONE | `1.2-logout-toast.png`; `FrontendBugFixesTest::test_login_uses_username_password_form_and_renders_logout_only_toast` | No toast on a fresh login page. Appears after Logout and closes with ×. Gone after reload. |
| 1.3 | 1 | "Account ready" check icon centered in its circle | ✅ DONE | auth script: icon box 70×44, image inset 7 px on every side | The red circle + check was replaced by the CJP mark inside the toast. It is centered. |
| 1.4 | 1 | Sign-up has a Role field; Admin only approves | ✅ DONE | `1.4-signup-pending-chrome.png`; `resources/views/auth/register.blade.php:33` | Driver / Dispatch / Inventory / Sales. Admin is blocked server-side (`AuthController.php:177`). Account Requests shows the role; Approve worked. |
| 1.5 | 1 | Add Purchase: Fuel Type selectable | ✅ DONE | flow 1.5/1.6; `flow-*-1.5-add-purchase.png` | Lists the 4 active fuels. |
| 1.6 | 1 | Add Purchase: Depot selectable | ✅ DONE | flow 1.5/1.6 | Saved F1 + Batangas Energy Terminal. |
| 1.7 | 1 | No Delivery Receipt upload in Add Purchase | ✅ DONE | flow 1.7; `PurchaseReceiptUploadTest::test_purchase_form_rejects_receipt_upload_tampering` | |
| 1.8 | 1 | Admin can add/edit/manage customers and sales; no "managed in Sales Officer module" notice | ✅ DONE | `CompliancePhase2Test::test_admin_manages_customers_from_user_management_with_their_real_codes`; `phase2/um-customers-*.png`, `phase2/um-add-customer-1366.png` | Admin has the full Sales page. **Phase 2:** User Management → Customers now uses real Add and Edit forms (Bank Name and Status included). They post to the existing validated `admin.sales.customers.*` routes and return to the Customers tab; errors reopen the modal. The dead-end notice is gone. The table, search and CSV export use the stored `customer_code` (previously `CSM-` + row id). The page heading follows the active tab. Browser: codes match the DB at 375/768/1366/1920; add and edit verified. |
| 1.9 | 1 | No big empty spaces; tables/content fill the width | ✅ DONE | `CompliancePhase3Test::test_purchases_sales_and_stock_out_stack_related_values_without_dropping_any`; `phase3/1.9-purchases-1366.png`, `phase3/1.9-sales-1366.png`, `phase3/1.9-stock-out-1366.png`; `phase3/phase3b.json`; `phase3/export-*.csv` | **Phase 3, option (b) as approved: stacked cells.**<br>**Purchases:** Purchase-ID over Date; Purchased / Hauled / Received; Cost/Liter over Total Cost; Inventory / Purchase / Payment status in one column (header order); Receipt column unchanged.<br>**Sales:** Order-ID / SO No. / Date; Customer over Company; Fuel / Qty / Price per liter; Total / Paid / Balance (Balance red when above 0); Due / Last paid; Delivery over Receivable status; View and Cancel stacked.<br>**Stock-Out:** Order-ID over Date; Customer over Company; Fuel over Qty; Cost/Unit over Total Cost; Price/Unit / Total Price / Total Paid; Margin over Realized Profit (tooltips kept).<br>Every line except names, depots and sources is `white-space: nowrap`, so IDs and amounts never break. Browser: **0px sideways scroll at 1366 and 1920** for all three tables; 0 broken ID or amount lines at every width. Below 1366 they scroll inside the frame with the solid sticky Actions column, as allowed. **Export:** the Export button writes one value per column (15 columns on Stock-Out, matching the header), with no labels in the values. |
| 1.10 | 1 | No overlapping buttons; consistent spacing and sizes | ✅ DONE | `CompliancePhase3Test::test_sticky_action_cells_always_paint_an_opaque_background`; `phase3/1.10-sticky-row-1366.png`; `phase3/phase3.json` | **Phase 3:** the sticky Actions cell is opaque. It is white by default and keeps the row tint (`app.css` sticky block), so nothing shows through between the buttons. Browser: 0 transparent sticky cells and 0 control overlaps on 9 pages × 4 widths. Remaining: the Purchases status pill still sits under the Actions column at 1366px until you scroll sideways; that is part of 1.9. |
| 1.11 | 1 | No empty profile circle top-right | ✅ DONE | sweep `avatar: false` on all 144 renders; `components/admin/header.blade.php` | |
| 1.12 | 1 | Sidebar logo never cut off | ✅ DONE | `1.12-sidebar-logo-{768,1366}.png` | At ≤768 px the sidebar is a drawer (hamburger); the logo is intact inside it. |
| 1.13 | 1 | No hardcoded dummy data in alerts/ledger | ✅ DONE | grep of `app/`, `resources/` (only `OperationalDataRepairService`); `InventoryLedgerTest::test_view_transactions_modal_displays_all_lift_blocks_and_contained_details_from_database` | Alerts and ledger read only from the DB. Demo customers/depots come from `DatabaseSeeder` → `OperationalDataRepairService::seedMasterData()`, so they are DB rows, not view code. The fabricated customer IDs are listed under 1.8. |
| 1.14 | 1 | Driver uploads receipt pictures + optional notes; shown in Inventory > Purchases | ✅ DONE | flow 1.14 / 7.1; `PurchaseReceiptUploadTest` (5 tests) | Upload unlocks once the lift is Lifted; it can be replaced. |
| 1.15 | 1 | Exactly 4 fuel types: F1, Unleaded, Premium, Diesel | ✅ DONE | `FuelCatalogRegressionTest` (5 tests); DB: 4 active fuel types (main and audit) | 4 legacy fuel types are kept as *inactive* for history; validation rejects them. |
| 1.16 | 1 | 2 garage tanks per fuel (8); leftover fuel goes to the tanks | ✅ DONE | DB: 8 active garage tanks, 2 per fuel (main and audit); flow 7.3 | 8 legacy tanks for the inactive fuels are inactive. |
| 2.1 | 2 | Same greeting format for every role (login toast only; no inline banners) | ✅ DONE | `CompliancePhase3Test::test_success_messages_are_toasts_only_on_every_role_page`; `phase3/2.1-toast-only-*.png` | **Phase 3:** the 12 inline `.admin-flash` / `.driver-notice` banners in 6 views were removed. Success and error messages appear once, as a toast, on every role's pages; field errors stay next to their fields. Browser: a real save shows exactly 1 toast and 0 banners at 375/768/1366/1920. |
| 2.2 | 2 | Depots have their own Inventory tab | ✅ DONE | `chrome-1366-inventory_inventory-officer_inventory_depots.png` | Add Depot lives here. |
| 2.3 | 2 | Sales automatically become Stock-Out | ✅ DONE | see 4.14 | **C1 resolved (Phase 1):** stock-out is automatic and posts on delivery, as approved. |
| 2.4 | 2 | Price × liters shows the total automatically; payments reduce the balance | ✅ DONE | flow 2.4 (1,000 L × 65 → "65,000.00"; balance after 30,000 + 5,000) | |
| 2.5 | 2 | Ledger updates from lifts (remaining quantity and status derived) | ✅ DONE | flow 2.5; `InventoryLedgerTest` (5 tests) | Fully lifted purchases drop off the Ledger tab and stay in Transactions. |
| 2.6 | 2 | Ledger > Transactions holds all purchases incl. lifts | ✅ DONE | `InventoryLedgerTest::test_ledger_shows_active_purchase_progress_and_transactions_keep_zero_lift_purchases` | |
| 2.7 | 2 | "View" → View Transactions modal: ID, status, bar split per lift | ✅ DONE | `flow-*-2.8-ledger-hover.png` | |
| 2.8 | 2 | Hover a segment → Lift-ID + Date Lifted | ✅ DONE | flow 2.7/2.8 (opacity 0→1, "Lift-ID … Date Lifted …") | Also on keyboard focus; always shown on touch screens. |
| 2.9 | 2 | 4 fuel types | ✅ DONE | = 1.15 | |
| 3.1 | 3 | Purchase status automatic (no manual Status dropdown) | ✅ DONE | flow 3.1; `inventory.blade.php` Add modal shows a fixed "Pending" pill | |
| 3.2 | 3 | Creating a purchase creates an alert | ✅ DONE | flow 3.2 (Inventory Officer and Admin alerts list the new purchase); `PurchaseService.php:72`; `flow-*-3.2-alert-created.png` | `cjp_inventory_sales_db` has 0 alerts only because its 4 purchases were seeded, not created through the app. Phase 1: alert bodies now state their own event ("Status: Received" on a Received alert), so they no longer show a lagging purchase status (`CompliancePhase1Test::test_purchase_alert_messages_state_their_own_event`). Alerts created before the fix keep their old text. |
| 3.3 | 3 | Every status has a color everywhere | ✅ DONE | `CompliancePhase3Test::test_every_status_has_a_colour`; `phase3/3.3-account-requests-1366.png` | **Phase 3:** colors added for confirmed and info (blue), planned (pending), maintenance and warning (amber), and rejected (red). Refund due is amber. Browser: 0 gray pills across inventory, sales, dispatch, driver, user management, account requests and alerts at all four widths. |
| 3.4 | 3 | Driver "location" = pickup depot | ✅ DONE | flow 3.4/3.5; `flow-*-3.4-driver-row.png` | Columns are "Pickup Depot" and "Depot Address". |
| 3.5 | 3 | Second lift on the same purchase works; source = purchase's depot | ✅ DONE | flow (lifts on purchases that already had lifts); `LiftSchedulingFlowTest::test_dispatch_creates_lift_assignments_under_the_same_purchase_and_blocks_invalid_quantities` | No free text is stored as the source. |
| 3.6 | 3 | No free-text Location in Schedule Lift; depot-derived | ✅ DONE | flow 3.6 ("Pickup Depot: Batangas Energy Terminal — Shell Tabangao, Batangas City") | |
| 3.7 | 3 | Several purchase IDs on one lift ("+ Add Purchase") | ✅ DONE | flow 3.7 (one schedule, 2 purchases × 20,000 L on a 40,000 L truck); `flow-*-3.7-schedule-two-purchases.png` | |
| 3.8 | 3 | *Superseded by 7.3:* no manual Stock-In; stock posts when the driver completes the lift | ✅ DONE | flow 3.8/7.3 (2 stock receipts posted on Lifted); `C01LiftCompletionReceivableStockTest` | No "+ Add Stock-In" button. A fallback "Assign Tank" remains in the Purchase Edit modal for completed lifts that could not post (e.g. the tank is full). |
| 3.9 | 3 | Works the same in Chrome, Edge, Firefox, Safari, Opera | ⚠️ PARTIAL | `phase4/sweep-chrome.json`, `phase4/sweep-webkit.json`; `docs/FIREFOX_CHECKLIST.md` | Chrome and WebKit (Safari's engine) both show **0 problems across 128 renders** after Phase 4: no page overflow, overlaps, transparent sticky cells, clipped logos, Filter buttons, inline banners or JS errors. Edge and Opera use Chrome's engine. **Firefox:** Playwright's Firefox will not launch on this macOS 27 machine, so a manual checklist (`docs/FIREFOX_CHECKLIST.md`) covers login, record purchase, schedule a lift with two purchases, driver Lifted + receipt upload, sale + payment + Delivered, and the ledger hover. **Pending your Firefox run.** |
| 4.1 | 4 | *Superseded by 6.19:* welcome block removed | ✅ DONE | = 6.19 | |
| 4.2 | 4 | "+ Schedule Lift" doesn't overlap | ✅ DONE | `chrome-1366-dispatch_dispatch_fuel-lifting.png`; sweep: no overlaps | |
| 4.3 | 4 | = 1.14 | ✅ DONE | = 1.14 | |
| 4.4 | 4 | = 1.15 / 1.16 | ✅ DONE | = 1.15 / 1.16 | |
| 4.5 | 4 | Remove button and fields don't overlap in Schedule Lift | ✅ DONE | flow 4.5/5.6/6.18: 0 overlaps with two lines | |
| 4.6 | 4 | *Superseded by 6.13:* trucks by plate + availability, as a Fuel Lifting tab | ✅ DONE | = 6.13; `chrome-1366-admin_admin_fuel-lifting_trucks.png` | |
| 4.7 | 4 | *Superseded by 6.9 / 7.1:* receipt column | ✅ DONE | = 7.1 | |
| 4.8 | 4 | Depots table fits the width | ✅ DONE | depots screenshot; sweep gap ≤ 40 px | |
| 4.9 | 4 | Stock-Out: Cost/Unit; Total Cost = Qty × Cost; Profit = Paid − Cost | ✅ DONE | `InventoryOfficerStockOutTest::test_automatic_stock_out_uses_inventory_cost_and_actual_payments_for_profit`; `CompliancePhase1Test::test_stock_out_shows_margin_and_realized_profit_with_formula_tooltips`; `phase1/stock-out-1366.png` | 100,000 × 60 = 6,000,000; paid 6,500,000 → 500,000 ✓. Cost/Unit is the source tank's weighted-average cost (purchase cost for direct depot releases). **C2 (Phase 1):** two columns with formula tooltips: **Margin** = Total Price − Total Cost (expected profit), and **Realized Profit** = Total Paid − Total Cost (negative until payments cover the cost). Cancelled releases show "—" in both. Profit appears nowhere else (Reports and Dashboard do not show it). |
| 4.10 | 4 | Alert cards aligned, full width | ✅ DONE | `chrome-1366-admin_admin_alerts.png`; `FrontendBugFixesTest::test_alert_pages_use_full_width_responsive_shared_layout` | |
| 4.11 | 4 | No manual Order ID in Record Sales | ✅ DONE | flow 4.11 | |
| 4.12 | 4 | Record Sales has "amount paid" | ✅ DONE | flow 4.12/5.9; `flow-*-4.12-record-sale.png` | It cannot exceed the total (overpayment is rejected). |
| 4.13 | 4 | A wrong sale can be deleted, or cancelled/voided with a reason, keeping an audit trail | ✅ DONE | `CompliancePhase1Test::test_cancelling_a_sale_requires_a_reason_and_shows_refund_due_when_it_had_payments`; `phase1/4.13-cancel-modal-1366.png`, `phase1/4.13-refund-due-row.png` | **Phase 1:** Cancel opens a modal with a **required reason** dropdown (Wrong customer / Wrong quantity or price / Wrong fuel type / Duplicate entry / Customer cancelled the order / Other) and an optional note (required for Other). It is stored as "Reason — note" and shown in the sale View. A cancelled sale with payments shows **"Refund due: ₱X"**, and its customer shows **Refund Due**, instead of clear. Untouched drafts are still deleted with a plain confirm, since there is no record left to hold a reason. Browser: empty submit reopens the modal with the error; a valid reason voids the sale and its stock-out. |
| 4.14 | 4 | Delivery Status (Pending/Delivered); Stock-Out only once Delivered | ✅ DONE | `CompliancePhase1Test::test_stock_out_posts_only_on_delivery_for_garage_sales_and_on_lifted_for_direct_depot_sales`; `phase1/stock-out-*.png`, `phase1/sales-*.png` | **C1 approved as built.** A new sale posts no stock-out. **Delivered** posts it from garage stock. A direct-depot allocation posts when the driver marks the lift Lifted. No manual stock-out. Phase 1 added the help line "Stock-Out is posted automatically when a sale's Delivery Status becomes Delivered." on Receivables and Stock-Out (verified at 375/768/1366/1920). |
| 5.1 | 5 | No oversized welcome (driver) | ✅ DONE | = 6.19 | |
| 5.2 | 5 | Driver details card shows every field | ✅ DONE | `chrome-1366-driver_driver_fuel-lifting.png` | Full-width card with every profile field. |
| 5.3 | 5 | Right-side lift panel removed | ✅ DONE | same | Details are in View. |
| 5.4 | 5 | "Source" filter → "Depot" | ✅ DONE | `driver/fuel-lifting.blade.php:66` | |
| 5.5 | 5 | Extra date filter and Filter button removed | ✅ DONE | `dispatch/fuel-lifting.blade.php:55`; `Part6RevisionsTest::test_no_filter_submit_buttons_…` | One "Lift date" filter; filters auto-apply. |
| 5.6 | 5 | Schedule Lift modal big enough, no overlap | ✅ DONE | flow 4.5/5.6/6.18; `size="xl"` | |
| 5.7 | 5 | Purchase dropdown lists eligible purchases | ✅ DONE | flow 5.7 | |
| 5.8 | 5 | Customer Bank Name | ✅ DONE | `sales.blade.php:442, 483` | |
| 5.9 | 5 | Record Sales "Amount Payment" | ✅ DONE | flow 4.12/5.9 | |
| 5.10 | 5 | Payment History "+ Add New Payment" reveals the form | ✅ DONE | flow 5.10; `flow-*-5.10-payment-history.png` | |
| 5.11 | 5 | Payment History Back → sale View | ✅ DONE | flow 5.11 | |
| 5.12 | 5 | Depots: no big empty space | ✅ DONE | = 4.8 | |
| 5.13 | 5 | No "+ Depot" on Purchases | ✅ DONE | `inventory.blade.php:44-48` | |
| 5.14 | 5 | Date and Status filters work without errors | ✅ DONE | misc script: status=pending and date=2026-10-15 applied, 0 errors | |
| 5.15 | 5 | Inventory Status pills same size | ✅ DONE | `table.js`: every pill 172 px | Partly hidden under the sticky Actions column @1366 (see 1.10). |
| 5.16 | 5 | Stock-In: no pipeline or pending-receipts table/pill | ✅ DONE | `inventory.blade.php:86-116` | |
| 5.17 | 5 | Ledger details centered | ✅ DONE | `chrome-1366-inventory_inventory-officer_ledger.png`; `Part6RevisionsTest::test_ledger_filters_and_centered_layout_for_every_role` | |
| 5.18 | 5 | Alerts: less spacing; full width | ✅ DONE | = 4.10 | |
| 6.1 | 6 | Eye icon inside the password field, right side, all sizes | ✅ DONE | `1.1-login-*.png`; `Part6RevisionsTest::test_login_eye_toggle_is_a_real_button_inside_the_password_field` | |
| 6.2 | 6 | Eye icon clickable | ✅ DONE | auth script: password→text→password | |
| 6.3 | 6 | No Garage/Direct Allocation columns on Purchases | ✅ DONE | `inventory.blade.php:52` | |
| 6.4 | 6 | Inventory Status pills uniform | ✅ DONE | = 5.15 | |
| 6.5 | 6 | Filters / Edit-close never show other tabs' tables | ✅ DONE | misc script: only the active panel visible after filtering each tab and after Edit → close | |
| 6.6 | 6 | Status/Depot/Fuel Type are dropdowns | ✅ DONE | `inventory-officer/partials/filter-controls.blade.php` | |
| 6.7 | 6 | "+ Record Purchase" is white | ✅ DONE | `chrome-1366-admin_admin_inventory.png` | |
| 6.8 | 6 | No "+ Depot" on Purchases; "+ Record Purchase" wider | ✅ DONE | same | Full-width row. |
| 6.9 | 6 | Receipt column; "-" when none | ✅ DONE | flow 6.9 | |
| 6.10 | 6 | Stock-In: no pipeline section | ✅ DONE | = 5.16 | |
| 6.11 | 6 | Stock-Out: no "+ Record Stock-Out" | ✅ DONE | flow 6.11/7.2; `InventoryOfficerStockOutTest::test_manual_stock_out_entry_is_removed` | |
| 6.12 | 6 | Ledger center-aligned | ✅ DONE | = 5.17 | |
| 6.13 | 6 | Trucks: not in sidebar; tab in Fuel Lifting | ✅ DONE | `Part6RevisionsTest::test_trucks_is_a_fuel_lifting_tab_not_a_sidebar_link` | `/trucks` redirects to the tab. |
| 6.14 | 6 | "+ Add Truck" at the end of the bar, white | ✅ DONE | trucks screenshot | |
| 6.15 | 6 | No Filter button on Trucks | ✅ DONE | trucks screenshot | |
| 6.16 | 6 | Enter amount paid from the sale's View/Edit | ✅ DONE | flow 6.16 (View → Payment History → +5,000) | |
| 6.17 | 6 | Dispatch: no inline welcome | ✅ DONE | sweep `inlineWelcome: false` | |
| 6.18 | 6 | Dispatch Schedule Lift fields work (typeable, no overlap, no "â€”") | ✅ DONE | flow 4.5/5.6/6.18 (mojibake = false) | |
| 6.19 | 6 | Driver: no inline welcome | ✅ DONE | sweep `inlineWelcome: false` | |
| 6.A | 6 | Every filter is a dropdown; no Filter buttons anywhere | ✅ DONE | `CompliancePhase2Test::test_reports_period_filter_shows_only_the_needed_inputs_and_has_no_generate_button`; `Part6RevisionsTest::test_no_filter_submit_buttons_…` (now also rejects a bare "Generate"); `phase2/reports-range-*.png` | **Phase 2:** Reports uses a Period dropdown that shows only the inputs that period needs: date, start + end, month or year. Hidden inputs are disabled, so they leave the URL. The filter applies on change once those inputs are filled (Date Range waits for both dates). "Generate" is removed; Export and Print stay. AI "Generate Insight" buttons are actions, not filters, and stay. |
| 6.B | 6 | Red dots for unseen updates; none on first login | ✅ DONE | misc script: newly approved user → 0 dots; `Part6RevisionsTest::test_sidebar_red_dot_…` | It introduced a regression that breaks 4 tests; see "Test suite". |
| 7.1 | 7 | Receipt column shows only "View Receipt"; opens only the image | ✅ DONE | flow 7.1; `flow-*-7.1-receipt-viewer.png`; `Part7RevisionsTest::test_receipt_column_opens_an_image_only_lightbox_…` | Several receipts → "View Receipts (n)". |
| 7.2 | 7 | No Record Stock-Out button or modal | ✅ DONE | = 6.11 | |
| 7.3 | 7 | Garage (tank) and depot allocation per purchase line in Schedule Lift | ✅ DONE | flow 7.3; `Part7RevisionsTest::test_schedule_lift_saves_a_garage_and_depot_split_…` | Garage + Depot must equal the Amount to Lift; tank capacity is validated. |
| 7.4 | 7 | Truck AVAILABLE once the driver completes the lift | ✅ DONE | flow 7.4 (held while active → AVAILABLE after Lifted); `Part7RevisionsTest::test_truck_is_in_use_while_in_transit_…` | |

## Cross-browser (3.9)

| Engine | Covers | Business flow (35 checks) | Responsive sweep (144 renders) | Login / logout / eye |
|---|---|---|---|---|
| Google Chrome (Chromium) | Chrome, Edge, Opera | 33 ✓ / 2 ✗ | baseline | ✓ |
| WebKit 26 | Safari | 33 ✓ / 2 ✗ (same two) | **0 differences** vs Chrome | ✓ |
| Firefox (Playwright 1495) | Firefox | not run | not run | not run |

- The same two checks fail in both engines:
  - **2.1**, a real issue: duplicate inline flash + toast.
  - **4.9**, where my script was too strict. It expected Cost/Unit 60.00, but the tank's weighted-average cost was 59.79 / 59.84 because earlier stock was bought at 58. The math is correct.
- **Firefox:** the bundled build fails to start its `plugin-container` helper on this macOS 27 machine ("sandbox_extension_issue_file_to_process … Operation not permitted"). The same happens outside the tool sandbox and after re-extracting with `ditto`. This is an environment problem, not an app finding. Phase 4 includes running the same scripts on another machine or in CI.

**Phase 4 responsive fixes:**
- Grid tracks now shrink below their content: `.chart-panel` uses `min-width: 0`, and the Dashboard goes to one column at ≤1180px.
- The Dashboard breakdown lists stack when narrower than about 290px each.
- Driver profile fields wrap, and the driver card stacks at ≤1023px.
- Chart canvases render at their drawing size (no distortion).

**Responsive (Chrome = WebKit, audit-time figures with Phase 4 updates):**

| Width | Pages scrolling sideways | Notes |
|---|---|---|
| 375 | none | Sidebar becomes a drawer; tables scroll inside their frame with a "Scroll sideways" hint. |
| 768 | ~~Dashboard +497 px, Reports +87 px, Driver ×2 +26 px~~ **fixed in Phase 4:** none | Everything fits. |
| 1366 | ~~Dashboard +87 px~~ **fixed in Phase 4:** none | Purchases, Sales and Stock-Out fit (Phase 3). Other wide tables scroll inside their frame. |
| 1920 | none | Purchases still scrolls 346 px inside its frame; no pill overlap. |

## Test suite

| Run | Result |
|---|---|
| Audit baseline (`main` @ `80aef6f`) | 437 passed, **8 failed** |
| After Phase 1 | 447 passed, **4 failed** (only the open October 12 security items below) |
| After Phase 2 | 449 passed, **4 failed** (same four) |
| After Phase 3 | 453 passed, **4 failed** (same four) |
| After Phase 4 | 455 passed, **4 failed** (same four) |
| After security phase | **464 passed, 0 failed** (SQLite), and **464 passed, 0 failed** on MariaDB (`cjp_audit_testing`) |

**Fixed in Phase 1:**
- `AdminUserManagementTest` duplicate…, `FormValidationTest` ×2 and `RoleBasedAccessControlTest` registration… failed because of a regression from 9fa4d4b (red dots). `ModuleActivityService` now treats a missing or unknown role as "no modules", via `modulesFor()`. Regression test: `CompliancePhase1Test::test_red_dots_tolerate_users_without_a_known_role`.
- `Part6RevisionsTest` red-dot tests were clock-dependent. They travelled back to 09:00, so rows seeded by the migrations (stamped with the real time) looked newer than every visit, and the test failed whenever it ran after 9 AM. The tests now travel forward instead.


**Fixed in the security phase** (October 12 audit items, all previously failing):

| Item | Fix | Evidence |
|---|---|---|
| AUD-H01 login throttle bypass | X-Forwarded-For is trusted only from proxies in `TRUSTED_PROXIES` (empty = none), so a faked header no longer changes the client IP. The login limiter layers 5/min per account+IP, **10 per 15 min per account from any IP**, and 30/min per IP. | `Audit\H01LoginThrottleSpoofTest` ✓; `SecurityPhaseTest` (spoofed header ignored; per-account limit holds behind a trusted proxy) |
| AUD-C02 reset-code brute force | Same proxy fix and layered limiter (5/min per account+IP, 10 per 15 min per account, 20/min per IP). Each code allows **5 wrong tries**, then it is cancelled and a new code must be requested. | `Audit\C02PasswordResetBruteForceTest` ✓; `SecurityPhaseTest::test_a_reset_code_is_cancelled_after_five_wrong_tries` |
| Throttle message | A throttled login or reset re-renders its own form (HTTP 429) with "Too many attempts. Please try again in X minutes." and keeps the typed username or email. | `SecurityPhaseTest::test_throttled_login_explains_how_long_to_wait`; `phase5/login-throttled-*.png` (verified at 375/768/1366/1920) |
| AUD-C03 committed DB dump | `database/backups/pre_phase3_backup_20260916.sql` removed from the index (local copy kept). `.gitignore` now ignores `*.sql`, `*.sql.gz`, `*.dump`, `/database/backups/` and `/storage/backups/`. **History is not rewritten**; see the steps below. | `Audit\C03CommittedDatabaseDumpTest` ✓ |
| AUD-M13 readiness test used the real `.env` | `cjp:readiness-audit` accepts `--env-file=` (default: the project `.env`, so production behavior is unchanged). The test passes a production-shaped fixture, and a new test proves the audit still fails on a development env file. | `Phase3OperationalAcceptanceTest` ✓; `SecurityPhaseTest::test_readiness_audit_still_fails_on_a_development_env_file` |
| MariaDB-only test bugs (found while verifying) | `FollowupsTest` fixtures used codes longer than the 30-character column (SQLite ignores column lengths). `Phase5PerformanceTest` passed a purchase id where a purchase-item id is expected (the order-dependence noted under AUD-M13). Test-only fixes; they also failed on untouched `main`. | MariaDB run: 464/464 |

**Deployment note:** if the app runs behind a reverse proxy or load balancer, set `TRUSTED_PROXIES` to its address. Otherwise every user appears to come from the proxy's IP and shares the per-IP limits.

### C03: removing the dump from git history (not done; your decision)

The dump is in commit `7aeb007` (2026-09-17), which is on `origin/main`. It contains the **admin account row (`admin@gmail.com`) with its password hash**, sessions, customers, sales and payments. **Change that admin password now**, whatever you decide about history.

Rewriting history changes every commit hash from `7aeb007` on, and everyone must re-clone. Steps (git-filter-repo):

```bash
# 0. Make sure everyone has pushed their work, then stop pushing until step 6 is done.
brew install git-filter-repo

# 1. Work on a fresh mirror clone, never on your working copy.
git clone --mirror https://github.com/lowela-cmyk/cjp-inventory-sales-system.git cjp-mirror.git
cd cjp-mirror.git

# 2. Drop the file from every commit on every branch and tag.
git filter-repo --invert-paths --path database/backups/pre_phase3_backup_20260916.sql

# 3. Verify it is gone (both commands must print nothing).
git log --all --oneline -- database/backups/pre_phase3_backup_20260916.sql
git rev-list --all --objects | grep pre_phase3_backup

# 4. filter-repo removes the remote on purpose; add it back and force-push everything.
git remote add origin https://github.com/lowela-cmyk/cjp-inventory-sales-system.git
git push --force --mirror origin

# 5. Everyone deletes their old clone and clones again. Old local branches
#    (e.g. backup-main-before-rename) still contain the file and must not be pushed back.
# 6. Ask GitHub Support to purge cached views and pull-request refs of the old commits
#    (https://support.github.com > "Remove sensitive data").
```

## Other findings (outside the document)

- ~~In Dispatch / Fuel Lifting the "In Progress" count includes **Lifted** lifts.~~ **Fixed in Phase 1:** Lifted counts as Completed on the Dispatch and Driver cards (`CompliancePhase1Test::test_lifted_lifts_count_as_completed_in_the_dispatch_and_driver_cards`).
- ~~Login / Register: the "Log In" heading and the input text are indented further than the field labels.~~ **Fixed in Phase 3:** heading, labels and fields share one left edge, typed text is inset 24px, and the password field keeps 64px on the right for the eye icon. Verified at all four widths (`phase3/login-aligned-*.png`).

## Summary

| Status | Count | Items |
|---|---|---|
| ✅ DONE | 90 | everything not listed below |
| ⚠️ PARTIAL | 1 | 3.9 |
| ❌ NOT DONE | 0 |  |
| 🔀 CONFLICT | 0 |  |
| **Total** | **91** | |

Every feature the document asks for exists and works end to end. **Open:**
- 3.9: your manual Firefox run (`docs/FIREFOX_CHECKLIST.md`).
- C03: the optional history rewrite and the admin password change (see "Test suite").

## Phased fix plan (only ⚠️ / 🔀 items)

Each phase can ship and be tested on its own. Verification after every phase:
- `php artisan test` must not add new failures beyond the 4 known security-audit failures.
- Re-run the Playwright scripts (`flow.js`, `sweep.js`) on `cjp_audit_ui`.

### Phase 1: Business logic and data correctness

**Items:** C1 (2.3 / 4.14), 4.13, C2 (4.9 label), plus three extras: the red-dot test regression, frozen status text in alert bodies, and the "In Progress" count.

**Changes:**
- **C1:** no logic change. Add help text on Receivables and Stock-Out. Add one feature test for each path:
  - Pending sale → no stock-out; Delivered → stock-out at average cost.
  - Direct-depot allocation → Lifted → sale Delivered and depot release.
- **4.13:**
  - Cancel and Delete open a small modal with a **required Reason**. It is stored in `sales.cancellation_reason` (the column already exists) and shown in the sale View.
  - A cancelled sale with payments shows a computed **"Refund due PHP X"** pill instead of a silent `clear`.
- **C2:** rename to "Realized Profit" with a tooltip. Optionally add a "Margin" column (your choice).
- **Regression:** `ModuleActivityService::ROLE_MODULES[$user->role] ?? []` (lines 89, 107). This fixes 4 failing tests.
- **Alerts:** stop copying the purchase's status into the alert body, or render the current status.
- **Dispatch cards:** "In Progress" = `in_transit` only; Lifted counts as Completed.

**Likely files:**
- `SaleCorrectionService.php`, `SalesOfficerCustomerController.php` (`cancelSale`, row data)
- `sales-officer/sales.blade.php`, `inventory-officer/inventory.blade.php`
- `StockOutFinancialService.php` (only if Margin is chosen)
- `WorkflowAlertService.php`, `DispatchDeliveryController.php` (summary cards)
- `ModuleActivityService.php`, `status-badge.blade.php`
- Tests: `SaleCorrection`/`Sales` tests and `InventoryOfficerStockOutTest`.

**Migrations:** none. The refund-due state is computed, so no enum change is needed.
**Risk:** medium, because it touches the sale void path. Payments and stock reversal stay as they are.
**Effort:** about 1 day.

### Phase 2: Missing features

**Items:** 1.8, 6.A.

**Changes:**
- **1.8:** replace the dead-end "Add Customers" notice and the read-only Edit in Admin → User Management → Customers with the real customer form. It posts to the existing `admin.sales.customers.store` / `.update` routes, which are already validated. Show the real `customer_code` instead of `CSM-` + row id.
- **6.A:** Reports gets a Period dropdown that shows only the inputs that period needs (date / range / month / year) and applies on change, like the other filter bars. Remove "Generate"; Export and Print stay.

**Likely files:** `admin/user-management.blade.php`, `AdminUserManagementController.php`, `admin/reports.blade.php`, `resources/js/app.js`, `AdminUserManagementTest`, `Part6RevisionsTest` (extend the no-filter-button test to Reports).
**Migrations:** none. **Risk:** low. **Effort:** about 0.5 day.

### Phase 3: UI consistency

**Items:** 2.1, 3.3, 1.10, 1.9, plus the login heading indent.

**Changes:**
- **2.1:** one notification channel. Success and info go to the toast only. Remove the inline `.admin-flash-success` / `.alert-bar` blocks in 7 views. Validation errors stay inline next to their fields.
- **3.3:** add tones for `confirmed` (scheduled blue), `rejected` (danger), `maintenance` (partial/amber), `planned` (pending), and alert severities `info` / `warning`.
- **1.10:** fix the sticky Actions background. Remove or retarget `app.css:597-599` so the `.row-*` tint (or white) always paints the sticky cell. Add a left border so nothing shows underneath.
- **1.9:** fit the wide tables at 1366 px.
  - Purchases: merge "Cost / Liter" + "Total Cost", and Purchase + Payment status, into stacked cells, so the Inventory Status pill is no longer under Actions.
  - Sales and Dispatch: compact padding and wrap long depot names.
  - Make the sidebar run full height on long pages.
  - Driver filter bar: let Fuel Type sit in the same grid row.

**Likely files:** `resources/css/app.css`, `components/admin/status-badge.blade.php`, the 7 views with inline flashes, `inventory.blade.php` / `sales.blade.php` / `dispatch/fuel-lifting.blade.php` (column markup), `InventoryOfficerPurchaseController.php` (cell grouping), `auth/login.blade.php` and `auth/register.blade.php`.
**Migrations:** none.
**Risk:** low–medium. It is visual and touches many screens; the sweep catches regressions.
**Effort:** about 1–1.5 days.

### Phase 4: Cross-browser and responsive

**Items:** 3.9, plus the responsive overflow found in the sweep.

**Changes:**
- Dashboard overflow (+497 px @768, +87 px @1366): the Unlifted Fuel table and the bar rows need `min-width: 0` / wrapping.
- Reports +87 px @768 (chart cards).
- Driver +26 px @768 (filter row).
- Run `flow.js` + `sweep.js` in **Firefox** on another machine or in CI, and fix any differences.
- Optional: commit the Playwright scripts as `tests/e2e/` with a small GitHub Action. This adds `playwright` as a dev dependency, so it needs your OK.

**Likely files:** `resources/css/app.css`, `admin/dashboard.blade.php`, `admin/reports.blade.php`, `driver/fuel-lifting.blade.php`.
**Migrations:** none. **Risk:** low. **Effort:** about 0.5–1 day.

## Conflicts and proposed resolutions

### C1. When does a sale become a Stock-Out? (2.3 / 6.11 / 7.2 vs 4.14): RESOLVED

**Decision (2026-10-15):** approved as built. Help text added in Phase 1.


- **The requirements:** Parts 2, 6 and 7 say "stock-out happens when a sale is recorded". Part 4 #13 says "only once it is Delivered".
- **What the code does today:** it already follows Part 4 #13.
  - A new sale (Delivery Status *Pending*) creates no Stock-Out.
  - Setting **Delivered** posts the Stock-Out from garage stock at the tank's average cost and deducts inventory (`SaleConfirmationService::updateDeliveryStatus`).
  - A **direct-depot** allocation chosen in Schedule Lift marks its sale Delivered when the driver marks the lift Lifted, and that posts the depot release (`LiftAllocationService::settle`).
  - There is no manual Stock-Out button or modal anywhere.
- **Proposed resolution:** keep this behavior (it matches the suggested resolution). The Stock-Out tab stays sales-only and is filled automatically. Reasons:
  - A sale recorded by mistake can be voided before any fuel moves.
  - Inventory then matches the fuel that physically left.
- **Remaining work (Phase 1, small):**
  - Add one line of help text on the Receivables list and the Stock-Out tab: "Stock-Out is posted automatically when a sale's Delivery Status becomes Delivered."
  - Add a regression test that covers both paths end to end.
- **Needs your sign-off.**

### C2. Profit for unpaid / partly paid sales (4.9 clarification, not a contradiction): RESOLVED

**Decision (2026-10-15):** show both columns with formula tooltips. Done in Phase 1.


- Part 4 defines Profit = Total Paid − Total Cost, and that is what is implemented. As a result, an unpaid sale shows a **negative** profit (−cost) until it is paid.
- **Proposed resolution:** keep the formula, and add these to the Stock-Out tab:
  - Rename the column to **"Realized Profit"**, with a tooltip "Total Paid − Total Cost".
  - Add a **"Margin (Price − Cost)"** column, so the expected profit is visible before payment.
- **Needs your choice:** rename only, or rename plus the margin column.
