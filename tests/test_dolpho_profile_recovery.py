from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
ENDPOINT=(ROOT/'api/dolpho-profile-recovery-cron-v1.php').read_text(encoding='utf-8')
WORKFLOW=(ROOT/'.github/workflows/dolpho-profile-recovery.yml').read_text(encoding='utf-8')

def test_recovery_uses_all_durable_history_sources():
    assert 'p50_social_link_evidence' in ENDPOINT
    assert 'p50_social_link_audit' in ENDPOINT
    assert "(array)($profile['links']??[])" in ENDPOINT

def test_recovered_links_become_owner_protected():
    assert 'https://www.instagram.com/dolpho_dolpho225/' in ENDPOINT
    assert 'https://www.tiktok.com/@dolpho_dolpho1' in ENDPOINT
    assert 'https://www.facebook.com/profile.php?id=61559188443333' in ENDPOINT
    assert 'https://www.youtube.com/@dolphodolpho' in ENDPOINT
    assert 'manual_owner' in ENDPOINT
    assert 'persistedServerSide' in ENDPOINT
    assert 'PASS50-STATE-LINK-PROTECTION-V4.1' in ENDPOINT

def test_workflow_refuses_an_empty_recovery():
    assert '.restoredCount==4' in WORKFLOW
    assert '.publicStateWrites==1' in WORKFLOW
