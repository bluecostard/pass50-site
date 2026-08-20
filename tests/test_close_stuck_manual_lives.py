import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
APP_CONFIG = (ROOT / 'app-config.js').read_text(encoding='utf-8')
PROFILES = {
    'profile-lexes.js': 'live_lexes_',
    'profile-ange-morel.js': 'live_angemorel_',
    'profile-jp-nda.js': 'live_jpnda_',
}


class CloseStuckManualLivesTests(unittest.TestCase):
    def test_profile_scripts_close_instead_of_reinjecting(self):
        for name, prefix in PROFILES.items():
            text = (ROOT / name).read_text(encoding='utf-8')
            with self.subTest(profile=name):
                self.assertIn('closeStuckManualLive', text)
                self.assertNotIn('ensureManualLive', text)
                self.assertNotIn("source:'manual'", text)
                self.assertNotIn("endsAt:new Date(Date.now()+180*60000)", text)
                self.assertIn(f"id.startsWith('{prefix}')", text)

    def test_cache_bust_loads_close_scripts(self):
        self.assertIn("./profile-lexes.js?v=1.1", APP_CONFIG)
        self.assertIn("./profile-ange-morel.js?v=1.1", APP_CONFIG)
        self.assertIn("./profile-jp-nda.js?v=1.1", APP_CONFIG)
