from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
E=(ROOT/'api/ennemi-facebook-validation-cron-v1.php').read_text(encoding='utf-8')
W=(ROOT/'.github/workflows/ennemi-facebook-validation.yml').read_text(encoding='utf-8')
def test_exact_page_is_owner_protected():
    assert '61582125968813' in E
    assert 'manual_owner' in E and 'owner_verified' in E
    assert 'persistedServerSide' in E and 'PASS50-STATE-LINK-PROTECTION-V4.1' in E
def test_workflow_requires_public_write():
    assert '.status=="owner_verified"' in W
    assert '.publicStateWrites==1' in W
