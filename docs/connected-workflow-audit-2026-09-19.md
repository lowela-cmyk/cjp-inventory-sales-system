# Connected workflow audit and phased fix plan

Audit basis: the user's 14-point request, Laravel routes, controllers, services, Blade, CSS, JavaScript, migrations, existing feature tests, and the local MySQL database. The separate "System Analysis" document was not present in the supplied attachment or repository, so this checklist covers the pasted requirements. The table is the pre-fix assessment; results after changes follow below.

| Requirement | Current implementation status | Evidence found in code/database/UI | Result | Recommended simple fix |
| --- | --- | --- | --- | --- |
| 1. Automatic purchase status | Creation starts `ordered`/`pending`; hauling transitions use `PurchaseWorkflowService`. Edit does not resynchronize. | `PurchaseService::createPurchase`, `updatePurchase`, `PurchaseWorkflowService::synchronize`; status field remains accepted by request validation but ignored. | Partially Implemented | Synchronize on edits; retain harmless legacy input acceptance while ignoring its value. |
| 2. Purchase alerts | Purchase creation inserts an alert in the same transaction. Status transitions insert further alerts. | `PurchaseService::createPurchase`, `WorkflowAlertService::purchase`, `ConnectedLiftingWorkflowTest`. | Implemented | Verify through feature test and browser. |
| 3. Consistent status colors | Reusable badge exists but several workflow and inventory status values fall to neutral; some modal statuses are plain text. | `components/admin/status-badge.blade.php`, `PurchaseService::inventoryLinkStatus`, ledger modals. | Partially Implemented | Extend badge mapping and use badge in modal statuses. |
| 4. Driver pickup depot | Driver query uses joined depot name/address and ignores the legacy `source_location`. | `DriverDeliveryController::haulRows`, driver Blade, `ConnectedLiftingWorkflowTest`. | Implemented | Confirm rendered driver page. |
| 5. Repeat a partly scheduled purchase | Remaining quantity subtracts active assigned hauls; a second schedule is allowed at another time. | `DispatchDeliveryController::availablePurchaseItemOptions`, `remainingAssignablePurchaseItemQuantity`. | Partially Implemented | Add explicit partial then remainder regression test, including overage rejection. |
| 6. No schedule Location input | Form has a derived pickup depot display; backend writes `source_location = null`. | Dispatch schedule Blade and `DispatchDeliveryController::store`. | Implemented | Verify rendered form. |
| 7. Multiple purchases in one schedule | `items[]` form and backend support multiple same-depot purchase items with sum capacity validation. | Dispatch Blade, controller, `ConnectedLiftingWorkflowTest`. | Implemented | Run existing test and browser check. |
| 8. Automatic Stock-In after purchase | No duplicate manual Add Stock In button, but Stock-In tab shows only completed garage allocations. A newly purchased item is absent until lifting and allocation. Posting physical receipt still needs verified liters and tank. | `InventoryOfficerPurchaseController::garageAllocationOptions`, Stock-In Blade, `StockInService`. | Partially Implemented | Show new purchases as pending stock-in pipeline records immediately; keep actual tank inventory movement tied to verified receipt. |
| 9. Scrollable tables | Shared wrapper and nowrap rules exist; action cells are sticky. | `resources/css/app.css`, table Blade views. | Partially Implemented | Verify at laptop widths and adjust overflow or action reachability where needed. |
| 10. UI after git pull | Vite build output is ignored and README is generic Laravel text. | `.gitignore`, `package.json`, `README.md`. | Partially Implemented | Document deterministic install/build/migration/cache commands; verify production build. |
| 11. Laptop responsiveness | Dashboard collapses at 1180px; tables have horizontal wrappers. | `resources/css/app.css`. | Partially Implemented | Test requested viewport sizes and fix observed issues. |
| 12. Major browsers | Web standard CSS is mostly used; no cross-browser result established. | CSS/JS source review; browser verification pending. | Partially Implemented | Check Chrome, Edge, Firefox; record Safari-compatible CSS limitations. |
| 13. Revenue chart uses space | Revenue panel occupies one column of a two-column dashboard grid. | Dashboard Blade and `.dashboard-grid` CSS. | Partially Implemented | Span the panel across both columns if the adjacent column is unused or visually empty. |
| 14. Transaction View modal | Ledger shows lift blocks and hover metadata but no explicit remaining-liter block; totals count only `completed` hauls. | `InventoryLedgerService`, admin and inventory officer ledger Blade. | Partially Implemented | Show accurate remaining liters and count eligible lifted records consistently. |

## Phase 1: Critical data and workflow fixes

