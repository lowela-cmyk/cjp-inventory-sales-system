---
type: "code"
date: "2026-09-24T00:56:45.994859+00:00"
question: "Which code and fixtures cause the remaining Dispatch depot and Sales approved-fuel test failures?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["DispatchDeliveryController", "ConnectedLiftingWorkflowTest", "InventoryLedgerTest", "Phase2GuardrailsAndIdempotencyTest", "SalesOfficerSalesManagementTest"]
---

# Q: Which code and fixtures cause the remaining Dispatch depot and Sales approved-fuel test failures?

## Answer

Dispatch failures traced to a regressed DispatchDeliveryController that omitted existing multi-item scheduling, persistent idempotency, workflow/truck synchronization, and depot_id/depot_address selection. Sales failures traced to randomized unapproved fuel codes in SalesOfficerSalesManagementTest. Restored established dispatch behavior, used approved DSL/UNL fixtures, and created a valid purchase-to-depot relationship for haul limit validation. Full suite passes.

## Outcome

- Signal: useful

## Source Nodes

- DispatchDeliveryController
- ConnectedLiftingWorkflowTest
- InventoryLedgerTest
- Phase2GuardrailsAndIdempotencyTest
- SalesOfficerSalesManagementTest