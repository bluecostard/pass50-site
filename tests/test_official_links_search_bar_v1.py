from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]
RUNTIME=(ROOT/'official-links-protection-v4.js').read_text(encoding='utf-8')

class OfficialLinksSearchBarTests(unittest.TestCase):
    def test_search_bar_is_persistent(self):
        self.assertIn('id="linksProfileSearch"', RUNTIME)
        self.assertIn('Rechercher un influenceur', RUNTIME)
        self.assertIn('data-link-profile', RUNTIME)
        self.assertIn('applyOfficialLinksSearch', RUNTIME)

    def test_search_expands_all_profiles(self):
        self.assertIn('ranking().map(p50v9LinkCard)', RUNTIME)
        self.assertIn('linksSearchCount', RUNTIME)

if __name__=='__main__':
    unittest.main()
