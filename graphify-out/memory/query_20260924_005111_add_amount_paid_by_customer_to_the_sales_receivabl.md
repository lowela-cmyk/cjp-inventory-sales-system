---
type: "query"
date: "2026-09-24T00:51:11.441884+00:00"
question: "Add Amount Paid by Customer to the Sales Receivables creation form while reusing payments and receivable reconciliation."
contributor: "graphify"
outcome: "useful"
source_nodes: ["SalesOfficerCustomerController", "SaleConfirmationService", "SalesOfficerPaymentRecordingTest", "modal.blade.php"]
---

# Q: Add Amount Paid by Customer to the Sales Receivables creation form while reusing payments and receivable reconciliation.

## Answer

Expanded from graph vocabulary: [sales, payment, payments, receivables, balance, amount, total, method, reconcile, status, stock, store]. The Sales create flow now validates amount_paid against the authoritative item total, records positive initial amounts through a shared recordPayment path also used by storePayment, and calls SaleConfirmationService reconcile for partial or full status updates. Zero amount creates no payment. The stock-out confirmation call remains in its original position and behavior. Both creation modals show Total, Amount Paid by Customer, and Remaining Balance with responsive live previews.

## Outcome

- Signal: useful

## Source Nodes

- SalesOfficerCustomerController
- SaleConfirmationService
- SalesOfficerPaymentRecordingTest
- modal.blade.php