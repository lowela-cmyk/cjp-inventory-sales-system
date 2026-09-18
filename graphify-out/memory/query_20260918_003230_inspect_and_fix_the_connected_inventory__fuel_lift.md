---
type: "query"
date: "2026-09-18T00:32:30.732814+00:00"
question: "Inspect and fix the connected Inventory, Fuel Lifting, Ledger, Alerts, Dispatch Officer, and Driver workflow"
contributor: "graphify"
outcome: "useful"
source_nodes: ["InventoryOfficerPurchaseController", "DispatchDeliveryController", "DispatchLiftingStatusController", "DriverDeliveryController", "StockInService", "InventoryLedgerService"]
---

# Q: Inspect and fix the connected Inventory, Fuel Lifting, Ledger, Alerts, Dispatch Officer, and Driver workflow

## Answer

Expanded from graph vocabulary: purchase, alerts, lifting, schedule, dispatch, driver, depot, receipt, stock, inventory, ledger, status. Implemented an additive lifting_schedules parent around existing hauls, automatic purchase workflow status with history, deduplicated alerts and per-user read state, authoritative depot pickup data, multi-purchase same-depot scheduling with transactional capacity and concurrency validation, audited idempotent stock receipts, reusable accessible status badges, responsive UI controls, and regression tests. Full suite: 365 passed, 3537 assertions.

## Outcome

- Signal: useful

## Source Nodes

- InventoryOfficerPurchaseController
- DispatchDeliveryController
- DispatchLiftingStatusController
- DriverDeliveryController
- StockInService
- InventoryLedgerService