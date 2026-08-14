from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]
RUNTIME=(ROOT/'official-links-protection-v4.js').read_text(encoding='utf-8')

class OfficialLinksSixFieldsTests(unittest.TestCase):
    def test_fixed_six_platforms_exist(self):
        self.assertIn("const OFFICIAL_LINK_FIELDS=['TikTok','Instagram','Facebook','YouTube','X','Snapchat'];",RUNTIME)
        self.assertIn('ensureSixOfficialFields',RUNTIME)
        self.assertIn("grid.replaceChildren(fragment)",RUNTIME)

    def test_zeinab_tiktok_is_registered(self):
        self.assertIn("TikTok:'https://www.tiktok.com/@cheffezeinabbance'",RUNTIME)

if __name__=='__main__':
    unittest.main()
