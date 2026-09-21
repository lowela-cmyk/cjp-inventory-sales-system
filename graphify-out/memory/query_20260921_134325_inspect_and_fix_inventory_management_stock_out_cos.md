---
type: "query"
date: "2026-09-21T13:43:25.165060+00:00"
question: "Inspect and fix Inventory Management Stock-Out Cost/Unit, Total Cost, and paid-basis Profit calculations."
contributor: "graphify"
outcome: "useful"
source_nodes: ["InventoryOfficerStockOutTest", "InventoryOfficerPurchaseController", "AdminMonitoringController", "StockOutReleaseService", "DashboardSummaryService"]
---

# Q: Inspect and fix Inventory Management Stock-Out Cost/Unit, Total Cost, and paid-basis Profit calculations.

## Answer

Stock-Out reporting previously hardcoded Total Cost to zero and treated sale line total as Profit. Added a persisted stock_outs.unit_cost snapshot, weighted-average garage inventory costing, direct-depot purchase item costing, proportional allocation of actual sale payments across releases, shared server-side financial calculations for Admin and Inventory Officer, compact corrected columns, legacy backfill, and regression tests.

## Outcome

- Signal: useful

## Source Nodes

- InventoryOfficerStockOutTest
- InventoryOfficerPurchaseController
- AdminMonitoringController
- StockOutReleaseService
- DashboardSummaryService