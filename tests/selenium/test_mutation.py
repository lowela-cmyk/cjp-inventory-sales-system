from __future__ import annotations

import os

import pytest

from conftest import LIVE_HOST


@pytest.mark.mutation
@pytest.mark.case_id("SAFE-001")
def test_mutation_suite_requires_explicit_qa_target(pytestconfig):
    host = __import__("urllib.parse", fromlist=["urlparse"]).urlparse(pytestconfig.getoption("--target")).hostname
    assert host != LIVE_HOST, f"Mutation suite is forbidden on {LIVE_HOST}"
    assert os.getenv("CJP_ENABLE_MUTATIONS") == "1", "Set CJP_ENABLE_MUTATIONS=1 only for a disposable QA/staging fixture"


@pytest.mark.mutation
@pytest.mark.case_id("SAFE-002")
def test_staging_mutation_placeholder_is_fixture_gated(pytestconfig):
    """The actual purchase/sale/lift workflows belong here once a disposable fixture contract is supplied."""
    if os.getenv("CJP_ENABLE_MUTATIONS") != "1":
        pytest.skip("BLOCKED: staging mutation fixtures are not configured")
    pytest.skip("BLOCKED: define disposable fixture IDs before enabling data-changing browser cases")

