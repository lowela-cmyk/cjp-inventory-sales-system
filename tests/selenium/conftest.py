"""Shared, production-safe Selenium fixtures for the CJP browser suite.

The suite deliberately avoids reading application data through a database connection.  Live checks
use only the explicit routes in routes.py and never discover controls by crawling links.
"""

from __future__ import annotations

import getpass
import json
import os
import re
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any
from urllib.parse import urlparse

import pytest
from selenium import webdriver
from selenium.common.exceptions import WebDriverException
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import WebDriverWait


ROOT = Path(__file__).resolve().parents[2]
REPORT_DIR = ROOT / "tests" / "selenium" / "reports"
SCREENSHOT_DIR = ROOT / "tests" / "selenium" / "screenshots"
DOWNLOAD_DIR = REPORT_DIR / "downloads"
LIVE_HOST = "cjpfuelvision.online"

ROLE_USERNAME_ENV = {
    "admin": "CJP_TEST_ADMIN_USERNAME",
    "inventory_officer": "CJP_TEST_INVENTORY_OFFICER_USERNAME",
    "sales_officer": "CJP_TEST_SALES_OFFICER_USERNAME",
    "dispatch_officer": "CJP_TEST_DISPATCH_OFFICER_USERNAME",
    "driver": "CJP_TEST_DRIVER_USERNAME",
}

LANDING = {
    "admin": "/admin/dashboard",
    "inventory_officer": "/inventory-officer/inventory",
    "sales_officer": "/sales-officer/sales",
    "dispatch_officer": "/dispatch/fuel-lifting",
    "driver": "/driver/fuel-lifting",
}

# This list is intentionally explicit. Do not replace it with menu/link discovery on production.
READONLY_ROUTES = {
    "admin": [
        "/admin/dashboard", "/admin/inventory", "/admin/inventory/stock-in", "/admin/inventory/stock-out",
        "/admin/inventory/depots", "/admin/inventory/garage", "/admin/ledger", "/admin/ledger/transactions",
        "/admin/fuel-lifting", "/admin/fuel-lifting/hauled", "/admin/fuel-lifting/trucks", "/admin/fuel-lifting/garage",
        "/admin/sales", "/admin/sales/customers", "/admin/alerts", "/admin/account-requests", "/admin/user-management",
    ],
    "inventory_officer": [
        "/inventory-officer/inventory", "/inventory-officer/inventory/stock-in", "/inventory-officer/inventory/stock-out",
        "/inventory-officer/inventory/depots", "/inventory-officer/inventory/garage", "/inventory-officer/ledger",
        "/inventory-officer/ledger/transactions", "/inventory-officer/alerts",
    ],
    "sales_officer": ["/sales-officer/sales", "/sales-officer/sales/customers", "/sales-officer/alerts"],
    "dispatch_officer": [
        "/dispatch/fuel-lifting", "/dispatch/fuel-lifting/hauled", "/dispatch/fuel-lifting/trucks",
        "/dispatch/fuel-lifting/garage", "/dispatch/ledger", "/dispatch/ledger/transactions", "/dispatch/alerts",
    ],
    "driver": ["/driver/fuel-lifting", "/driver/fuel-lifting/hauled", "/driver/fuel-lifting/no-schedule", "/driver/fuel-lifting/no-hauled"],
}

RESTRICTED_ROUTES = [
    "/admin/dashboard", "/admin/user-management", "/inventory-officer/inventory", "/sales-officer/sales",
    "/dispatch/fuel-lifting", "/driver/fuel-lifting",
]


@dataclass
class Result:
    test_id: str
    role: str
    outcome: str
    target: str
    observed_url: str = ""
    detail: str = ""
    screenshot: str = ""
    timestamp_utc: str = ""


def pytest_addoption(parser: pytest.Parser) -> None:
    group = parser.getgroup("cjp selenium")
    group.addoption("--target", default=os.getenv("CJP_BASE_URL", "https://cjpfuelvision.online"), help="Base URL")
    group.addoption("--suite", choices=("readonly", "mutation", "all"), default="readonly")
    group.addoption("--headed", action="store_true", help="Show Chrome")
    group.addoption("--password", action="store_true", help="Prompt for the shared password once")


def pytest_configure(config: pytest.Config) -> None:
    config._cjp_results = []  # type: ignore[attr-defined]
    target = config.getoption("--target").rstrip("/")
    config._cjp_target = target  # type: ignore[attr-defined]
    config._cjp_is_live = urlparse(target).hostname == LIVE_HOST  # type: ignore[attr-defined]
    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    SCREENSHOT_DIR.mkdir(parents=True, exist_ok=True)
    DOWNLOAD_DIR.mkdir(parents=True, exist_ok=True)


def pytest_collection_modifyitems(config: pytest.Config, items: list[pytest.Item]) -> None:
    suite = config.getoption("--suite")
    for item in items:
        is_mutation = item.get_closest_marker("mutation") is not None
        is_readonly = item.get_closest_marker("readonly") is not None
        if suite == "readonly" and is_mutation:
            item.add_marker(pytest.mark.skip(reason="mutation suite not selected"))
        elif suite == "mutation" and is_readonly:
            item.add_marker(pytest.mark.skip(reason="read-only suite not selected"))
        if is_mutation and config._cjp_is_live:  # type: ignore[attr-defined]
            item.add_marker(pytest.mark.skip(reason=f"BLOCKED: mutation tests are forbidden on {LIVE_HOST}"))


