# Firefox Manual Checklist

A short pass of the main flows in **Firefox** (System Analysis item 3.9). Chrome and WebKit (Safari) are checked automatically with Playwright. Firefox could not run on the audit machine, so it is checked by hand.

## Before you start

- Use the latest Firefox, at window widths of about **1366px** and **768px** (Responsive Design Mode: Ctrl/Cmd+Shift+M).
- Use a test database (for example `cjp_audit_ui`), never live data.
- Have one approved account for each role: Admin, Inventory Officer, Dispatch Officer, Driver and Sales Officer.
- Have an image file (JPG or PNG) to use as a withdrawal receipt.
- Keep the browser console open (F12 → Console). Any red error counts as a failure.

Mark each step ✅ or ❌. For any ❌, write down the step, the window width and what you saw.

## 1. Log in and out

- [ ] The login page shows the full CJP logo (nothing cut off). The heading, labels and fields line up on one left edge.
- [ ] Click the eye icon in the password field: the password shows. Click again: it hides. The icon stays inside the field.
- [ ] Log in as **Inventory Officer**. A "Login success" toast appears at the bottom left, and there is no welcome banner on the page.
- [ ] Log out and confirm. A "Signed out" toast appears and closes with **x**.

## 2. Record a purchase (Inventory Officer)

- [ ] Inventory → Purchases → **+ Record Purchase**. The form has Fuel Type and Depot dropdowns, no receipt upload and no Status dropdown.
- [ ] Record **two purchases** with the same fuel and depot: e.g. F1, Batangas Energy Terminal, 20,000 L and 30,000 L at 60.00 per liter.
- [ ] Both appear in the table at 1366px **without scrolling sideways**. Each shows Purchased/Hauled/Received, Cost/Liter over Total Cost, and three status pills. Receipt shows "-".
- [ ] Alerts lists a "Created" alert for each purchase.

## 3. Schedule a lift with two purchases (Dispatch Officer)

- [ ] Fuel Lifting → **+ Schedule Lift**. Choose a truck, a driver and a lift date.
- [ ] In the first purchase line, pick the 20,000 L purchase and enter Amount 20,000, Garage Allocation 20,000, and a tank.
- [ ] Click **+ Add Purchase**. In the second line, pick the 30,000 L purchase and enter Amount 20,000, Garage 20,000, and the other tank.
- [ ] No fields or buttons overlap, every field accepts typing, and Pickup Depot shows the depot name and address (no free-text location).
- [ ] **Schedule Lift** saves. One lift lists both purchase IDs, and the truck shows as in use on the Trucks tab.

## 4. Driver: Lifted, then upload the withdrawal receipt (Driver)

- [ ] Fuel Lifting shows the lift with **Pickup Depot** and Depot Address (not a delivery destination).
- [ ] View → set status to **In Transit** → Update, then **Lifted** → Update.
- [ ] Upload a **withdrawal receipt** image with an optional note. The Receipt column shows "Uploaded".
- [ ] As Dispatch or Admin: the truck shows **Available** again on the Trucks tab.
- [ ] As Inventory Officer: the purchase's Receipt column shows **View Receipt**, which opens only the image. Stock-In shows both tank receipts.

## 5. Record a sale, add a payment, set Delivered (Sales Officer)

- [ ] Sales → **+ Record Sales**. There is no Order ID field. Enter 1,000 L of F1 at 65.00: Line Total and Total show **65,000.00** right away.
- [ ] Enter Amount Payment 30,000 and save. One toast appears and no banner. The row shows Total / Paid / Balance with **Balance 35,000.00 in red**.
- [ ] View → **Payment History** → **+ Add New Payment** shows the form. Add 5,000. **Back** returns to the sale's View.
- [ ] Set Delivery Status to **Delivered** and confirm.
- [ ] As Inventory Officer → Stock-Out: the sale appears once. Hovering the **Margin** and **Realized Profit** headers shows their formula tooltips. The table does not scroll sideways at 1366px.
- [ ] Cancel a different, part-paid sale: a reason is required. After cancelling, the row shows **Refund due: ₱…**.

## 6. Ledger: View Transactions hover (Inventory Officer or Admin)

- [ ] Ledger → Transaction → **View** on the 20,000 L purchase.
- [ ] The modal shows the Purchase ID, its status pill, and a bar with one segment per lift.
- [ ] **Hover** a segment: a tooltip shows its Lift-ID and Date Lifted. Tab to a segment with the keyboard: the same details appear.

## 7. Layout at 768px

- [ ] Repeat a quick visit to Dashboard, Reports, Inventory, Sales, Fuel Lifting and the Driver page at 768px. **The page itself never scrolls sideways.** Only wide tables scroll inside their frame, and their Actions column stays solid.
- [ ] Reports: choose **Date Range**. Only the start and end date fields appear, and the report updates once both are filled. There is no Generate button.

## Result

| Section | Pass / Fail | Notes |
|---|---|---|
| 1. Log in / out | | |
| 2. Record purchase | | |
| 3. Schedule lift (two purchases) | | |
| 4. Driver Lifted + receipt | | |
| 5. Sale + payment + Delivered | | |
| 6. Ledger hover | | |
| 7. 768px layout | | |
