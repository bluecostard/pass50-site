from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
LAYOUT = (ROOT / "live-modal-layout-v1.js").read_text(encoding="utf-8")
CONFIG = (ROOT / "app-config.js").read_text(encoding="utf-8")
ENGAGEMENT = (ROOT / "fi-engagement-v3.js").read_text(encoding="utf-8")
SW = (ROOT / "sw.js").read_text(encoding="utf-8")


class LiveModalMobileLayoutTests(unittest.TestCase):
    def test_layout_module_is_loaded_and_precached(self):
        self.assertIn("live-modal-layout-v1.js?v=1.0", CONFIG)
        self.assertIn("live-modal-layout-v1.js?v=1.0", SW)
        self.assertIn("pass50-v44-ranking-calibration", SW)

    def test_desktop_actions_have_explicit_grid_positions(self):
        self.assertIn("grid-template-columns:62px minmax(0,1fr) minmax(190px,auto)", LAYOUT)
        self.assertIn("#liveBody .live-card > .live-watch-link", LAYOUT)
        self.assertIn("#liveBody .live-card > .p50-share-live", LAYOUT)
        self.assertIn("grid-column:3", LAYOUT)

    def test_mobile_cards_keep_identity_and_actions_readable(self):
        self.assertIn("@media(max-width:680px)", LAYOUT)
        self.assertIn("grid-template-columns:64px minmax(0,1fr)", LAYOUT)
        self.assertIn("grid-column:1 / -1", LAYOUT)
        self.assertIn("width:100%", LAYOUT)
        self.assertIn("overflow-wrap:anywhere", LAYOUT)

    def test_existing_live_share_button_is_supported(self):
        self.assertIn("button.className='btn p50-share-live'", ENGAGEMENT)
        self.assertIn("card.querySelector('.live-watch-link')", ENGAGEMENT)
        self.assertIn(".p50-share-live", LAYOUT)


if __name__ == "__main__":
    unittest.main()
