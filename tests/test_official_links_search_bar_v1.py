from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]
RUNTIME=(ROOT/'official-links-protection-v4.js').read_text(encoding='utf-8')

class OfficialLinksSearchBarTests(unittest.TestCase):
    def test_search_bar_is_persistent(self):
        self.assertIn('id="linksProfileSearch"', RUNTIME)
        self.assertIn('Rechercher un influenceur', RUNTIME)
        self.assertIn('applyOfficialLinksSearch', RUNTIME)
        self.assertIn('ensureOfficialLinksSearch', RUNTIME)

    def test_search_expands_all_profiles(self):
        self.assertIn("allOfficialLinkProfiles", RUNTIME)
        self.assertIn("p50AllProfiles", RUNTIME)
        self.assertIn('linksSearchCount', RUNTIME)
        self.assertNotIn("all.map(p50v9LinkCard).join('')", RUNTIME)
        self.assertNotIn('ranking().slice(0,30).map(p50v9LinkCard)', RUNTIME)

if __name__=='__main__':
    unittest.main()
