from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
SEARCH = (ROOT / 'official-links-search-v2.js').read_text(encoding='utf-8')

class OfficialLinksSearchV2SmokeTests(unittest.TestCase):
    def test_search_is_visible_and_reinjected(self):
        self.assertIn('RECHERCHER DANS LES LIENS OFFICIELS', SEARCH)
        self.assertIn('MutationObserver', SEARCH)
        self.assertIn('setInterval(ensureSearch,2500)', SEARCH)
        self.assertIn("e.target?.dataset?.adminTab==='links'", SEARCH)

if __name__ == '__main__':
    unittest.main()