Keep receipt posting tied to actual garage delivery. Add purchase-backed pending Stock-In visibility and synchronize status after purchase edits. Run the existing partial/remainder scheduling and overage tests. Reuse existing depot, schedule, and alert paths.

## Phase 2: UI cleanup and consistency

Expand the shared status badge map, add the remaining quantity to transaction modals, and keep the derived depot display.

## Phase 3: Responsiveness and browser compatibility

Inspect the dashboard grid, tables, and action controls at the requested laptop sizes. Use responsive CSS for any confirmed layout issue. Record which browsers were actually exercised.

## Phase 4: Deployment consistency

Run the production Vite build and document dependency, migration, build, and cache-clear commands. Keep ignored generated assets out of Git and require a build on the client after pulling.

## Results after implementation

| Requirement | Result after changes | Evidence and remaining limit |
| --- | --- | --- |
| 1. Automatic purchase status | Implemented at code/test level | Purchase edits and verified garage receipts synchronize workflow status inside locked transactions. Cancellation and hauling transitions are covered by feature tests; UI does not offer manual status selection. |
| 2. Purchase alerts | Implemented at code/test level | Purchase creation test checks one alert and the Alerts route. The four local purchases are completed historical records, so none currently require an alert. |
| 3. Status colors | Improved; browser verification pending | Shared badge now covers incomplete, unpaid, garage receipt, direct, and pending inventory states. Visible status cells and modal headings in the audited modules use it. |
| 4. Driver depot source | Implemented at code/test level | Feature test confirms the official depot name/address and excludes submitted source text. |
| 5. Repeat scheduling | Implemented at code/test level | Feature tests schedule 6,000 L, reject 4,000.01 L, then schedule the exact 4,000 L remainder. |
| 6. Schedule Location field | Implemented at code/test level | Form has derived depot display; backend does not accept a Location input. |
| 7. Multiple purchase IDs | Implemented at code/test level | Connected workflow tests confirm two same-depot purchase items in one schedule; duplicate items, capacity overage, and mixed depots are rejected. |
| 8. Automatic Stock-In visibility | Implemented as a purchase pipeline; physical posting remains receipt driven | Newly created purchases appear in the Stock-In tab immediately. Test confirms no inventory movement is recorded before a verified garage receipt. Tank and actual received liters are selected at receipt. |
| 9. Responsive tables | Implemented in CSS; visual verification pending | Shared `.table-wrap` scrolls horizontally, `.admin-table` cells do not wrap, and action cells are sticky. |
| 10. UI after pull | Documented and build verified | `public/build` is ignored, so the client must run `npm ci` and `npm run build`; see `docs/client-deployment.md`. |
| 11. Laptop responsiveness | Static CSS addressed; visual verification pending | Dashboard grid collapses below 1180px and wide tables scroll. No viewport screenshots were obtained. |
| 12. Chrome/Edge/Firefox/Safari | Unverified | The computer-use runtime failed before opening a window. Chrome and Edge are installed; Firefox is absent. Safari is unavailable on this Windows host. |
| 13. Revenue chart width | Implemented in CSS; visual verification pending | Expected Revenue / Revenue vs Receivables panel now spans the dashboard grid, and Chart.js uses responsive canvas settings. |
| 14. View transaction modal | Implemented at code/test level | `lifted` and `completed` trips count toward lifted liters; the modal displays Lift ID/date blocks and explicit Remaining / Unlifted liters. |

### Phase completion

- **Phase 1:** Stock-In purchase pipeline, locked purchase edit/cancellation checks, status synchronization after edits and verified receipts, and focused scheduling, depot, alert, and driver tests verified.
- **Phase 2:** Shared badge tones and use across the audited views; ledger remaining quantity and lifted trip totals.
- **Phase 3:** Revenue panel spans available width; table scroll and responsive CSS reviewed. Browser and viewport verification remains open.
- **Phase 4:** Client deployment instructions added; production Vite build verified.

### Verification and limits

- `php artisan migrate:status`: all migrations ran.
- Local MySQL counts: 4 purchases, 4 purchase items, 4 hauls, 9 inventory movements, 0 alerts, 3 trucks, 1 driver profile. All four purchases have `completed` workflow status. This is existing local data, not a newly created live purchase.
- `php artisan test --compact`: 370 passed, 3,581 assertions.
- `npm run build`: passed; hashed CSS/JS generated in ignored `public/build`.
- `php artisan view:cache` and `git diff --check`: passed.
- Browser automation could not start its Node runtime (`The system cannot find the path specified`), so requested Chrome/Edge/Firefox and 1366x768 through 1920x1080 checks are not claimed as passed.
