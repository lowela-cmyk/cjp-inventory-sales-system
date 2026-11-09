from __future__ import annotations

import csv
import io
import time
from pathlib import Path

import pytest
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait

from conftest import LANDING, READONLY_ROUTES, RESTRICTED_ROUTES, ROLE_USERNAME_ENV, clear_downloads, wait_for_page


@pytest.mark.readonly
@pytest.mark.case_id("AUTH-001")
def test_login_surface(driver, base_url):
    driver.get(f"{base_url}/login")
    assert driver.title == "Login | CJP Southern Star"
    assert driver.find_element(By.ID, "username").get_attribute("autocomplete") == "username"
    assert driver.find_element(By.ID, "password").get_attribute("type") == "password"
    assert driver.find_element(By.CSS_SELECTOR, "form.login-form button[type='submit']").is_enabled()


@pytest.mark.readonly
@pytest.mark.case_id("AUTH-002")
def test_required_login_fields(driver, base_url):
    driver.get(f"{base_url}/login")
    driver.find_element(By.CSS_SELECTOR, "form.login-form button[type='submit']").click()
    assert driver.find_element(By.ID, "username").get_attribute("validationMessage")


@pytest.mark.readonly
@pytest.mark.case_id("AUTH-003")
def test_invalid_login_is_rejected(driver, base_url):
    driver.get(f"{base_url}/login")
    driver.find_element(By.ID, "username").send_keys("not-a-real-account@example.invalid")
    driver.find_element(By.ID, "password").send_keys("intentionally-invalid")
    driver.find_element(By.CSS_SELECTOR, "form.login-form button[type='submit']").click()
    WebDriverWait(driver, 20).until(lambda d: "/login" in d.current_url)
    assert driver.find_element(By.CSS_SELECTOR, ".login-error").is_displayed()


@pytest.mark.readonly
@pytest.mark.case_id("AUTH-004")
@pytest.mark.parametrize("role", list(ROLE_USERNAME_ENV), ids=list(ROLE_USERNAME_ENV))
def test_role_landing_page(logged_in_driver, role, request):
    request.node.cjp_role = role
    assert logged_in_driver.current_url.rstrip("/").endswith(LANDING[role])


@pytest.mark.readonly
@pytest.mark.case_id("AUTH-005")
@pytest.mark.parametrize("role", list(ROLE_USERNAME_ENV), ids=list(ROLE_USERNAME_ENV))
def test_explicit_readonly_route_catalog(logged_in_driver, role, base_url, request):
    request.node.cjp_role = role
    for path in READONLY_ROUTES[role]:
        logged_in_driver.get(f"{base_url}{path}")
        wait_for_page(logged_in_driver)
        assert "/login" not in logged_in_driver.current_url, f"{role} lost auth at {path}"
        assert logged_in_driver.find_element(By.TAG_NAME, "body").is_displayed()


@pytest.mark.readonly
@pytest.mark.case_id("AUTH-006")
@pytest.mark.parametrize("role", [r for r in ROLE_USERNAME_ENV if r != "admin"], ids=[r for r in ROLE_USERNAME_ENV if r != "admin"])
def test_restricted_direct_routes_are_denied(logged_in_driver, role, base_url, request):
    request.node.cjp_role = role
    for path in RESTRICTED_ROUTES:
        logged_in_driver.get(f"{base_url}{path}")
        wait_for_page(logged_in_driver)
        assert "/login" not in logged_in_driver.current_url
        assert logged_in_driver.find_element(By.TAG_NAME, "body").text.strip()
        body = logged_in_driver.find_element(By.TAG_NAME, "body").text.lower()
        assert "403" in body or "forbidden" in body, f"Expected denial for {role} at {path}; observed {logged_in_driver.current_url}"


@pytest.mark.readonly
@pytest.mark.case_id("UI-001")
@pytest.mark.parametrize(("role", "path", "tabs"), [
    ("admin", "/admin/inventory", ["Purchases", "Stock In", "Stock Out", "Depots", "Garage"]),
    ("inventory_officer", "/inventory-officer/inventory", ["Purchases", "Stock In", "Stock Out", "Depots", "Garage"]),
    ("admin", "/admin/ledger", ["Ledger", "Transaction"]),
    ("inventory_officer", "/inventory-officer/ledger", ["Ledger", "Transaction"]),
    ("admin", "/admin/fuel-lifting", ["Schedule", "Hauled", "Trucks", "Garage"]),
    ("dispatch_officer", "/dispatch/fuel-lifting", ["Schedule", "Hauled", "Trucks", "Garage"]),
    ("admin", "/admin/sales", ["Receivables", "Customers"]),
    ("sales_officer", "/sales-officer/sales", ["Receivables", "Customers"]),
    ("driver", "/driver/fuel-lifting", ["Schedule", "Hauled"]),
], ids=lambda value: str(value))
def test_page_tabs_are_rendered(logged_in_driver, role, path, tabs, base_url, request):
    request.node.cjp_role = role
    logged_in_driver.get(f"{base_url}{path}")
    labels = {element.text.strip() for element in logged_in_driver.find_elements(By.CSS_SELECTOR, "[data-tab-target]")}
    assert set(tabs).issubset(labels)


@pytest.mark.readonly
@pytest.mark.case_id("SALES-001")
@pytest.mark.parametrize("role,path", [("admin", "/admin/sales"), ("sales_officer", "/sales-officer/sales")])
def test_receivables_has_required_seven_columns(logged_in_driver, role, path, base_url, request):
    request.node.cjp_role = role
    logged_in_driver.get(f"{base_url}{path}")
    headers = logged_in_driver.find_elements(By.CSS_SELECTOR, "[data-tab-panel='receivables'] thead th")
    assert len(headers) == 7, "Receivables must have exactly seven evenly spaced columns"
    text = " ".join(header.text for header in headers)
    assert "F1 %" not in text and "F1 (L)" not in text and "F1 Price" not in text


@pytest.mark.readonly
@pytest.mark.case_id("EXPORT-001")
@pytest.mark.parametrize("role,path", [
    ("admin", "/admin/inventory"), ("inventory_officer", "/inventory-officer/inventory"),
    ("admin", "/admin/ledger"), ("inventory_officer", "/inventory-officer/ledger"),
    ("admin", "/admin/fuel-lifting"), ("dispatch_officer", "/dispatch/fuel-lifting"),
    ("admin", "/admin/sales"), ("sales_officer", "/sales-officer/sales"), ("driver", "/driver/fuel-lifting"),
])
def test_export_control_is_explicit_and_present(logged_in_driver, role, path, base_url, request):
    request.node.cjp_role = role
    logged_in_driver.get(f"{base_url}{path}")
    controls = logged_in_driver.find_elements(By.CSS_SELECTOR, "[data-export-table], a.btn-export, [data-dashboard-export]")
    assert controls, f"No explicit export control on {path}"
    assert all(control.is_displayed() for control in controls)


@pytest.mark.readonly
@pytest.mark.case_id("UI-002")
@pytest.mark.parametrize("role,path", [("admin", "/admin/inventory"), ("sales_officer", "/sales-officer/sales"), ("driver", "/driver/fuel-lifting")])
def test_search_and_filter_controls_have_labels(logged_in_driver, role, path, base_url, request):
    request.node.cjp_role = role
    logged_in_driver.get(f"{base_url}{path}")
    for control in logged_in_driver.find_elements(By.CSS_SELECTOR, "input[name='search'], select, input[type='date']"):
        assert control.get_attribute("aria-label") or control.get_attribute("name") or control.get_attribute("id")

