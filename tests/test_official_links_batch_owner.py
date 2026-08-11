from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
E=(ROOT/'api/official-links-batch-owner-cron-v1.php').read_text(encoding='utf-8')
W=(ROOT/'.github/workflows/official-links-batch-owner.yml').read_text(encoding='utf-8')
def test_eight_supplied_links_are_exact():
    for value in ['instagram.com/gorsky/','tiktok.com/@gorsky','instagram.com/hassanhayek/','tiktok.com/@hassanhayek','instagram.com/holysheilla/','tiktok.com/@holysheilla','instagram.com/jonathanmorrison13/','facebook.com/Influenceurpositif']:
        assert value in E
def test_all_links_become_owner_protected():
    assert 'manual_owner' in E and 'owner_verified' in E
    assert 'persistedServerSide' in E and 'PASS50-STATE-LINK-PROTECTION-V4.1' in E
def test_coach_hamond_and_lady_sonia_links_are_exact():
    for value in ['tiktok.com/@coachhamond','instagram.com/ladysoniam/','tiktok.com/@ladysoniam','facebook.com/LadysoniaMabiala','youtube.com/@LadyMABIALA']:
        assert value in E
def test_workflow_requires_thirteen_and_one_write():
    assert '.validatedCount==13' in W and '.publicStateWrites==1' in W
