from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENDPOINT = (ROOT / "api" / "dbz-profile-validation-cron-v1.php").read_text(encoding="utf-8")
WORKFLOW = (ROOT / ".github" / "workflows" / "dbz-profile-validation.yml").read_text(encoding="utf-8")


def test_exact_owner_confirmed_accounts_are_permanent():
    assert "https://www.instagram.com/dbz_2_/" in ENDPOINT
    assert "https://www.tiktok.com/@dbz.07" in ENDPOINT
    assert "https://www.facebook.com/profile.php?id=61575109614293" in ENDPOINT
    assert "manual_owner" in ENDPOINT
    assert "persistedServerSide" in ENDPOINT
    assert "PASS50-STATE-LINK-PROTECTION-V4.1" in ENDPOINT


def test_youtube_is_not_invented_or_overwritten():
    assert "youtubePreservedEmpty" in ENDPOINT
    assert "'YouTube'=>" not in ENDPOINT


def test_workflow_requires_three_validations_and_one_public_write():
    assert "DBZ-PROFILE-VALIDATION-V1.0" in WORKFLOW
    assert ".validatedCount==3" in WORKFLOW
    assert ".publicStateWrites==1" in WORKFLOW
