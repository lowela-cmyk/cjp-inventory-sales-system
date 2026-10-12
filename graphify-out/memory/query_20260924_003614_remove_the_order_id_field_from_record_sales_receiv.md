---
type: "query"
date: "2026-09-24T00:36:14.885996+00:00"
question: "Remove the Order ID field from Record Sales Receivables while keeping automatic unique backend generation and Sales Order Number."
contributor: "graphify"
outcome: "useful"
source_nodes: ["SalesOfficerCustomerController", "modal.blade.php", "SalesOfficerSalesManagementTest"]
---

# Q: Remove the Order ID field from Record Sales Receivables while keeping automatic unique backend generation and Sales Order Number.

## Answer

Expanded from original query via graph vocab: [sales, sale, receivables, order, number, modal, create, store, validation, identifiers, ids]. SalesOfficerCustomerController now always generates sale_code with nextCode, retains retryOnCollision and the database unique constraint, and no longer validates or consumes client sale_code. The sales-officer and admin creation modals retain sales_order_number but omit the sale_code input. Focused feature tests verify unique generated IDs, forged input ignored, Sales Order Number persistence, create-form markup, and existing ID display.

## Outcome

- Signal: useful

## Source Nodes

- SalesOfficerCustomerController
- modal.blade.php
- SalesOfficerSalesManagementTest