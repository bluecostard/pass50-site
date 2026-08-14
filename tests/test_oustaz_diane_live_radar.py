import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
PROFILE=(ROOT/'profile-oustaz-diane.js').read_text(encoding='utf-8')
QUICK=(ROOT/'.github/workflows/live-radar-quick.yml').read_text(encoding='utf-8')

class OustazDianeLiveRadarTests(unittest.TestCase):
    def test_official_tiktok_is_exact_and_verified(self):
        self.assertIn("const TIKTOK_URL='https://www.tiktok.com/@oustazdianeofficiel1';",PROFILE)
        self.assertIn("TikTok:verifiedLink",PROFILE)
        self.assertIn("id:PROFILE_ID",PROFILE)

    def test_quick_radar_forces_targeted_probe(self):
        self.assertIn('Sonder prioritairement Oustaz Diané',QUICK)
        self.assertIn('mode=profile&profileId=oustaz-diane&force=1&batch=4',QUICK)
        self.assertIn('live TikTok publié=',QUICK)

if __name__=='__main__':
    unittest.main()
