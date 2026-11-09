# CJP FuelVision Selenium test catalog

Inspection basis: `routes/web.php`, role middleware, `config/navigation.php`, all Blade views, JavaScript modules,
controllers/services, migrations, factories, and the existing Laravel feature/unit tests. The live URL is
`https://cjpfuelvision.online`; this environment could not resolve that host, so live browser observations are marked
`BLOCKED` until the Windows runner executes them. No claim below is a result from an unobserved live run.

## Role-permission matrix

| Role | Expected landing page | Implemented page access | Key allowed write areas |
|---|---|---|---|
| Admin | `/admin/dashboard` | Dashboard, Inventory, Ledger, Fuel Lifting, Sales, Alerts, User Management | All role workflows, reports/export, staff/drivers, approvals |
| Inventory Officer | `/inventory-officer/inventory` | Inventory, Ledger, Alerts | Purchases, stock-in/out maintenance, depots |
| Sales Officer | `/sales-officer/sales` | Sales/Customers, Alerts | Sales, source assignment, payments/refunds, customers |
| Dispatch Officer | `/dispatch/fuel-lifting` | Fuel Lifting, Ledger, Alerts | Scheduling, truck assignment, status, leftover/unload |
| Driver | `/driver/fuel-lifting` | Fuel Lifting schedule/hauled/no-schedule/no-hauled | Own lifting status and withdrawal receipt |

Admin is deliberately allowed through every role middleware branch by `EnsureUserHasRole`. Other roles are denied
with HTTP 403 on direct access to another role's namespace. `/login`, `/register`, `/forgot-password`, and
`/reset-password` are public; `/dashboard` and `/home` redirect to the role landing page.

## Catalog

Environment values: `Live-RO` = live URL, read-only browser check; `QA-Mutation` = disposable staging copy only;
`Backend` = Laravel feature/unit/API assertion; `Manual` = visual/accessibility observation. Automation status refers
to the repository deliverable, not an unrun claim.

