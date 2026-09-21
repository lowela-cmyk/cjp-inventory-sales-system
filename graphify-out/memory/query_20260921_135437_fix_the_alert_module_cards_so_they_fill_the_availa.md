---
type: "query"
date: "2026-09-21T13:54:37.694874+00:00"
question: "Fix the Alert Module cards so they fill the available main content width and remain responsive."
contributor: "graphify"
outcome: "useful"
source_nodes: ["alert-list.blade.php", "admin/alerts.blade.php", "dispatch/alerts.blade.php", "FrontendBugFixesTest"]
---

# Q: Fix the Alert Module cards so they fill the available main content width and remain responsive.

## Answer

Removed the shared 1040px alert-list cap, added a full-width alerts-page wrapper for all four role views, kept actions in a stable right-side column, enabled clean message wrapping, and stacked actions at 900px and 520px breakpoints without changing alert business logic.

## Outcome

- Signal: useful

## Source Nodes

- alert-list.blade.php
- admin/alerts.blade.php
- dispatch/alerts.blade.php
- FrontendBugFixesTest