---
type: "query"
date: "2026-09-24T01:23:49.468910+00:00"
question: "Update the CJP Sales workflow to add a Delivery Status and control when Stock-Out is recorded. A sale should only be recorded to Stock-Out once the delivery is marked Delivered."
contributor: "graphify"
outcome: "useful"
source_nodes: ["SaleConfirmationService", "StockOutReleaseService", "SalesOfficerCustomerController", "InventoryOfficerPurchaseController", "SaleCorrectionService", "AdminMonitoringController"]
---

# Q: Update the CJP Sales workflow to add a Delivery Status and control when Stock-Out is recorded. A sale should only be recorded to Stock-Out once the delivery is marked Delivered.

## Answer

Expanded from original query via graph vocab: [sale, sales, delivery, status, stock, out, release, confirm, inventory, payment, receivable, update]. SaleConfirmationService now separates financial confirmation from delivery transitions; Delivered atomically invokes StockOutReleaseService for garage or completed direct-depot allocations. Pending and Out for Delivery create no Stock-Out. StockOutReleaseService and InventoryOfficerPurchaseController enforce Delivered, reconcile existing releases, and prevent duplicate inventory deductions. SalesOfficerCustomerController exposes Admin and Sales Officer delivery routes; SaleCorrectionService maps audited cancellation to cancelled delivery status. Sales tables and modals show delivery status independently from payment status.

## Outcome

- Signal: useful

## Source Nodes

- SaleConfirmationService
- StockOutReleaseService
- SalesOfficerCustomerController
- InventoryOfficerPurchaseController
- SaleCorrectionService
- AdminMonitoringController