@pytest.hookimpl(hookwrapper=True)
def pytest_runtest_makereport(item: pytest.Item, call: pytest.CallInfo[Any]):
    outcome = yield
    report = outcome.get_result()
    if report.when not in ("call", "setup") or (report.when == "setup" and report.outcome != "skipped"):
        return
    result_outcome = {"passed": "PASS", "failed": "FAIL", "skipped": "SKIP"}[report.outcome]
    role = str(getattr(item, "cjp_role", "-"))
    test_id = str(item.get_closest_marker("case_id").args[0]) if item.get_closest_marker("case_id") else item.name
    driver = item.funcargs.get("driver") if hasattr(item, "funcargs") else None
    screenshot = ""
    observed_url = ""
    if driver is not None:
        observed_url = getattr(driver, "current_url", "")
    if result_outcome == "FAIL" and driver is not None:
        screenshot = str(_safe_screenshot(driver, test_id, role))
    item.config._cjp_results.append(Result(  # type: ignore[attr-defined]
        test_id=test_id,
        role=role,
        outcome=result_outcome,
        target=item.config._cjp_target,  # type: ignore[attr-defined]
        observed_url=observed_url,
        detail=str(report.longrepr) if report.longrepr else "",
        screenshot=screenshot,
        timestamp_utc=datetime.now(timezone.utc).isoformat(),
    ))


def pytest_sessionfinish(session: pytest.Session, exitstatus: int) -> None:
    path = REPORT_DIR / f"selenium-{datetime.now().strftime('%Y%m%d-%H%M%S')}.json"
    payload = {
        "target": session.config._cjp_target,  # type: ignore[attr-defined]
        "exit_status": exitstatus,
        "generated_at_utc": datetime.now(timezone.utc).isoformat(),
        "results": [asdict(result) for result in session.config._cjp_results],  # type: ignore[attr-defined]
    }
    path.write_text(json.dumps(payload, indent=2), encoding="utf-8")
    (REPORT_DIR / "latest.json").write_text(json.dumps(payload, indent=2), encoding="utf-8")


def username_for(role: str) -> str:
    username = os.getenv(ROLE_USERNAME_ENV[role])
    if not username:
        pytest.skip(f"BLOCKED: set {ROLE_USERNAME_ENV[role]} for the {role} account")
    return username


def _safe_screenshot(driver: webdriver.Chrome, test_id: str, role: str) -> Path | None:
    """Scrub usernames/passwords from the rendered DOM before writing a failure image."""
    try:
        driver.execute_script("""
            for (const el of document.querySelectorAll('input')) {
                if (el.type === 'password' || el.name === 'username' || el.autocomplete === 'username') el.value = '[REDACTED]';
            }
            const scrub = (node) => {
                if (node.nodeType === Node.TEXT_NODE) node.textContent = node.textContent.replace(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g, '[REDACTED]');
                else node.childNodes.forEach(scrub);
            };
            scrub(document.body);
        """)
        path = SCREENSHOT_DIR / f"{re.sub(r'[^A-Za-z0-9_.-]', '_', test_id)}-{role}.png"
        driver.save_screenshot(str(path))
        return path
    except WebDriverException:
        return None


@pytest.fixture(scope="session")
def base_url(pytestconfig: pytest.Config) -> str:
    return pytestconfig.getoption("--target").rstrip("/")


@pytest.fixture(scope="session")
def shared_password(pytestconfig: pytest.Config) -> str:
    password = os.getenv("CJP_TEST_PASSWORD")
    if password:
        return password
    if pytestconfig.getoption("--password") or not os.getenv("CI"):
        try:
            return getpass.getpass("CJP_TEST_PASSWORD (not echoed): ")
        except (EOFError, KeyboardInterrupt):
            pytest.skip("BLOCKED: CJP_TEST_PASSWORD was not supplied")
    pytest.skip("BLOCKED: set CJP_TEST_PASSWORD for non-interactive runs")


@pytest.fixture
def driver(request: pytest.FixtureRequest, pytestconfig: pytest.Config) -> webdriver.Chrome:
    options = Options()
    if not pytestconfig.getoption("--headed"):
        options.add_argument("--headless=new")
    options.add_argument("--window-size=1440,1000")
    options.add_argument("--disable-gpu")
    options.add_argument("--no-sandbox")
    options.add_experimental_option("prefs", {
        "download.default_directory": str(DOWNLOAD_DIR),
        "download.prompt_for_download": False,
        "download.directory_upgrade": True,
        "safebrowsing.enabled": True,
    })
    instance = webdriver.Chrome(options=options)  # Selenium Manager supplies the driver on Windows 10/11.
    instance.set_page_load_timeout(45)
    request.node.cjp_role = getattr(request, "param", "-")
    yield instance
    instance.quit()


@pytest.fixture
def logged_in_driver(request: pytest.FixtureRequest, driver: webdriver.Chrome, base_url: str, shared_password: str) -> webdriver.Chrome:
    case_params = getattr(getattr(request.node, "callspec", None), "params", {})
    role = case_params.get("role") or getattr(request, "param", None) or getattr(request.node, "cjp_role", None) or "admin"
    request.node.cjp_role = role
    driver.get(f"{base_url}/login")
    wait = WebDriverWait(driver, 20)
    wait.until(EC.visibility_of_element_located((By.ID, "username"))).send_keys(username_for(role))
    driver.find_element(By.ID, "password").send_keys(shared_password)
    driver.find_element(By.CSS_SELECTOR, "form.login-form button[type='submit']").click()
    wait.until(lambda d: "/login" not in d.current_url)
    return driver


def wait_for_page(driver: webdriver.Chrome) -> None:
    WebDriverWait(driver, 20).until(lambda d: d.execute_script("return document.readyState") == "complete")


def clear_downloads() -> None:
    for path in DOWNLOAD_DIR.glob("*"):
        if path.is_file():
            path.unlink()

