# CJP Selenium browser suite

This suite is intentionally conservative about the live site. `test_readonly.py` visits only the explicit route
manifest in `conftest.py`; it does not crawl links or click arbitrary controls. Login is required for role checks. Set
the five role username variables (`CJP_TEST_ADMIN_USERNAME`, `CJP_TEST_INVENTORY_OFFICER_USERNAME`,
`CJP_TEST_SALES_OFFICER_USERNAME`, `CJP_TEST_DISPATCH_OFFICER_USERNAME`, `CJP_TEST_DRIVER_USERNAME`) through the
secret manager. The shared password is read from `CJP_TEST_PASSWORD`, or prompted once with `getpass`.

`test_mutation.py` is fixture-gated and is automatically skipped when the target host is `cjpfuelvision.online`.
Before enabling it, point `--target` at a QA/staging copy using `--no-reload`, confirm that process uses the QA
database, load disposable fixtures, and set `CJP_ENABLE_MUTATIONS=1`. Never use the real `cjp_inventory_sales_db`.

Reports are written to `tests/selenium/reports/latest.json` and timestamped JSON files. Failure screenshots are written
to `tests/selenium/screenshots/` after DOM scrubbing of usernames and password fields. The test runner records PASS,
FAIL, and SKIP; a safety refusal is reported as BLOCKED in the test detail/reason.

## Windows PowerShell commands

From the repository root:

```powershell
py -3 -m venv .venv
.\.venv\Scripts\python.exe -m pip install --upgrade pip
.\.venv\Scripts\python.exe -m pip install -r tests\selenium\requirements.txt
# Prefer setting CJP_TEST_PASSWORD through your secret manager. For an interactive run, omit it and the suite will
# prompt once without echoing the password.
# Also provide the five CJP_TEST_*_USERNAME variables from the secret manager; their values are intentionally omitted.
.\.venv\Scripts\python.exe -m pytest tests\selenium -m readonly --target https://cjpfuelvision.online --password
```

For a disposable staging copy, first verify the server's explicit `DB_DATABASE` is a separately named QA database:

```powershell
$env:CJP_ENABLE_MUTATIONS = "1"
.\.venv\Scripts\python.exe -m pytest tests\selenium --suite mutation --target https://qa.example.test --password
```

Rerun one case:

```powershell
.\.venv\Scripts\python.exe -m pytest tests\selenium -m readonly -k "AUTH-004 and admin" --target https://cjpfuelvision.online --password
```

For CI, inject the plain value into `CJP_TEST_PASSWORD` from the CI secret store without printing it. No password is
stored in source, reports, commands, or screenshots.

