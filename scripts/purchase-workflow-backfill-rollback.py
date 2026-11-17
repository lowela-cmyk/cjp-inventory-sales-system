#!/usr/bin/env python3
"""Rollback SQL for one applied `inventory:sync-purchase-workflow --apply --report=FILE` run.

Usage: python3 -I scripts/purchase-workflow-backfill-rollback.py applied-report.json > rollback.sql
Review the SQL, back up the database, then run it only with explicit approval.

It restores each changed purchase's workflow_status and updated_at to the values recorded in the report and deletes
the status-history rows that run added (id > history_max_id_before). Rehearsed on a copy of the live database: after
the rollback (and the migration rollback) every table was byte-identical to the starting state.
"""
import collections
import json
import sys

report = json.load(open(sys.argv[1]))
if report.get("mode") != "applied":
    sys.exit("This report is from a dry run; there is nothing to roll back.")


def quote(value):
    return "NULL" if value is None else "'" + str(value).replace("'", "''") + "'"


groups = collections.defaultdict(list)
for change in report["changed"]:
    groups[(change["from"], change["from_updated_at"])].append(str(int(change["id"])))

print("START TRANSACTION;")
for (status, updated_at), ids in groups.items():
    print(f"UPDATE purchases SET workflow_status = {quote(status)}, updated_at = {quote(updated_at)} WHERE id IN ({','.join(ids)});")
print(f"DELETE FROM purchase_status_histories WHERE id > {int(report['history_max_id_before'])};")
print("COMMIT;")
