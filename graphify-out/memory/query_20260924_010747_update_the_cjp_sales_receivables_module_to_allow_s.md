---
type: "code"
date: "2026-09-24T01:07:47.579230+00:00"
question: "Update the CJP Sales/Receivables module to allow safe record correction when an order was entered incorrectly."
contributor: "graphify"
outcome: "useful"
source_nodes: ["SalesOfficerCustomerController", "SaleConfirmationService", "StockOutReleaseService", "SalesOfficerSalesManagementTest", "EnsureUserHasRole"]
---

# Q: Update the CJP Sales/Receivables module to allow safe record correction when an order was entered incorrectly.

## Answer

Expanded against graph nodes: SalesOfficerCustomerController, SaleConfirmationService, StockOutReleaseService, SalesOfficerPaymentRecordingTest, SalesOfficerReceivablesTrackingTest, SalesOfficerSalesManagementTest, EnsureUserHasRole. Added SaleCorrectionService: untouched drafts with no payments, schedules, stock-outs, haul allocations, fulfillment, or receivable activity are hard-deleted transactionally; all other sales are retained as cancelled with audit fields. Payments and inventory movements remain immutable; receivables are cleared, stock-outs and haul allocations are cancelled, fulfilled quantities reset, and existing cancellation-aware inventory queries reverse their effect. Admin and Sales Officer routes remain role-protected. UI provides View plus Delete/Cancel with shared confirmation modal. Full suite: 382 passed, 0 failed, 3840 assertions.

## Outcome

- Signal: useful

## Source Nodes

- SalesOfficerCustomerController
- SaleConfirmationService
- StockOutReleaseService
- SalesOfficerSalesManagementTest
- EnsureUserHasRole