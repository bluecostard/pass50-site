# Validation de l’intégration native des Coulés dans la page Pronostics.
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]
MODULE=(ROOT/'pronostics-coules-tab-v1.js').read_text(encoding='utf-8')
NAV=(ROOT/'mobile-bottom-nav-v1.js').read_text(encoding='utf-8')
PRONO=(ROOT/'pronostics.html').read_text(encoding='utf-8')
INDEX=(ROOT/'index.html').read_text(encoding='utf-8')

class PronosticsCoulesTabV1Tests(unittest.TestCase):
    def test_module_exposes_three_modes(self):
        self.assertIn('Pronostics</button>',MODULE)
        self.assertIn('Vote des Coulés</button>',MODULE)
        self.assertIn('Prono50 live</button>',MODULE)
        self.assertIn('data-prono-mode="coules"',MODULE)
        self.assertIn('data-prono-mode="live"',MODULE)
        self.assertIn("view','coules'",MODULE)

    def test_coules_reuses_existing_home_duel(self):
        self.assertIn('src="./?embed=coules#coules"',MODULE)
        self.assertIn("params.get('embed')==='coules'",MODULE)
        self.assertIn("document.getElementById('coules')",MODULE)
        self.assertNotIn("fetch('/api/coules",MODULE)

    def test_prono_view_hides_only_prono_blocks(self):
        self.assertIn('#scoreRow',MODULE)
        self.assertIn('#statusSection',MODULE)
        self.assertIn('#pubsSection',MODULE)
        self.assertIn('#slipBar',MODULE)
        self.assertIn('#p50CoulesPronoPanel',MODULE)
        self.assertIn('#p50LivePronoPanel',MODULE)

    def test_loader_and_cache_version_are_active(self):
        self.assertIn('function loadPronoCoulesTab()',NAV)
        self.assertIn('pronostics-coules-tab-v1.js?v=1.2',NAV)
        self.assertIn('loadPronoCoulesTab();',NAV)
        self.assertIn('PASS50-MOBILE-BOTTOM-NAV-V1.11',NAV)
        self.assertIn('mobile-bottom-nav-v1.js?v=1.11',PRONO)
        self.assertIn('<script src="./pronostics-coules-tab-v1.js?v=1.2" data-pass50-prono-coules-tab="1.2"></script>',INDEX)

if __name__=='__main__':
    unittest.main()
