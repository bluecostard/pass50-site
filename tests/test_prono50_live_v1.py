# Validation de Prono50 live dans Pronostics.
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]
MODULE=(ROOT/'pronostics-coules-tab-v1.js').read_text(encoding='utf-8')
LIVE=(ROOT/'pronostics-live-tab-v1.js').read_text(encoding='utf-8')
NAV=(ROOT/'mobile-bottom-nav-v1.js').read_text(encoding='utf-8')
PRONO=(ROOT/'pronostics.html').read_text(encoding='utf-8')
INDEX=(ROOT/'index.html').read_text(encoding='utf-8')
CORE=(ROOT/'api'/'prono-core.php').read_text(encoding='utf-8')
API=(ROOT/'api'/'prono-live.php').read_text(encoding='utf-8')
SLIP=(ROOT/'api'/'prono-slip.php').read_text(encoding='utf-8')
FEED=(ROOT/'api'/'prono-feed.php').read_text(encoding='utf-8')
ADMIN=(ROOT/'admin-pronostics.html').read_text(encoding='utf-8')

class Prono50LiveV1Tests(unittest.TestCase):
    def test_public_tabs_include_live(self):
        self.assertIn('Pronostics</button>',MODULE)
        self.assertIn('Vote des Coulés</button>',MODULE)
        self.assertIn('Prono50 live</button>',MODULE)
        self.assertIn('data-prono-mode="live"',MODULE)
        self.assertIn("view','live'",MODULE)
        self.assertIn('grid-template-columns:1fr 1fr 1fr',MODULE)
        self.assertIn('p50-prono-live-view',MODULE)
        self.assertIn('#p50LivePronoPanel',MODULE)

    def test_live_play_rules(self):
        self.assertIn('/api/prono-live.php',LIVE)
        self.assertIn('/api/prono-slip.php',LIVE)
        self.assertIn('LIVE_MULT=2',LIVE)
        self.assertIn('gains ×2',LIVE)
        self.assertIn('data-live-qid',LIVE)

    def test_backend_doubles_payout_and_allows_repeats(self):
        self.assertIn("P50_PRONO_LIVE_SOURCE = 'prono50_live'",CORE)
        self.assertIn('P50_PRONO_LIVE_PAYOUT_MULTIPLIER = 2',CORE)
        self.assertIn('p50_prono_live_sessions',CORE)
        self.assertIn('uq_p50_prono_vote_entry',CORE)
        self.assertIn('live_session_id',CORE)
        self.assertIn('p50_prono_is_live_question',CORE)
        self.assertIn("action === 'activate'",API)
        self.assertIn("action === 'deactivate'",API)
        self.assertIn('round($combined * P50_PRONO_LIVE_PAYOUT_MULTIPLIER',SLIP)
        self.assertIn('$isCombo || $isLive',SLIP)
        self.assertIn("source_type<>?",FEED)

    def test_admin_manual_switch(self):
        self.assertIn('Prono50 live',ADMIN)
        self.assertIn('liveActivateBtn',ADMIN)
        self.assertIn('liveDeactivateBtn',ADMIN)
        self.assertIn("action:'activate'",ADMIN)
        self.assertIn("action:'saveQuestion'",ADMIN)

    def test_loaders_and_cache(self):
        self.assertIn('pronostics-coules-tab-v1.js?v=1.1',NAV)
        self.assertIn('pronostics-live-tab-v1.js?v=1.0',MODULE)
        self.assertIn('pronostics-live-tab-v1.js?v=1.0',PRONO)
        self.assertIn('pronostics-coules-tab-v1.js?v=1.1',PRONO)
        self.assertIn('pronostics-coules-tab-v1.js?v=1.1',INDEX)

if __name__=='__main__':
    unittest.main()
