from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def test_fictive_ranking_is_admin_only_and_read_only():
    php = read("api/metrics-ranking-fictive.php")
    assert "require_role($user,'owner','admin')" in php
    assert "'publicPublication'=>false" in php
    assert "'publicStateWrites'=>0" in php
    assert "UPDATE app_state" not in php
    assert "INSERT INTO app_state" not in php


def test_fictive_page_is_explicitly_internal():
    html = read("classement-fictif.html")
    assert "CLASSEMENT FICTIF INTERNE" in html
    assert "noindex,nofollow" in html
    assert "pass50_api_token" in html


def test_operational_credentials_activate_from_server_secrets():
    php = read("api/metrics-social-collectors-core.php")
    assert "$configured=$secret!==''||(bool)$explicitEnabled" in php
    assert "PASS50_X_BEARER_TOKEN" in php
    assert "business_discovery" in php
    assert "PASS50_TIKTOK_RESEARCH_APPROVED" in php


def test_readiness_never_exposes_secrets():
    php = read("api/metrics-collector-readiness-core.php")
    assert "'secretsExposed'=>false" in php
    assert "'publicStateWrites'=>0" in php
    assert "secret'=>" not in php


def test_deployment_waits_for_new_baseline_contract():
    core = read("api/metrics-public-baseline-core.php")
    workflow = read(".github/workflows/metrics-public-baseline-p1.yml")
    assert "PUBLIC-BASELINE-P1-V1.2" in core
    assert workflow.count("PUBLIC-BASELINE-P1-V1.2") >= 3
    assert "api/metrics-social-collectors-core.php" in workflow
