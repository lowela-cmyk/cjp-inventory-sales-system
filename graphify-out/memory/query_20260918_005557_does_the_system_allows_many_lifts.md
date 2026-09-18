---
type: "query"
date: "2026-09-18T00:55:57.452654+00:00"
question: "does the system allows many lifts"
contributor: "graphify"
outcome: "useful"
source_nodes: ["DispatchDeliveryController", "InventoryLedgerService", "DispatchLiftingStatusManagementTest"]
---

# Q: does the system allows many lifts

## Answer

Expanded via graph vocab: [lifts, multiple, hauls, purchase, quantity, capacity, dispatch, lifted, unlifted]. Yes. A purchase item can have repeated partial lifts with no fixed lifetime lift count, until assigned or completed quantities reach its ordered quantity. Each schedule accepts 1 to 20 distinct purchase items, requires one depot, and the combined scheduled quantity must fit the selected truck capacity. The ledger modal renders every stored lift and wraps extra boxes inside the container. Cancelled lifts remain historical and do not count toward lifted totals.

## Outcome

- Signal: useful

## Source Nodes

- DispatchDeliveryController
- InventoryLedgerService
- DispatchLiftingStatusManagementTest