| Test ID | Module | Role | Scenario | Preconditions | Test Data | Steps | Expected Result | Priority | Environment | Automation Status |
|---|---|---|---|---|---|---|---|---|---|---|
| AUTH-001 | Authentication | All | Login page contract | Signed out | None | Open `/login`; inspect title, username, password, submit | Correct title, labels, autocomplete, password masking, submit present | P0 | Live-RO | Implemented |
| AUTH-002 | Authentication | All | Required login fields | Signed out | Blank form | Submit explicit login form | Browser required-field validation prevents request | P0 | Live-RO | Implemented |
| AUTH-003 | Authentication | All | Invalid credentials | Signed out | Invalid email/password | Submit once | Remains on login and shows credential error | P0 | Live-RO | Implemented |
| AUTH-004 | Authentication | Each | Valid login and landing redirect | Approved active account; `CJP_TEST_PASSWORD` | Five supplied role emails | Login; record final URL | Redirects to exact role landing path | P0 | Live-RO | Implemented |
| AUTH-005 | Authentication | Each | Explicit role route smoke | Valid session | Route manifest in `tests/selenium/conftest.py` | Visit every allowed GET path | Auth remains, page completes, body is present | P0 | Live-RO | Implemented |
| AUTH-006 | Authorization | Non-admin | Direct restricted-page access | Valid session | Other-role GET paths | Visit each restricted path directly | HTTP 403/denial page, not another role's data | P0 | Live-RO | Implemented |
| AUTH-007 | Authorization | Admin | Admin has cross-role page access | Admin session | All role GET paths | Visit inventory, sales, lifting, driver receipt page as available | Allowed by middleware; no false 403 | P0 | Live-RO | Manual/fixture-dependent |
| AUTH-008 | Authentication | All | Logout and signed-out redirect | Valid session | None | Submit explicit logout; open `/dashboard` | Redirect to login; protected route requires auth | P0 | Live-RO | Manual (POST) |
| AUTH-009 | Authentication | All | Username vs email login | Signed out; account name known | Exact display name and email | Login once with each identifier | Both work unless duplicate-name rule applies | P1 | QA-Mutation | Backend/fixture |
| AUTH-010 | Authentication | All | Registration validation and admin exclusion | Signed out | Missing fields, admin role, weak password | Exercise public registration | Validation shown; admin request rejected; valid request pending | P1 | QA-Mutation | Backend |
| AUTH-011 | Authentication | All | Password reset code lifecycle | Mail catcher | Valid/unknown email, wrong code 1-5, expired code | Request/reset with controlled mail | Generic request response, five-attempt cancellation, expiry enforced | P1 | QA-Mutation | Backend |
| DASH-001 | Dashboard | Admin | Dashboard KPI/chart/empty states | Admin session | Existing dataset or empty QA fixture | Open dashboard | Cards/charts/empty state render without console-breaking errors | P0 | Live-RO | Manual/fixture-dependent |
| DASH-002 | Dashboard | Admin | Dashboard filters persist in export | Admin session | Date range, status/fuel filters | Apply filters; inspect export form fields | Export receives active filters | P1 | QA-Mutation | Manual |
| DASH-003 | Alerts | All | Role-specific alert visibility | Session per role | Seeded alert types | Open bell and Alerts page | Only visible role alerts appear; unread badge is accurate | P1 | Live-RO | Manual/fixture-dependent |
| DASH-004 | Alerts | All | Mark alert read/view | Unread alert in QA | Alert ID | Open/view and mark read | Target module opens; unread count changes once | P1 | QA-Mutation | Backend |
| USER-001 | User management | Admin | Staff/driver/account-request tabs | Admin session | Existing users | Open `/admin/user-management`; switch explicit tabs | Three panels and correct headings render | P0 | Live-RO | Manual |
| USER-002 | User management | Admin | Search/filter/pagination | Dataset > page size | Search term, status, role | Apply filters; traverse pages | Rows, counts, empty state and query parameters match | P1 | Live-RO | Manual/fixture-dependent |
| USER-003 | User management | Admin | CSV export permission and full dataset | Dataset > one page | Active tab/filter | GET explicit export link; inspect filename/header/rows | Admin only; filename is tab-specific; all filtered rows exported | P1 | QA-Mutation | Backend/manual |
| USER-004 | User management | Admin | Create/update/deactivate staff | Disposable QA | Valid and invalid staff fields | Submit each controlled form | Validation, role/status rules, persistence after reload | P1 | QA-Mutation | Backend |
| USER-005 | Account requests | Admin | Approve/reject pending request | Disposable pending user | Request ID | Update approval | Status changes, access follows status, audit/alerts correct | P1 | QA-Mutation | Backend |
| INV-001 | Inventory | Admin/Inventory Officer | Purchases tab/table | Readable purchase fixture | Search/date/status | Open page and inspect table/detail controls | Purchase IDs, fuel, liters, cost, payment fields are readable | P0 | Live-RO | Manual |
| INV-002 | Inventory | Admin/Inventory Officer | Purchase search/date/status filters | Dataset > page size | Term, date range, status | Apply each filter and clear | Results and URL query reflect filter; empty state is explicit | P1 | Live-RO | Manual/fixture-dependent |
| INV-003 | Inventory | Admin/Inventory Officer | Purchase details/payment mode | Purchase fixture | Cash/bank/cheque variants | Open detail/edit modal | Existing values, bank/reference rules and receipt display match | P1 | Live-RO | Manual |
| INV-004 | Inventory | Inventory Officer | Create/update/cancel purchase | Disposable QA | Approved fuel, depot, liters, unit cost, payment | Submit positive, missing, boundary and duplicate cases | Valid state persists; invalid/over-allocation is rejected; cancel is audited | P0 | QA-Mutation | Backend |
| INV-005 | Inventory | Admin/Inventory Officer | Stock In retired-route behavior | Valid session | `/inventory/stock-in` | Open route and inspect heading/tab | Route renders Stock In view/compatibility behavior without broken link | P1 | Live-RO | Implemented (route smoke) |
| INV-006 | Inventory | Admin/Inventory Officer | Stock In receipt/update | Stock-in fixture | Date, liters, ATL/DR | Open details and update in QA | Validation and purchase progress stay consistent | P1 | QA-Mutation | Backend |
| INV-007 | Inventory | Admin/Inventory Officer | Stock Out details | Stock-out fixture | Search/status/date | Open Stock Out and details | Sale/order, purchase, driver/truck, delivery fields and dates match source | P0 | Live-RO | Manual |
| INV-008 | Inventory | Admin/Inventory Officer | Stock Out edit boundary | QA direct-depot/garage fixtures | Zero/negative/excess liters | Submit edit | Invalid quantity blocked; valid update preserves reconciliation | P0 | QA-Mutation | Backend |
| INV-009 | Inventory | Admin/Inventory Officer | Depots tab | Depot fixture | Search/status | Open, search, paginate | Depot fields, capacity and empty state correct | P1 | Live-RO | Manual |
| INV-010 | Inventory | Admin/Inventory Officer | Depot create/update | Disposable QA | Unique code/name/capacity and duplicate | Submit forms | Valid record persists; duplicate/invalid capacity rejected | P1 | QA-Mutation | Backend |
| INV-011 | Inventory/Garage | Admin/Inventory Officer | Garage tanks/lots | Garage fixture | Search/fuel/status | Open Garage; inspect lots/details | Capacity, stock, avg cost, purchase IDs and newest lots render | P0 | Live-RO | Manual |
| INV-012 | Inventory/Garage | Admin/Inventory Officer | Garage empty states | Empty QA tank | None | Filter to empty tank | “This tank is empty”/no-lot state is readable and non-error | P1 | QA-Mutation | Manual |
| LED-001 | Ledger | Admin/Inventory Officer/Dispatch | Ledger tab | Inventory movements | Filters/date range | Open Ledger | Purchased/lifted/remaining liters and movement sources render | P0 | Live-RO | Manual |
| LED-002 | Ledger | Admin/Inventory Officer/Dispatch | Transaction detail | Multiple movement fixture | Two lifts with distinct truck/driver | Open transaction details | Each lift preserves truck and driver assignment; quantities reconcile | P0 | QA-Mutation | Backend/manual |
| LED-003 | Ledger | All allowed | Ledger filters/pagination/export | Dataset > page size | Search/date/fuel | Filter, paginate, export visible table | Active filter and all-record semantics are correct | P1 | Live-RO | Manual |
| LIFT-001 | Fuel Lifting | Admin/Dispatch | Schedule table and form | Purchase with fuel remaining | Purchase item, driver, truck, date | Open Schedule; inspect explicit add form | Available fuel and assignment fields are present | P0 | Live-RO | Manual |
| LIFT-002 | Fuel Lifting | Admin/Dispatch | Schedule assignment validation | Disposable QA | Valid/unknown truck/driver; excess liters | Submit schedule cases | Capacity, availability, stock and duplicate rules enforced | P0 | QA-Mutation | Backend |
| LIFT-003 | Fuel Lifting | Admin/Dispatch | Edit scheduled lift | Scheduled lift | Changed date/quantity/allocation | Open edit modal; save | Only Scheduled lift edits; values persist after reload | P1 | QA-Mutation | Backend |
| LIFT-004 | Fuel Lifting | Admin/Dispatch | Schedule to Hauled | Scheduled lift with no remaining truck fuel | Status transition | Update status; open Hauled | Lift moves from Schedule to Hauled with correct totals | P0 | QA-Mutation | Backend |
| LIFT-005 | Fuel Lifting | Admin/Driver | Partial load | Lift < ordered amount | Partial hauled quantity | Record/transition partial load | Remaining purchase quantity and truck load are accurate | P0 | QA-Mutation | Backend |
| LIFT-006 | Fuel Lifting | Admin/Dispatch/Driver | Completion blocked with fuel remaining | Truck has leftover | Complete status | Attempt completion | Server blocks completion and explains fuel-left condition | P0 | QA-Mutation | Backend |
| LIFT-007 | Fuel Lifting | Dispatch/Driver | Withdrawal receipt | Assigned lift; disposable image | JPEG/PNG/WebP and notes | Upload receipt; reload | Receipt status, thumbnail, notes and authorization are correct | P1 | QA-Mutation | Backend/manual |
| LIFT-008 | Fuel Lifting | Admin/Dispatch | Leftover delivery/unload | Truck leftover fixture | Delivery or unload-to-garage | Submit explicit action | Stock movement/garage lot and truck status reconcile; no duplicate movement | P0 | QA-Mutation | Backend |
| LIFT-009 | Fleet | Admin/Dispatch | Trucks tab/search/status | Truck fixture | Status/search | Open Trucks; filter and edit modal | Plate/name/capacity/status readable; automatic assignment status respected | P1 | Live-RO | Manual |
| LIFT-010 | Fleet | Admin/Dispatch | Truck create/update/toggle | Disposable QA | Valid/duplicate/zero capacity | Submit controlled forms | Validation and availability rules hold | P1 | QA-Mutation | Backend |
| SALE-001 | Sales | Admin/Sales Officer | Receivables table | Sales fixture | Search/status/date | Open Receivables | Seven evenly spaced columns; no `F1 %`, `F1 (L)`, or `F1 Price` | P0 | Live-RO | Implemented |
| SALE-002 | Sales | Admin/Sales Officer | Customer tab/search/pagination | Customer fixture | Search/status | Switch Customers; filter/paginate | Customer code, company, contact, balance and empty state render | P1 | Live-RO | Manual |
| SALE-003 | Sales | Admin/Sales Officer | Record sale validation | Disposable stock fixture | Standard, garage, direct-depot source | Open form; submit boundary/invalid cases | Required fields, stock and price rules reject bad requests | P0 | QA-Mutation | Backend |
| SALE-004 | Sales | Admin/Sales Officer | Garage source fulfillment | Garage lot fixture | F1/F2 quantities | Assign garage source; fulfill | Garage stock-out/lot draw is created and reconciles | P0 | QA-Mutation | Backend |
| SALE-005 | Sales | Admin/Sales Officer | Direct-depot release | Direct-depot lift fixture | Delivered sale | Fulfill direct-depot source | Quantities reconcile; absence of a garage storage movement is valid, not a defect | P0 | QA-Mutation | Backend |
| SALE-006 | Sales | Admin/Sales Officer | Full payment | Unpaid sale | Exact total | Add payment and reload | Paid status, balance zero, schedule status and history update | P0 | QA-Mutation | Backend |
| SALE-007 | Sales | Admin/Sales Officer | Partial payment/invalid amount | Unpaid sale | Partial, zero, over-balance, missing reference | Submit payment cases | Partial balance works; invalid/overpayment and required reference reject | P0 | QA-Mutation | Backend |
| SALE-008 | Sales | Admin/Sales Officer | Refund and refund edit | Paid/part-paid sale | Partial/full refund, invalid excess | Create/edit refund | Refund ceiling, status, history and balance are correct | P0 | QA-Mutation | Backend |
| SALE-009 | Sales | Admin/Sales Officer | Cancellation and change history | Sale with/without dependent activity | Cancel/edit delivered sale | Perform permitted and forbidden changes | Guardrails hold; change log records actor/old/new values | P0 | QA-Mutation | Backend |
| SALE-010 | Sales | Admin/Sales Officer | Delivery status | Standard sale | Pending/delivered/cancelled | Update status; reload | Allowed transitions and stock release behavior match code | P1 | QA-Mutation | Backend |
| SALE-011 | Sales | Admin/Sales Officer | Sales export | Dataset > page size; formula-like controlled text | `=1+1`, `+cmd`, `-x`, `@x` in safe QA fields | Export active filtered table; inspect CSV | Filename/headers correct; all filtered pages included; formula-like cells escaped | P0 | QA-Mutation | Backend/manual |
| EXP-001 | Exports | Admin | Dashboard PDF export | Admin session | Active dashboard filters | Submit explicit dashboard export form | PDF response has expected filename/content and selected filters | P1 | Live-RO | Manual |
| EXP-002 | Exports | Admin | User-management export permission | Non-admin and admin sessions | Office/drivers tabs | Visit explicit GET export URL | Admin succeeds; non-admin gets denial; no mutation | P1 | Live-RO | Manual |
| EXP-003 | Exports | All allowed | Client table export filename/header | Page with `data-export-table` | Existing rows | Click explicit export button; read downloaded CSV | Filename and flattened headers match rendered table; formula escaping holds | P1 | QA-Mutation | Manual |
| UI-001 | UI | All | Desktop layout | Logged-in session | 1440x1000 | Visit each allowed route | Sidebar/header/table/modal fit without overflow | P1 | Live-RO | Manual |
| UI-002 | UI | All | Narrow layout | Logged-in session | 360x800 and 768x1024 | Resize; inspect bottom nav/menu | Navigation remains usable, no clipped controls, readable badges | P1 | Live-RO | Manual |
| UI-003 | UI | All | Modal scroll/focus | Page with modal | Add/edit/payment modal | Open modal; Tab through; scroll body | Focus is visible/contained, modal scrolls, Escape/close works | P1 | Live-RO | Manual |
| UI-004 | UI | All | Dates, badges, empty/loading states | Dataset with varied dates/statuses | Historical and future dates | Inspect all tables/cards | Dates readable, badges spaced, empty/loading states non-jarring | P2 | Live-RO | Manual |
| UI-005 | UI | All | Persistence after reload | QA change completed | One non-destructive preference/filter or created QA record | Reload route | Intended state persists; stale state is not displayed | P1 | QA-Mutation | Manual |
| SEC-001 | Security | All | CSRF/session isolation | Browser session | Two isolated driver instances | Login in each; inspect cookies/session behavior | Sessions do not cross-contaminate; CSRF present on forms | P0 | QA | Backend/manual |
| SEC-002 | Security | All | Throttle and signed-out protection | Disposable QA | Repeated invalid login; protected URL | Exercise bounded attempts | Throttle/error is safe; no secret or stack trace exposed | P1 | QA-Mutation | Backend |
| DATA-001 | Reconciliation | Backend | Garage/direct-depot source rules | Controlled stock fixtures | Garage and direct-depot orders | Compare sale items, stock-outs, movements, lots | Both source paths reconcile; direct-depot omission is accepted | P0 | QA-Mutation | Backend |
| DATA-002 | Reconciliation | Backend | Purchase/lift/remaining ledger math | Multiple lifts/partial load | Two assignments and leftovers | Compare persisted quantities | Ordered = lifted + remaining + valid cancellation/leftover outcomes | P0 | QA-Mutation | Backend |

## Coverage notes and discrepancies to verify in a real browser run

- The implementation explicitly retires the old Stock-In workflow into the Purchases page while retaining compatibility
  routes; browser checks should confirm the visible heading/tab behavior.
- Reports were removed and redirect to the admin dashboard; verify that legacy links do not expose a broken page.
- The Receivables table template currently defines seven `th` elements and omits the three F1 columns; the browser
  assertion is included because CSS/layout and rendered conditionals still need observation.
- Export controls mix client-side table export and server-side links/PDF. The test suite checks only explicit controls;
  full-page export, formula escaping, and multi-page semantics require a fixture dataset and belong in QA/backend tests.
- Login updates `last_login_at`, so authentication setup is an intentional, bounded write. No business-data mutation is
  performed by the live suite.
- Live execution was not claimed here because DNS/network access from this workspace could not resolve the supplied
  host. The Windows commands and JSON report path are ready for the requested observed run.

