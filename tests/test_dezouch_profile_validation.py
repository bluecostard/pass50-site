from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENDPOINT = (ROOT / "api" / "dezouch-profile-validation-cron-v1.php").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github" / "workflows" / "dezouch-profile-validation.yml").read_text(encoding="utf-8")


def test_exact_owner_confirmed_accounts_are_permanent():
    assert "https://www.instagram.com/dezouch_officiel/" in ENDPOINT
    assert "https://www.tiktok.com/@dezouch_officiel" in ENDPOINT
    assert "https://www.facebook.com/dezouch.officiel" in ENDPOINT
    assert "manual_owner" in ENDPOINT
    assert "persistedServerSide" in ENDPOINT
    assert "PASS50-STATE-LINK-PROTECTION-V4.1" in ENDPOINT


def test_youtube_is_not_invented_or_overwritten():
    assert "youtubePreservedEmpty" in ENDPOINT
    assert "'YouTube'=>" not in ENDPOINT


def test_workflow_requires_three_validations_and_one_public_write():
    assert "DEZOUCH-PROFILE-VALIDATION-V1.0" in WORKFLOW
    assert ".validatedCount==3" in WORKFLOW
    assert ".publicStateWrites==1" in WORKFLOW
