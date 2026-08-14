from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
APP = (ROOT / 'app-config.js').read_text(encoding='utf-8')
LOCK = (ROOT / 'observateur-official-links-lock-v1.js').read_text(encoding='utf-8')
API = (ROOT / 'api' / 'social-links.php').read_text(encoding='utf-8')
V9 = (ROOT / 'v9-tools.js').read_text(encoding='utf-8')


class ObservateurLockedLinksSearchTests(unittest.TestCase):
    def test_observateur_official_links_are_frozen(self):
        self.assertIn("PROFILE_ID='census-observateur-ebene'", LOCK)
        self.assertIn("YouTube:'https://www.youtube.com/@Observateur'", LOCK)
        self.assertIn("Facebook:'https://www.facebook.com/observateurofficiel/'", LOCK)
        self.assertIn("status:'owner_verified'", LOCK)
        self.assertIn("locked:true", LOCK)
        self.assertIn("input.readOnly=true", LOCK)
        self.assertIn("state.textContent='FIGÉ'", LOCK)

    def test_server_refuses_delete_or_reject_for_locked_links(self):
        self.assertIn("'census-observateur-ebene|youtube'=>'https://www.youtube.com/@Observateur'", API)
        self.assertIn("'census-observateur-ebene|facebook'=>'https://www.facebook.com/observateurofficiel/'", API)
        self.assertIn("in_array($action,['delete','reject'],true)", API)
        self.assertIn("'confirm_locked'", API)
        self.assertIn("'locked'=>$lockedOfficialUrl!==''", API)

    def test_patch_is_loaded_publicly(self):
        self.assertIn("observateur-official-links-lock-v1.js?v=1.0", APP)
        self.assertIn("data-pass50-observateur-link-locks", APP)

    def test_official_links_search_exists_and_is_extended(self):
        self.assertIn('id="linksProfileSearch"', V9)
        self.assertIn("e.target.id==='linksProfileSearch'", V9)
        self.assertIn("Rechercher par nom, pseudo, identifiant ou URL sociale", LOCK)
        self.assertIn("...Object.values(p?.links||{})", LOCK)


if __name__ == '__main__':
    unittest.main()
