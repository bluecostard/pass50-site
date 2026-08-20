import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
PROFILE=(ROOT/'profile-oustaz-diane.js').read_text(encoding='utf-8')
SOURCE=(ROOT/'api'/'live-radar-v4-source.php').read_text(encoding='utf-8')
CORE=(ROOT/'tests'/'live_radar_v4_core.php').read_text(encoding='utf-8')
CONFIG=(ROOT/'app-config.js').read_text(encoding='utf-8')
QUICK=(ROOT/'.github/workflows/live-radar-quick.yml').read_text(encoding='utf-8')

class OustazDianeLiveRadarTests(unittest.TestCase):
    def test_official_tiktok_is_exact_and_verified(self):
        self.assertIn("const TIKTOK_URL='https://www.tiktok.com/@oustazdianeofficiel1';",PROFILE)
        self.assertIn("TikTok:verifiedLink",PROFILE)
        self.assertIn("id:PROFILE_ID",PROFILE)

    def test_official_youtube_replaces_ddr_collective(self):
        self.assertIn("const YOUTUBE_URL='https://www.youtube.com/@OustazDianeofficiel';",PROFILE)
        self.assertNotIn('UCkNi90ORn66edC-hB5sBbnQ',PROFILE)
        self.assertNotIn('Chaîne collective DDR La Vraie Chaîne',PROFILE)
        self.assertIn('@OustazDianeofficiel',PROFILE)
        self.assertIn("if(profile.links.YouTube!==YOUTUBE_URL)",PROFILE)
        self.assertNotIn('ensureManualLive',PROFILE)
        self.assertNotIn("source:'manual'",PROFILE)

    def test_radar_p0_youtube_forced_probe(self):
        self.assertIn("'oustaz-diane'", SOURCE.split('P50_LIVE_V4_P0_YOUTUBE', 1)[1].split('];', 1)[0])
        self.assertIn("'oustaz-diane|youtube'=>'https://www.youtube.com/@OustazDianeofficiel'", SOURCE)
        self.assertIn("'handle'=>'@OustazDianeofficiel'", SOURCE)
        self.assertIn("'profile_id'=>'oustaz-diane'", SOURCE)
        self.assertIn("'oustaz-diane'", CORE)

    def test_cache_bust_loader(self):
        self.assertIn('./profile-oustaz-diane.js?v=1.1', CONFIG)

    def test_quick_radar_forces_targeted_probe(self):
        self.assertIn('Sonder prioritairement Oustaz Diané',QUICK)
        self.assertIn('mode=profile&profileId=oustaz-diane&force=1&batch=4',QUICK)
        self.assertIn('live TikTok=',QUICK)
        self.assertIn('live YouTube=',QUICK)

if __name__=='__main__':
    unittest.main()
